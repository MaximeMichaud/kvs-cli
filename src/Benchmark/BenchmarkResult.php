<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

/**
 * Holds benchmark results with proper metrics for HTTP, DB, and system tests
 */
class BenchmarkResult
{
    /**
     * @var array<string, array{
     *     name: string, avg: float, min: float, max: float,
     *     p50: float, p95: float, p99: float, req_sec: float, samples: int
     * }>
     */
    private array $httpResults = [];

    /** @var array<string, array{name: string, avg_ms: float, queries_sec: float, total_queries: int}> */
    private array $dbResults = [];

    /** @var array<string, array{name: string, avg: float, min: float, max: float, p50: float, p95: float, p99: float, ops_sec: float, samples: int, hit_ratio?: float}> */
    private array $cacheResults = [];

    /** @var array<string, array{name: string, avg: float, min: float, max: float, ops_sec: float, samples: int}> */
    private array $fileIOResults = [];

    /**
     * CPU benchmark results
     *
     * @var array<string, array{
     *     name: string, avg: float, min: float, max: float,
     *     p50: float, p95: float, p99: float, std_dev: float,
     *     ops_sec: float, samples: int
     * }>
     */
    private array $cpuResults = [];

    /** @var array<string, mixed> */
    private array $systemMetrics = [];

    /** @var array<string, mixed> */
    private array $systemInfo = [];

    private float $totalTime = 0.0;

    private string $tag = '';

    /**
     * Record HTTP benchmark result
     *
     * @param array{
     *     avg: float, min: float, max: float,
     *     p50: float, p95: float, p99: float, req_sec: float, samples: int
     * } $stats
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
     * Record cache benchmark result
     *
     * @param array{avg: float, min: float, max: float, p50: float, p95: float, p99: float, ops_sec: float, samples: int, hit_ratio?: float} $stats
     */
    public function recordCache(string $key, string $name, array $stats): void
    {
        $this->cacheResults[$key] = array_merge(['name' => $name], $stats);
    }

    /**
     * Record file I/O benchmark result
     *
     * @param array{avg: float, min: float, max: float, ops_sec: float, samples: int} $stats
     */
    public function recordFileIO(string $key, string $name, array $stats): void
    {
        $this->fileIOResults[$key] = array_merge(['name' => $name], $stats);
    }

