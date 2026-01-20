<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

use PDO;

/**
 * Orchestrates all benchmark tests
 *
 * Runs comprehensive benchmarks for:
 * - CPU operations (hashing, serialization, string ops)
 * - HTTP response times
 * - Database queries (basic and heavy KVS queries)
 * - Memcached cache operations
 * - File I/O (serialize, reads, writes)
 * - System metrics
 *
 * Supports two modes:
 * - Local: Runs benchmarks in CLI context (default)
 * - Remote: Runs CPU/Cache/FileIO/DB benchmarks on KVS server via HTTP
 */
class BenchmarkRunner
{
    private ?PDO $db;
    private string $tablePrefix;
    private string $baseUrl;
    private int $httpSamples;
    private int $dbIterations;

    /** @var array{host?: string, port?: int} */
    private array $memcachedConfig;

    private int $cacheIterations;
    private int $fileIterations;
    private int $cpuIterations;
    private int $httpRuns;
    private bool $useLocalhost;

    // Remote execution support
    private bool $useRemote = false;
    private ?RemoteBenchmarkClient $remoteClient = null;

    /**
     * @param array{host?: string, port?: int} $memcachedConfig
     */
    public function __construct(
        ?PDO $db,
        string $tablePrefix,
        string $baseUrl = '',
        int $httpSamples = 5,
        int $dbIterations = 10,
        array $memcachedConfig = [],
        int $cacheIterations = 100,
        int $fileIterations = 100,
        int $cpuIterations = 1000,
        int $httpRuns = 3,
        bool $useLocalhost = false
    ) {
        $this->db = $db;
        $this->tablePrefix = $tablePrefix;
        $this->baseUrl = $baseUrl;
        $this->httpSamples = $httpSamples;
        $this->dbIterations = $dbIterations;
        $this->memcachedConfig = $memcachedConfig;
        $this->cacheIterations = $cacheIterations;
        $this->fileIterations = $fileIterations;
        $this->cpuIterations = $cpuIterations;
        $this->httpRuns = $httpRuns;
        $this->useLocalhost = $useLocalhost;
    }

    /**
     * Enable remote execution via the KVS server.
     *
     * When enabled, CPU, Cache, FileIO, and Database benchmarks run on the
     * server (PHP-FPM) instead of locally (CLI). HTTP benchmarks always run
     * locally as they measure response time from outside.
     */
    public function setRemoteExecution(RemoteBenchmarkClient $client): self
    {
        $this->useRemote = true;
        $this->remoteClient = $client;
        return $this;
    }

    /**
     * Check if remote execution is enabled.
     */
    public function isRemoteEnabled(): bool
    {
        return $this->useRemote && $this->remoteClient !== null;
    }

    /**
     * Run all benchmarks and return results
     *
     * @param callable|null $progressCallback Called with (string $stage, string $message)
     */
    public function run(?callable $progressCallback = null): BenchmarkResult
    {
        // Use remote execution if enabled
        if ($this->useRemote && $this->remoteClient !== null) {
            return $this->runRemote($progressCallback);
        }

        return $this->runLocal($progressCallback);
    }

