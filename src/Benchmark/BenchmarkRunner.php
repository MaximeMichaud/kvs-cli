<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

use PDO;

/**
 * Orchestrates all benchmark tests
 */
class BenchmarkRunner
{
    private ?PDO $db;
    private string $tablePrefix;
    private string $baseUrl;
    private int $httpSamples;
    private int $dbIterations;

    public function __construct(
        ?PDO $db,
        string $tablePrefix,
        string $baseUrl = '',
        int $httpSamples = 5,
        int $dbIterations = 10
    ) {
        $this->db = $db;
        $this->tablePrefix = $tablePrefix;
        $this->baseUrl = $baseUrl;
        $this->httpSamples = $httpSamples;
        $this->dbIterations = $dbIterations;
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
        $result->setSystemInfo($systemBench->getSystemInfo());

        // HTTP benchmarks (if URL provided)
        if ($this->baseUrl !== '') {
            if ($progressCallback !== null) {
                $progressCallback('http', 'Testing HTTP response times...');
            }

            $httpBench = new HttpBench($this->baseUrl, $this->httpSamples);

            if ($httpBench->isServerReachable()) {
                $httpBench->run($result);
            } elseif ($progressCallback !== null) {
                $progressCallback('http', 'Server not reachable, skipping HTTP tests');
            }
        }

        // Database benchmarks (if DB available)
        if ($this->db !== null) {
            if ($progressCallback !== null) {
                $progressCallback('db', 'Testing database performance...');
            }

            $dbBench = new DatabaseBench($this->db, $this->tablePrefix, $this->dbIterations);
            $dbBench->run($result);
        }

        // System metrics
        if ($progressCallback !== null) {
            $progressCallback('system', 'Collecting system metrics...');
        }

        $result->setSystemMetrics($systemBench->collect());
        $result->setTotalTime(microtime(true) - $startTime);

        return $result;
    }
}