    /**
     * Record CPU benchmark result
     *
     * @param array{
     *     avg: float, min: float, max: float,
     *     p50: float, p95: float, p99: float, std_dev: float,
     *     ops_sec: float, samples: int
     * } $stats
     */
    public function recordCpu(string $key, string $name, array $stats): void
    {
        $this->cpuResults[$key] = array_merge(['name' => $name], $stats);
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

    public function setTag(string $tag): void
    {
        $this->tag = $tag;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * @return array<string, array{
     *     name: string, avg: float, min: float, max: float,
     *     p50: float, p95: float, p99: float, req_sec: float, samples: int
     * }>
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
     * Get cache benchmark results
     *
     * @return array<string, array{
     *     name: string, avg: float, min: float, max: float,
     *     p50: float, p95: float, p99: float, ops_sec: float,
     *     samples: int, hit_ratio?: float
     * }>
     */
    public function getCacheResults(): array
    {
        return $this->cacheResults;
    }

    /**
     * @return array<string, array{name: string, avg: float, min: float, max: float, ops_sec: float, samples: int}>
     */
    public function getFileIOResults(): array
    {
        return $this->fileIOResults;
    }

    /**
     * @return array<string, array{
     *     name: string, avg: float, min: float, max: float,
     *     p50: float, p95: float, p99: float, std_dev: float,
     *     ops_sec: float, samples: int
     * }>
     */
    public function getCpuResults(): array
    {
        return $this->cpuResults;
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
     * Check if we have cache results
     */
    public function hasCacheResults(): bool
    {
        return $this->cacheResults !== [];
    }

    /**
     * Check if we have file I/O results
     */
    public function hasFileIOResults(): bool
    {
        return $this->fileIOResults !== [];
    }

    /**
     * Check if we have CPU results
     */
    public function hasCpuResults(): bool
    {
        return $this->cpuResults !== [];
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
        $result = [
            'timestamp' => date('c'),
            'system_info' => $this->systemInfo,
            'http_results' => $this->httpResults,
            'db_results' => $this->dbResults,
            'cache_results' => $this->cacheResults,
            'fileio_results' => $this->fileIOResults,
            'cpu_results' => $this->cpuResults,
            'system_metrics' => $this->systemMetrics,
            'total_time' => $this->totalTime,
            'score' => $this->calculateScore(),
            'rating' => $this->getRating(),
        ];

        if ($this->tag !== '') {
            $result['tag'] = $this->tag;
        }

        return $result;
    }

    /**
     * Load results from array (for comparison)
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $result = new self();

        self::parseSystemInfo($result, $data);
        self::parseHttpResults($result, $data);
        self::parseDbResults($result, $data);
        self::parseCacheResults($result, $data);
        self::parseFileIOResults($result, $data);
        self::parseCpuResults($result, $data);
        self::parseMetadata($result, $data);

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseSystemInfo(self $result, array $data): void
    {
        if (isset($data['system_info']) && is_array($data['system_info'])) {
            /** @var array<string, mixed> $systemInfo */
            $systemInfo = $data['system_info'];
            $result->setSystemInfo($systemInfo);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseHttpResults(self $result, array $data): void
    {
        if (!isset($data['http_results']) || !is_array($data['http_results'])) {
            return;
        }

        foreach ($data['http_results'] as $key => $stats) {
            if (!is_array($stats) || !isset($stats['name'])) {
                continue;
            }
            $name = $stats['name'];
            $avg = self::getFloat($stats, 'avg');
            $reqSec = self::getFloat($stats, 'req_sec');
            $result->httpResults[(string)$key] = [
                'name' => is_string($name) ? $name : '',
                'avg' => $avg,
                'min' => self::getFloat($stats, 'min'),
                'max' => self::getFloat($stats, 'max'),
                'p50' => self::getFloat($stats, 'p50'),
                'p95' => self::getFloat($stats, 'p95'),
                'p99' => self::getFloat($stats, 'p99'),
                'req_sec' => $reqSec > 0 ? $reqSec : ($avg > 0 ? round(1000 / $avg, 2) : 0.0),
                'samples' => self::getInt($stats, 'samples'),
            ];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseDbResults(self $result, array $data): void
    {
        if (!isset($data['db_results']) || !is_array($data['db_results'])) {
            return;
        }

        foreach ($data['db_results'] as $key => $stats) {
            if (!is_array($stats) || !isset($stats['name'])) {
                continue;
            }
            $name = $stats['name'];
            $result->dbResults[(string)$key] = [
                'name' => is_string($name) ? $name : '',
                'avg_ms' => self::getFloat($stats, 'avg_ms'),
                'queries_sec' => self::getFloat($stats, 'queries_sec'),
                'total_queries' => self::getInt($stats, 'total_queries'),
            ];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseCacheResults(self $result, array $data): void
    {
        if (!isset($data['cache_results']) || !is_array($data['cache_results'])) {
            return;
        }

        foreach ($data['cache_results'] as $key => $stats) {
            if (!is_array($stats) || !isset($stats['name'])) {
                continue;
            }
            $name = $stats['name'];
            $cacheResult = [
                'name' => is_string($name) ? $name : '',
                'avg' => self::getFloat($stats, 'avg'),
                'min' => self::getFloat($stats, 'min'),
                'max' => self::getFloat($stats, 'max'),
                'p50' => self::getFloat($stats, 'p50'),
                'p95' => self::getFloat($stats, 'p95'),
                'p99' => self::getFloat($stats, 'p99'),
                'ops_sec' => self::getFloat($stats, 'ops_sec'),
                'samples' => self::getInt($stats, 'samples'),
            ];
            if (isset($stats['hit_ratio']) && is_numeric($stats['hit_ratio'])) {
                $cacheResult['hit_ratio'] = (float)$stats['hit_ratio'];
            }
            $result->cacheResults[(string)$key] = $cacheResult;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseFileIOResults(self $result, array $data): void
    {
        if (!isset($data['fileio_results']) || !is_array($data['fileio_results'])) {
            return;
        }

        foreach ($data['fileio_results'] as $key => $stats) {
            if (!is_array($stats) || !isset($stats['name'])) {
                continue;
            }
            $name = $stats['name'];
            $result->fileIOResults[(string)$key] = [
                'name' => is_string($name) ? $name : '',
                'avg' => self::getFloat($stats, 'avg'),
                'min' => self::getFloat($stats, 'min'),
                'max' => self::getFloat($stats, 'max'),
                'ops_sec' => self::getFloat($stats, 'ops_sec'),
                'samples' => self::getInt($stats, 'samples'),
            ];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseCpuResults(self $result, array $data): void
    {
        if (!isset($data['cpu_results']) || !is_array($data['cpu_results'])) {
            return;
        }

        foreach ($data['cpu_results'] as $key => $stats) {
            if (!is_array($stats) || !isset($stats['name'])) {
                continue;
            }
            $name = $stats['name'];
            $result->cpuResults[(string)$key] = [
                'name' => is_string($name) ? $name : '',
                'avg' => self::getFloat($stats, 'avg'),
                'min' => self::getFloat($stats, 'min'),
                'max' => self::getFloat($stats, 'max'),
                'p50' => self::getFloat($stats, 'p50'),
                'p95' => self::getFloat($stats, 'p95'),
                'p99' => self::getFloat($stats, 'p99'),
                'std_dev' => self::getFloat($stats, 'std_dev'),
                'ops_sec' => self::getFloat($stats, 'ops_sec'),
                'samples' => self::getInt($stats, 'samples'),
            ];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseMetadata(self $result, array $data): void
    {
        if (isset($data['system_metrics']) && is_array($data['system_metrics'])) {
            /** @var array<string, mixed> $systemMetrics */
            $systemMetrics = $data['system_metrics'];
            $result->setSystemMetrics($systemMetrics);
        }

        if (isset($data['total_time']) && is_numeric($data['total_time'])) {
            $result->setTotalTime((float)$data['total_time']);
        }

        if (isset($data['tag']) && is_string($data['tag'])) {
            $result->setTag($data['tag']);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function getFloat(array $data, string $key): float
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (float)$data[$key] : 0.0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function getInt(array $data, string $key): int
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (int)$data[$key] : 0;
    }
}