    /**
     * Run benchmarks locally (CLI mode)
     *
     * @param callable|null $progressCallback Called with (string $stage, string $message)
     */
    private function runLocal(?callable $progressCallback = null): BenchmarkResult
    {
        $result = new BenchmarkResult();
        $startTime = microtime(true);

        // Collect system info (use HTTP to detect FPM config if URL available)
        $systemBench = new SystemBench();
        $systemInfo = $systemBench->getCombinedSystemInfo($this->baseUrl);

        // Add database info to system info
        if ($this->db !== null) {
            $dbInfo = $systemBench->getDatabaseInfo($this->db);
            if ($dbInfo !== []) {
                $systemInfo = array_merge($systemInfo, $dbInfo);
            }
        }

        $systemInfo['benchmark_mode'] = 'cli';
        $result->setSystemInfo($systemInfo);

        // CPU benchmarks (most important for PHP version comparison)
        $this->runProgress($progressCallback, 'cpu', 'Testing CPU performance (CLI)...');
        $cpuBench = new CpuBench($this->cpuIterations);
        $cpuBench->run($result);

        // HTTP benchmarks (if URL provided)
        if ($this->baseUrl !== '') {
            $httpBench = new HttpBench($this->baseUrl, $this->httpSamples, $this->useLocalhost);

            if ($httpBench->isServerReachable()) {
                $this->runMultipleHttpBenchmarks($result, $httpBench, $progressCallback);

                // Store localhost mode info
                if ($this->useLocalhost) {
                    $systemInfo = $result->getSystemInfo();
                    $systemInfo['http_localhost'] = true;
                    $systemInfo['http_host_header'] = $httpBench->getHostHeader();
                    $result->setSystemInfo($systemInfo);
                }
            } else {
                $this->runProgress($progressCallback, 'http', 'Server not reachable, skipping HTTP tests');
            }
        }

        // Database benchmarks (if DB available)
        $db = $this->db;
        if ($db !== null) {
            $this->runProgress($progressCallback, 'db', 'Testing database performance...');

            $dbBench = new DatabaseBench($db, $this->tablePrefix, $this->dbIterations);
            $dbBench->run($result);
        }

        // Cache benchmarks (Object Cache - Memcached/Dragonfly)
        $this->runProgress($progressCallback, 'cache', 'Testing cache performance...');
        $cacheBench = new CacheBench($this->memcachedConfig, $this->cacheIterations);

        if ($cacheBench->connect()) {
            $cacheVersion = $cacheBench->getCacheVersion();
            $backendType = $cacheBench->detectBackendType();

            $backendLabel = match ($backendType) {
                'dragonfly' => 'Dragonfly',
                'memcached' => 'Memcached',
                default => 'Object Cache',
            };

            $this->runProgress($progressCallback, 'cache', "Connected to {$backendLabel} {$cacheVersion}");
            $cacheBench->run($result);

            // Add cache info to system info
            $existingInfo = $result->getSystemInfo();
            $existingInfo['memcached_version'] = $cacheVersion;
            $existingInfo['cache_backend'] = $backendType;
            $result->setSystemInfo($existingInfo);

            $cacheBench->close();
        } else {
            $this->runProgress($progressCallback, 'cache', 'Object cache not available, skipping cache tests');
        }

        // File I/O benchmarks
        $this->runProgress($progressCallback, 'fileio', 'Testing file I/O performance...');
        $fileBench = new FileIOBench($this->fileIterations);
        $fileBench->run($result);

        // System metrics
        $this->runProgress($progressCallback, 'system', 'Collecting system metrics...');
        $result->setSystemMetrics($systemBench->collect());
        $result->setTotalTime(microtime(true) - $startTime);

        return $result;
    }

