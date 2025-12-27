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
        int $httpRuns = 3
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
    }

    /**
     * Run all benchmarks and return results
     *
     * @param callable|null $progressCallback Called with (string $stage, string $message)
     */
    public function run(?callable $progressCallback = null): BenchmarkResult
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

        $result->setSystemInfo($systemInfo);

        // CPU benchmarks (most important for PHP version comparison)
        $this->runProgress($progressCallback, 'cpu', 'Testing CPU performance...');
        $cpuBench = new CpuBench($this->cpuIterations);
        $cpuBench->run($result);

        // HTTP benchmarks (if URL provided)
        if ($this->baseUrl !== '') {
            $httpBench = new HttpBench($this->baseUrl, $this->httpSamples);

            if ($httpBench->isServerReachable()) {
                $this->runMultipleHttpBenchmarks($result, $httpBench, $progressCallback);
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
}
