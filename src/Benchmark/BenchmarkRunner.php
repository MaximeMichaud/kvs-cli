<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

use PDO;

/**
 * Orchestrates all benchmark tests
 *
 * Runs comprehensive benchmarks for:
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
        int $fileIterations = 100
    ) {
        $this->db = $db;
        $this->tablePrefix = $tablePrefix;
        $this->baseUrl = $baseUrl;
        $this->httpSamples = $httpSamples;
        $this->dbIterations = $dbIterations;
        $this->memcachedConfig = $memcachedConfig;
        $this->cacheIterations = $cacheIterations;
        $this->fileIterations = $fileIterations;
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

        // Collect system info
        $systemBench = new SystemBench();
        $systemInfo = $systemBench->getSystemInfo();

        // Add database info to system info
        if ($this->db !== null) {
            $dbInfo = $systemBench->getDatabaseInfo($this->db);
            if ($dbInfo !== []) {
                $systemInfo = array_merge($systemInfo, $dbInfo);
            }
        }

        $result->setSystemInfo($systemInfo);

        // HTTP benchmarks (if URL provided)
        if ($this->baseUrl !== '') {
            $this->runProgress($progressCallback, 'http', 'Testing HTTP response times...');

            $httpBench = new HttpBench($this->baseUrl, $this->httpSamples);

            if ($httpBench->isServerReachable()) {
                $httpBench->run($result);
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

        // Cache benchmarks (Memcached)
        $this->runProgress($progressCallback, 'cache', 'Testing cache performance...');
        $cacheBench = new CacheBench($this->memcachedConfig, $this->cacheIterations);

        if ($cacheBench->connect()) {
            $cacheVersion = $cacheBench->getCacheVersion();
            $this->runProgress($progressCallback, 'cache', "Connected to Memcached {$cacheVersion}");
            $cacheBench->run($result);

            // Add cache info to system info
            $existingInfo = $result->getSystemInfo();
            $existingInfo['memcached_version'] = $cacheVersion;
            $result->setSystemInfo($existingInfo);

            $cacheBench->close();
        } else {
            $this->runProgress($progressCallback, 'cache', 'Memcached not available, skipping cache tests');
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
}