    /**
     * Run benchmarks remotely (PHP-FPM mode)
     *
     * CPU, Cache, FileIO, and Database benchmarks run on the server.
     * HTTP benchmarks and system detection still run locally.
     *
     * @param callable|null $progressCallback Called with (string $stage, string $message)
     */
    private function runRemote(?callable $progressCallback = null): BenchmarkResult
    {
        $result = new BenchmarkResult();
        $startTime = microtime(true);

        $remoteClient = $this->remoteClient;
        if ($remoteClient === null) {
            return $this->runLocal($progressCallback);
        }

        // Run remote benchmarks
        $this->runProgress($progressCallback, 'remote', 'Running benchmarks on KVS server (PHP-FPM)...');
        $remoteResult = $remoteClient->run();

        $success = $remoteResult['success'] ?? false;
        if ($success !== true) {
            $error = $remoteResult['error'] ?? 'Unknown error';
            $errorMsg = is_string($error) ? $error : 'Unknown error';
            $this->runProgress($progressCallback, 'remote', "Remote execution failed: {$errorMsg}");
            $this->runProgress($progressCallback, 'remote', 'Falling back to local execution...');
            return $this->runLocal($progressCallback);
        }

        // Build system info: local detection for OS/hostname, remote for PHP settings
        $systemBench = new SystemBench();
        $systemInfo = $systemBench->getSystemInfo(); // Get OS, hostname, extensions from CLI/host

        // Overlay PHP info from FPM server (this is what users actually experience)
        if (isset($remoteResult['php_info']) && is_array($remoteResult['php_info'])) {
            $phpInfo = $remoteResult['php_info'];
            $systemInfo['php_version'] = $phpInfo['version'] ?? PHP_VERSION;
            $systemInfo['php_sapi'] = $phpInfo['sapi'] ?? 'fpm';
            $systemInfo['opcache'] = $phpInfo['opcache_enabled'] ?? false;
            $systemInfo['jit'] = $phpInfo['jit_enabled'] ?? false;
            $systemInfo['memory_limit'] = $phpInfo['memory_limit'] ?? ini_get('memory_limit');
        }

        $systemInfo['benchmark_mode'] = 'fpm';
        $systemInfo['source'] = 'Server (PHP-FPM)';

        // Import CPU results
        $cpuResults = $remoteResult['cpu'] ?? null;
        if (is_array($cpuResults) && $cpuResults !== []) {
            /** @var array<string, mixed> $cpuResults */
            $this->importRemoteCpuResults($result, $cpuResults, $progressCallback);
        }

        // Import cache results
        $cacheResults = $remoteResult['cache'] ?? null;
        if (is_array($cacheResults)) {
            /** @var array<string, mixed> $cacheResults */
            $this->importRemoteCacheResults($result, $cacheResults, $systemInfo, $progressCallback);
        }

        // Import file I/O results
        $fileResults = $remoteResult['fileio'] ?? null;
        if (is_array($fileResults) && $fileResults !== []) {
            /** @var array<string, mixed> $fileResults */
            $this->importRemoteFileResults($result, $fileResults, $progressCallback);
        }

        // Import database results
        $dbResults = $remoteResult['database'] ?? null;
        if (is_array($dbResults)) {
            /** @var array<string, mixed> $dbResults */
            $this->importRemoteDatabaseResults($result, $dbResults, $systemInfo, $progressCallback);
        }

        $result->setSystemInfo($systemInfo);

        // HTTP benchmarks always run locally (measuring from outside)
        if ($this->baseUrl !== '') {
            $httpBench = new HttpBench($this->baseUrl, $this->httpSamples, $this->useLocalhost);

            if ($httpBench->isServerReachable()) {
                $this->runMultipleHttpBenchmarks($result, $httpBench, $progressCallback);

                if ($this->useLocalhost) {
                    $existingInfo = $result->getSystemInfo();
                    $existingInfo['http_localhost'] = true;
                    $existingInfo['http_host_header'] = $httpBench->getHostHeader();
                    $result->setSystemInfo($existingInfo);
                }
            } else {
                $this->runProgress($progressCallback, 'http', 'Server not reachable, skipping HTTP tests');
            }
        }

        // System metrics (local detection - hardware info)
        $this->runProgress($progressCallback, 'system', 'Collecting system metrics...');
        $systemBench = new SystemBench();
        $result->setSystemMetrics($systemBench->collect());

        // Add execution time from remote result
        if (isset($remoteResult['execution_time'])) {
            $existingInfo = $result->getSystemInfo();
            $existingInfo['remote_execution_time'] = $remoteResult['execution_time'];
            $result->setSystemInfo($existingInfo);
        }

        $result->setTotalTime(microtime(true) - $startTime);

        return $result;
    }

    /**
     * Helper to call progress callback if set
     */
    private function runProgress(?callable $callback, string $stage, string $message): void
    {
        if ($callback !== null) {
            $callback($stage, $message);
        }
    }

