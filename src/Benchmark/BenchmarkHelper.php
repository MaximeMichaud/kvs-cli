<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

/**
 * Helper trait for benchmark classes
 *
 * Provides:
 * - High-precision timing using hrtime(true) (nanoseconds)
 * - Statistical calculations (mean, min, max, percentiles, std dev)
 * - Warmup iterations to eliminate cold-start bias
 * - Memory tracking
 *
 * Best practices implemented:
 * - https://dev.to/blamsa0mine/php-84-performance-optimization-a-practical-repeatable-guide-1jp4
 * - https://inspector.dev/how-to-benchmark-php-code-performance/
 */
trait BenchmarkHelper
{
    /** @var int Number of warmup iterations (not measured) */
    protected int $warmupIterations = 3;

    /**
     * Start high-precision timer
     *
     * Uses hrtime(true) for nanosecond precision (PHP 7.3+)
     * Much more accurate than microtime() for micro-benchmarks
     */
    protected function startTimer(): int
    {
        return hrtime(true);
    }

    /**
     * Stop timer and return elapsed time in milliseconds
     */
    protected function stopTimer(int $start): float
    {
        $elapsed = hrtime(true) - $start;
        return $elapsed / 1_000_000; // nanoseconds to milliseconds
    }

    /**
     * Run warmup iterations to eliminate cold-start effects
     *
     * OPcache, JIT, CPU cache, and branch prediction all benefit from warmup
     */
    protected function warmup(callable $operation): void
    {
        for ($i = 0; $i < $this->warmupIterations; $i++) {
            $operation();
        }
    }

    /**
     * Run benchmark with warmup and return timings
     *
     * @return array<int, float> Array of timing values in milliseconds
     */
    protected function runBenchmark(callable $operation, int $iterations): array
    {
        // Warmup phase (not measured)
        $this->warmup($operation);

        // Actual measurement phase
        $timings = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = $this->startTimer();
            $operation();
            $timings[] = $this->stopTimer($start);
        }

        return $timings;
    }

    /**
     * Run benchmark with memory tracking
     *
     * @return array{timings: array<int, float>, memory_peak: int, memory_avg: int}
     */
    protected function runBenchmarkWithMemory(callable $operation, int $iterations): array
    {
        $this->warmup($operation);

        $timings = [];
        $memoryReadings = [];

        for ($i = 0; $i < $iterations; $i++) {
            $memBefore = memory_get_usage(true);
            $start = $this->startTimer();
            $operation();
            $timings[] = $this->stopTimer($start);
            $memoryReadings[] = memory_get_usage(true) - $memBefore;
        }

        $memoryPeak = $memoryReadings !== [] ? max($memoryReadings) : 0;
        $memoryAvg = $memoryReadings !== [] ? (int) (array_sum($memoryReadings) / count($memoryReadings)) : 0;

        return [
            'timings' => $timings,
            'memory_peak' => $memoryPeak,
            'memory_avg' => $memoryAvg,
        ];
    }

    /**
     * Calculate comprehensive statistics from timing data
     *
     * @param array<int, float> $timings Array of timing values in milliseconds
     * @return array{
     *     avg: float,
     *     min: float,
     *     max: float,
     *     p50: float,
     *     p95: float,
     *     p99: float,
     *     std_dev: float,
     *     ops_sec: float,
     *     samples: int
     * }
     */
    protected function calculateStats(array $timings): array
    {
        if ($timings === []) {
            return [
                'avg' => 0.0,
                'min' => 0.0,
                'max' => 0.0,
                'p50' => 0.0,
                'p95' => 0.0,
                'p99' => 0.0,
                'std_dev' => 0.0,
                'ops_sec' => 0.0,
                'samples' => 0,
            ];
        }

        sort($timings);
        $count = count($timings);
        $sum = array_sum($timings);
        $avg = $sum / $count;

        // Calculate standard deviation
        $squaredDiffs = array_map(fn($t) => ($t - $avg) ** 2, $timings);
        $variance = array_sum($squaredDiffs) / $count;
        $stdDev = sqrt($variance);

        return [
            'avg' => round($avg, 4),
            'min' => round($timings[0], 4),
            'max' => round($timings[$count - 1], 4),
            'p50' => round($this->percentile($timings, 50), 4),
            'p95' => round($this->percentile($timings, 95), 4),
            'p99' => round($this->percentile($timings, 99), 4),
            'std_dev' => round($stdDev, 4),
            'ops_sec' => $avg > 0 ? round(1000 / $avg, 2) : 0,
            'samples' => $count,
        ];
    }

    /**
     * Calculate percentile value from sorted array
     *
     * @param array<int, float> $sorted Pre-sorted array of values
     */
    protected function percentile(array $sorted, int $p): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }

        $index = (int) ceil(($p / 100) * $count) - 1;
        $index = max(0, min($count - 1, $index));

        return $sorted[$index];
    }

    /**
     * Format bytes to human-readable string
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    /**
     * Get current memory usage stats
     *
     * @return array{current: int, peak: int, current_real: int, peak_real: int}
     */
    protected function getMemoryStats(): array
    {
        return [
            'current' => memory_get_usage(false),
            'peak' => memory_get_peak_usage(false),
            'current_real' => memory_get_usage(true),
            'peak_real' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Force garbage collection and reset memory peak
     */
    protected function resetMemory(): void
    {
        gc_collect_cycles();
        gc_mem_caches();
    }
}
