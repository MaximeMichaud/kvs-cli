<?php

declare(strict_types=1);

namespace KVS\CLI\Tests\Fixtures;

use KVS\CLI\Benchmark\BenchmarkHelper;

/**
 * Concrete class to test the BenchmarkHelper trait
 */
class BenchmarkHelperTestable
{
    use BenchmarkHelper;

    /**
     * Expose protected methods for testing
     */
    public function publicStartTimer(): int
    {
        return $this->startTimer();
    }

    public function publicStopTimer(int $start): float
    {
        return $this->stopTimer($start);
    }

    public function publicWarmup(callable $operation): void
    {
        $this->warmup($operation);
    }

    /**
     * @return array<int, float>
     */
    public function publicRunBenchmark(callable $operation, int $iterations): array
    {
        return $this->runBenchmark($operation, $iterations);
    }

    /**
     * @param array<int, float> $timings
     * @return array{avg: float, min: float, max: float, p50: float, p95: float, p99: float, std_dev: float, ops_sec: float, samples: int}
     */
    public function publicCalculateStats(array $timings): array
    {
        return $this->calculateStats($timings);
    }

    /**
     * @param array<int, float> $sorted
     */
    public function publicPercentile(array $sorted, int $p): float
    {
        return $this->percentile($sorted, $p);
    }

    public function setWarmupIterations(int $iterations): void
    {
        $this->warmupIterations = $iterations;
    }
}