    /**
     * Run HTTP benchmarks multiple times and average the results
     *
     * This reduces variance in scores by averaging across multiple runs.
     */
    private function runMultipleHttpBenchmarks(
        BenchmarkResult $result,
        HttpBench $httpBench,
        ?callable $progressCallback
    ): void {
        $runs = $this->httpRuns;

        if ($runs === 1) {
            $this->runProgress($progressCallback, 'http', 'Testing HTTP response times...');
            $httpBench->run($result);
            return;
        }

        /** @var array<string, array<int, array{avg: float, min: float, max: float, p50: float, p95: float, p99: float, req_sec: float, samples: int}>> $allResults */
        $allResults = [];

        for ($run = 1; $run <= $runs; $run++) {
            $this->runProgress($progressCallback, 'http', "Testing HTTP response times (run {$run}/{$runs})...");

            // Create temporary result to collect this run's data
            $tempResult = new BenchmarkResult();
            $httpBench->run($tempResult);

            // Collect results by endpoint key
            foreach ($tempResult->getHttpResults() as $key => $data) {
                if (!isset($allResults[$key])) {
                    $allResults[$key] = [];
                }
                $allResults[$key][] = $data;
            }

            // Small pause between runs to let server settle
            if ($run < $runs) {
                usleep(500000); // 500ms
            }
        }

        // Average results across runs
        foreach ($allResults as $key => $runData) {
            $averaged = $this->averageHttpStats($runData);
            $name = $runData[0]['name'] ?? $key;
            $result->recordHttp($key, $name, $averaged);
        }

        // Store number of runs for display
        $systemInfo = $result->getSystemInfo();
        $systemInfo['http_runs'] = $runs;
        $result->setSystemInfo($systemInfo);
    }

    /**
     * Average HTTP stats across multiple runs
     *
     * @param array<int, array{
     *     avg: float, min: float, max: float, p50: float, p95: float, p99: float,
     *     req_sec: float, samples: int, name?: string
     * }> $runData
     * @return array{
     *     avg: float, min: float, max: float, p50: float, p95: float, p99: float,
     *     req_sec: float, samples: int
     * }
     */
    private function averageHttpStats(array $runData): array
    {
        $count = count($runData);
        if ($count === 0) {
            return [
                'avg' => 0.0, 'min' => 0.0, 'max' => 0.0,
                'p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0,
                'req_sec' => 0.0, 'samples' => 0,
            ];
        }

        $totalSamples = 0;
        $sumAvg = 0.0;
        $sumP50 = 0.0;
        $sumP95 = 0.0;
        $sumP99 = 0.0;
        $globalMin = PHP_FLOAT_MAX;
        $globalMax = 0.0;

        foreach ($runData as $data) {
            $sumAvg += $data['avg'];
            $sumP50 += $data['p50'];
            $sumP95 += $data['p95'];
            $sumP99 += $data['p99'];
            $globalMin = min($globalMin, $data['min']);
            $globalMax = max($globalMax, $data['max']);
            $totalSamples += $data['samples'];
        }

        $avgMs = $sumAvg / $count;
        $avgReqSec = $avgMs > 0 ? round(1000 / $avgMs, 2) : 0.0;

        return [
            'avg' => $avgMs,
            'min' => $globalMin === PHP_FLOAT_MAX ? 0.0 : $globalMin,
            'max' => $globalMax,
            'p50' => $sumP50 / $count,
            'p95' => $sumP95 / $count,
            'p99' => $sumP99 / $count,
            'req_sec' => $avgReqSec,
            'samples' => $totalSamples,
        ];
    }

