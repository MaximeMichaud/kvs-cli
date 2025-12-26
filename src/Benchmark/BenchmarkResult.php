<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

/**
 * Holds benchmark results with proper metrics for HTTP, DB, and system tests
 */
class BenchmarkResult
{
    /** @var array<string, array{name: string, avg: float, min: float, max: float, p50: float, p95: float, p99: float, samples: int}> */
    private array $httpResults = [];

    /** @var array<string, array{name: string, avg_ms: float, queries_sec: float, total_queries: int}> */
    private array $dbResults = [];

    /** @var array<string, mixed> */
    private array $systemMetrics = [];

    /** @var array<string, mixed> */
    private array $systemInfo = [];

    private float $totalTime = 0.0;

    /**
     * Record HTTP benchmark result
     *
     * @param array{avg: float, min: float, max: float, p50: float, p95: float, p99: float, samples: int} $stats
     */
    public function recordHttp(string $key, string $name, array $stats): void
    {
        $this->httpResults[$key] = array_merge(['name' => $name], $stats);
    }

    /**
     * Record database benchmark result
     */
    public function recordDb(string $key, string $name, float $avgMs, float $queriesSec, int $totalQueries): void
    {
        $this->dbResults[$key] = [
            'name' => $name,
            'avg_ms' => $avgMs,
            'queries_sec' => $queriesSec,
            'total_queries' => $totalQueries,
        ];
    }

    /**
     * Set system metrics
     *
     * @param array<string, mixed> $metrics
     */
    public function setSystemMetrics(array $metrics): void
    {
        $this->systemMetrics = $metrics;
    }

    /**
     * @param array<string, mixed> $info
     */
    public function setSystemInfo(array $info): void
    {
        $this->systemInfo = $info;
    }

    public function setTotalTime(float $time): void
    {
        $this->totalTime = $time;
    }

    /**
     * @return array<string, array{name: string, avg: float, min: float, max: float, p50: float, p95: float, p99: float, samples: int}>
     */
    public function getHttpResults(): array
    {
        return $this->httpResults;
    }

    /**
     * @return array<string, array{name: string, avg_ms: float, queries_sec: float, total_queries: int}>
     */
    public function getDbResults(): array
    {
        return $this->dbResults;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystemMetrics(): array
    {
        return $this->systemMetrics;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystemInfo(): array
    {
        return $this->systemInfo;
    }

    public function getTotalTime(): float
    {
        return $this->totalTime;
    }

    /**
     * Check if we have HTTP results
     */
    public function hasHttpResults(): bool
    {
        return $this->httpResults !== [];
    }

    /**
     * Check if we have DB results
     */
    public function hasDbResults(): bool
    {
        return $this->dbResults !== [];
    }

    /**
     * Calculate performance score (lower latency = better score)
     * Score based on HTTP response times - most relevant metric
     */
    public function calculateScore(): int
    {
        if ($this->httpResults === []) {
            return 0;
        }

        $score = 0;
        $weights = [
            'homepage' => 3.0,
            'videos' => 2.0,
            'categories' => 1.5,
            'search' => 2.0,
            'admin' => 1.0,
        ];

        foreach ($this->httpResults as $key => $result) {
            $weight = $weights[$key] ?? 1.0;
            $avgMs = $result['avg'];

            if ($avgMs > 0) {
                // Score inversely proportional to response time
                // 100ms = 1000 pts, 50ms = 2000 pts, 200ms = 500 pts
                $score += (int) ((100000 / $avgMs) * $weight);
            }
        }

        return $score;
    }

    /**
     * Get rating based on average response time
     */
    public function getRating(): string
    {
        if ($this->httpResults === []) {
            return 'N/A - No HTTP tests';
        }

        $totalAvg = 0.0;
        $count = 0;
        foreach ($this->httpResults as $result) {
            $totalAvg += $result['avg'];
            $count++;
        }

        // $count is always > 0 here since we checked httpResults !== []
        $avgResponseTime = $totalAvg / $count;

        if ($avgResponseTime <= 50) {
            return '★★★★★ Excellent (<50ms)';
        } elseif ($avgResponseTime <= 100) {
            return '★★★★☆ Very Good (<100ms)';
        } elseif ($avgResponseTime <= 200) {
            return '★★★☆☆ Good (<200ms)';
        } elseif ($avgResponseTime <= 500) {
            return '★★☆☆☆ Fair (<500ms)';
        } else {
            return '★☆☆☆☆ Poor (>500ms)';
        }
    }

    /**
     * Export results to array for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timestamp' => date('c'),
            'system_info' => $this->systemInfo,
            'http_results' => $this->httpResults,
            'db_results' => $this->dbResults,
            'system_metrics' => $this->systemMetrics,
            'total_time' => $this->totalTime,
            'score' => $this->calculateScore(),
            'rating' => $this->getRating(),
        ];
    }

    /**
     * Load results from array (for comparison)
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $result = new self();

        if (isset($data['system_info']) && is_array($data['system_info'])) {
            /** @var array<string, mixed> $systemInfo */
            $systemInfo = $data['system_info'];
            $result->setSystemInfo($systemInfo);
        }

        if (isset($data['http_results']) && is_array($data['http_results'])) {
            foreach ($data['http_results'] as $key => $stats) {
                if (!is_array($stats) || !isset($stats['name'])) {
                    continue;
                }
                $name = $stats['name'];
                $result->httpResults[(string)$key] = [
                    'name' => is_string($name) ? $name : '',
                    'avg' => isset($stats['avg']) && is_numeric($stats['avg']) ? (float)$stats['avg'] : 0.0,
                    'min' => isset($stats['min']) && is_numeric($stats['min']) ? (float)$stats['min'] : 0.0,
                    'max' => isset($stats['max']) && is_numeric($stats['max']) ? (float)$stats['max'] : 0.0,
                    'p50' => isset($stats['p50']) && is_numeric($stats['p50']) ? (float)$stats['p50'] : 0.0,
                    'p95' => isset($stats['p95']) && is_numeric($stats['p95']) ? (float)$stats['p95'] : 0.0,
                    'p99' => isset($stats['p99']) && is_numeric($stats['p99']) ? (float)$stats['p99'] : 0.0,
                    'samples' => isset($stats['samples']) && is_numeric($stats['samples']) ? (int)$stats['samples'] : 0,
                ];
            }
        }

        if (isset($data['db_results']) && is_array($data['db_results'])) {
            foreach ($data['db_results'] as $key => $stats) {
                if (!is_array($stats) || !isset($stats['name'])) {
                    continue;
                }
                $name = $stats['name'];
                $result->dbResults[(string)$key] = [
                    'name' => is_string($name) ? $name : '',
                    'avg_ms' => isset($stats['avg_ms']) && is_numeric($stats['avg_ms']) ? (float)$stats['avg_ms'] : 0.0,
                    'queries_sec' => isset($stats['queries_sec']) && is_numeric($stats['queries_sec']) ? (float)$stats['queries_sec'] : 0.0,
                    'total_queries' => isset($stats['total_queries']) && is_numeric($stats['total_queries']) ? (int)$stats['total_queries'] : 0,
                ];
            }
        }

        if (isset($data['system_metrics']) && is_array($data['system_metrics'])) {
            /** @var array<string, mixed> $systemMetrics */
            $systemMetrics = $data['system_metrics'];
            $result->setSystemMetrics($systemMetrics);
        }

        if (isset($data['total_time']) && is_numeric($data['total_time'])) {
            $result->setTotalTime((float)$data['total_time']);
        }

        return $result;
    }
}