    /**
     * Normalize remote CPU/general stats to match BenchmarkResult expectations.
     *
     * @param array<string, mixed> $data
     * @return array{avg: float, min: float, max: float, p50: float, p95: float, p99: float, std_dev: float, ops_sec: float, samples: int}
     */
    private function normalizeRemoteStats(array $data): array
    {
        return [
            'avg' => isset($data['avg']) && is_numeric($data['avg']) ? (float) $data['avg'] : 0.0,
            'min' => isset($data['min']) && is_numeric($data['min']) ? (float) $data['min'] : 0.0,
            'max' => isset($data['max']) && is_numeric($data['max']) ? (float) $data['max'] : 0.0,
            'p50' => isset($data['p50']) && is_numeric($data['p50']) ? (float) $data['p50'] : 0.0,
            'p95' => isset($data['p95']) && is_numeric($data['p95']) ? (float) $data['p95'] : 0.0,
            'p99' => isset($data['p99']) && is_numeric($data['p99']) ? (float) $data['p99'] : 0.0,
            'std_dev' => isset($data['std_dev']) && is_numeric($data['std_dev']) ? (float) $data['std_dev'] : 0.0,
            'ops_sec' => isset($data['ops_sec']) && is_numeric($data['ops_sec']) ? (float) $data['ops_sec'] : 0.0,
            'samples' => isset($data['samples']) && is_numeric($data['samples']) ? (int) $data['samples'] : 0,
        ];
    }

    /**
     * Normalize remote cache stats to match BenchmarkResult expectations.
     *
     * @param array<string, mixed> $data
     * @return array{avg: float, min: float, max: float, p50: float, p95: float, p99: float, ops_sec: float, samples: int}
     */
    private function normalizeCacheStats(array $data): array
    {
        return [
            'avg' => isset($data['avg']) && is_numeric($data['avg']) ? (float) $data['avg'] : 0.0,
            'min' => isset($data['min']) && is_numeric($data['min']) ? (float) $data['min'] : 0.0,
            'max' => isset($data['max']) && is_numeric($data['max']) ? (float) $data['max'] : 0.0,
            'p50' => isset($data['p50']) && is_numeric($data['p50']) ? (float) $data['p50'] : 0.0,
            'p95' => isset($data['p95']) && is_numeric($data['p95']) ? (float) $data['p95'] : 0.0,
            'p99' => isset($data['p99']) && is_numeric($data['p99']) ? (float) $data['p99'] : 0.0,
            'ops_sec' => isset($data['ops_sec']) && is_numeric($data['ops_sec']) ? (float) $data['ops_sec'] : 0.0,
            'samples' => isset($data['samples']) && is_numeric($data['samples']) ? (int) $data['samples'] : 0,
        ];
    }

    /**
     * Normalize remote file I/O stats to match BenchmarkResult expectations.
     *
     * @param array<string, mixed> $data
     * @return array{avg: float, min: float, max: float, ops_sec: float, samples: int}
     */
    private function normalizeFileStats(array $data): array
    {
        return [
            'avg' => isset($data['avg']) && is_numeric($data['avg']) ? (float) $data['avg'] : 0.0,
            'min' => isset($data['min']) && is_numeric($data['min']) ? (float) $data['min'] : 0.0,
            'max' => isset($data['max']) && is_numeric($data['max']) ? (float) $data['max'] : 0.0,
            'ops_sec' => isset($data['ops_sec']) && is_numeric($data['ops_sec']) ? (float) $data['ops_sec'] : 0.0,
            'samples' => isset($data['samples']) && is_numeric($data['samples']) ? (int) $data['samples'] : 0,
        ];
    }

    /**
     * Import CPU results from remote benchmark.
     *
     * @param array<string, mixed> $cpuResults
     */
    private function importRemoteCpuResults(
        BenchmarkResult $result,
        array $cpuResults,
        ?callable $progressCallback
    ): void {
        $this->runProgress($progressCallback, 'cpu', 'CPU benchmarks completed (server-side)');
        foreach ($cpuResults as $key => $data) {
            if (is_array($data)) {
                $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : $key;
                /** @var array<string, mixed> $data */
                $stats = $this->normalizeRemoteStats($data);
                $result->recordCpu($key, $name, $stats);
            }
        }
    }

    /**
     * Import cache results from remote benchmark.
     *
     * @param array<string, mixed> $cacheResults
     * @param array<string, mixed> $systemInfo Reference to system info array to update
     */
    private function importRemoteCacheResults(
        BenchmarkResult $result,
        array $cacheResults,
        array &$systemInfo,
        ?callable $progressCallback
    ): void {
        $connected = isset($cacheResults['connected']) && $cacheResults['connected'] === true;
        if (!$connected) {
            $this->runProgress($progressCallback, 'cache', 'Cache not available on server');
            return;
        }

        $type = isset($cacheResults['type']) && is_string($cacheResults['type']) ? $cacheResults['type'] : 'unknown';
        $version = isset($cacheResults['version']) && is_string($cacheResults['version']) ? $cacheResults['version'] : 'unknown';
        $systemInfo['cache_backend'] = $type;
        $systemInfo['memcached_version'] = $version;

        $label = $type === 'dragonfly' ? 'Dragonfly' : 'Memcached';
        $this->runProgress($progressCallback, 'cache', "Cache benchmarks completed ({$label} {$version}, server-side)");

        $cacheData = $cacheResults['results'] ?? null;
        if (!is_array($cacheData)) {
            return;
        }

        foreach ($cacheData as $key => $data) {
            if (is_array($data)) {
                $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : $key;
                /** @var array<string, mixed> $data */
                $stats = $this->normalizeCacheStats($data);
                $result->recordCache($key, $name, $stats);
            }
        }
    }

    /**
     * Import file I/O results from remote benchmark.
     *
     * @param array<string, mixed> $fileResults
     */
    private function importRemoteFileResults(
        BenchmarkResult $result,
        array $fileResults,
        ?callable $progressCallback
    ): void {
        $this->runProgress($progressCallback, 'fileio', 'File I/O benchmarks completed (server-side)');
        foreach ($fileResults as $key => $data) {
            if (is_array($data)) {
                $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : $key;
                /** @var array<string, mixed> $data */
                $stats = $this->normalizeFileStats($data);
                $result->recordFileIO($key, $name, $stats);
            }
        }
    }

    /**
     * Import database results from remote benchmark.
     *
     * @param array<string, mixed> $dbResults
     * @param array<string, mixed> $systemInfo Reference to system info array to update
     */
    private function importRemoteDatabaseResults(
        BenchmarkResult $result,
        array $dbResults,
        array &$systemInfo,
        ?callable $progressCallback
    ): void {
        $dbConnected = isset($dbResults['connected']) && $dbResults['connected'] === true;
        if (!$dbConnected) {
            $dbError = isset($dbResults['error']) && is_string($dbResults['error']) ? $dbResults['error'] : null;
            $message = $dbError !== null ? "Database connection failed: {$dbError}" : 'Database not available on server';
            $this->runProgress($progressCallback, 'db', $message);
            return;
        }

        $dbType = isset($dbResults['type']) && is_string($dbResults['type']) ? $dbResults['type'] : 'mysql';
        $dbVersion = isset($dbResults['version']) && is_string($dbResults['version']) ? $dbResults['version'] : 'unknown';
        $systemInfo['db_type'] = $dbType;
        $systemInfo['db_version'] = $dbVersion;

        $this->runProgress($progressCallback, 'db', "Database benchmarks completed ({$dbType} {$dbVersion}, server-side)");

        $dbData = $dbResults['results'] ?? null;
        if (!is_array($dbData)) {
            return;
        }

        foreach ($dbData as $key => $data) {
            if (!is_array($data)) {
                continue;
            }
            $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : (string) $key;
            $avgMs = isset($data['avg_ms']) && is_numeric($data['avg_ms']) ? (float) $data['avg_ms'] : 0.0;
            $queriesSec = isset($data['queries_sec']) && is_numeric($data['queries_sec']) ? (float) $data['queries_sec'] : 0.0;
            $samples = isset($data['samples']) && is_numeric($data['samples']) ? (int) $data['samples'] : 0;
            $result->recordDb((string) $key, $name, $avgMs, $queriesSec, $samples);
        }
    }
}
