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

    /** @var array<int, array{category: string, message: string}> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $dataVolume = [];

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
     * Add a warning message
     */
    public function addWarning(string $category, string $message): void
    {
        $this->warnings[] = [
            'category' => $category,
            'message' => $message,
        ];
    }

    /**
     * Get all warnings
     *
     * @return array<int, array{category: string, message: string}>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if there are any warnings
     */
    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * Set data volume information (row counts)
     *
     * @param array<string, int> $dataVolume
     */
    public function setDataVolume(array $dataVolume): void
    {
        $this->dataVolume = $dataVolume;
    }

    /**
     * Get data volume information
     *
     * @return array<string, int>
     */
    public function getDataVolume(): array
    {
        return $this->dataVolume;
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
     * Calculate composite performance score from all benchmarks
     *
     * Score is balanced across all components to prevent any single
     * benchmark from dominating. HTTP times are capped at 5ms minimum
     * to prevent unrealistic scores from cached/static responses.
     *
     * Target ranges (roughly equal contribution when performing well):
     * - HTTP: ~25% of score (capped to prevent localhost inflation)
     * - Database: ~25% of score
     * - Cache: ~20% of score
     * - CPU: ~20% of score
     * - File I/O: ~10% of score
     */
    public function calculateScore(): int
    {
        $score = 0;

        // HTTP score (capped minimum 5ms to prevent localhost/cache inflation)
        $score += $this->calculateHttpScore();

        // Database score
        $score += $this->calculateDbScore();

        // Cache score
        $score += $this->calculateCacheScore();

        // CPU score
        $score += $this->calculateCpuScore();

        // File I/O score
        $score += $this->calculateFileIOScore();

        return $score;
    }

    /**
     * Calculate HTTP component score
     * Capped at 5ms minimum to prevent unrealistic localhost/cached scores
     */
    private function calculateHttpScore(): int
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
            // Cap at minimum 5ms to prevent localhost/cached responses from inflating score
            $avgMs = max(5.0, $result['avg']);

            // Score: 5ms = 2000 pts * weight, 50ms = 200 pts * weight, 200ms = 50 pts * weight
            $score += (int) ((10000 / $avgMs) * $weight);
        }

        return $score;
    }

    /**
     * Calculate database component score
     */
    private function calculateDbScore(): int
    {
        if ($this->dbResults === []) {
            return 0;
        }

        $score = 0;
        // Weight important queries higher
        $weights = [
            'video_listing' => 3.0,
            'video_count' => 1.0,
            'search' => 2.0,
            'user_lookup' => 1.5,
            'category_summary' => 2.0,
            'stats_aggregation' => 1.5,
            'complex_join' => 2.0,
            'insert' => 1.0,
            'update' => 1.0,
        ];

        foreach ($this->dbResults as $key => $result) {
            $weight = $weights[$key] ?? 1.0;
            $avgMs = max(0.1, $result['avg_ms']); // Prevent division by zero

            // Score: 0.1ms = 10000 pts * weight, 1ms = 1000 pts * weight, 10ms = 100 pts * weight
            $score += (int) ((1000 / $avgMs) * $weight);
        }

        return $score;
    }

    /**
     * Calculate cache component score
     */
    private function calculateCacheScore(): int
    {
        if ($this->cacheResults === []) {
            return 0;
        }

        $score = 0;
        // Focus on KVS-relevant operations
        $weights = [
            'simple_set' => 1.0,
            'simple_get' => 1.5,
            'page_set' => 2.0,
            'page_get' => 2.5,
            'bulk_set' => 1.0,
            'bulk_get' => 1.5,
            'kvs_set' => 2.0,
            'kvs_get' => 2.5,
        ];

        foreach ($this->cacheResults as $key => $result) {
            $weight = $weights[$key] ?? 1.0;
            $avgMs = max(0.01, $result['avg']); // Prevent division by zero

            // Score based on ops/sec (higher = better)
            $opsSec = $result['ops_sec'];
            // Normalize: 50K ops/sec = 500 pts * weight
            $score += (int) (($opsSec / 100) * $weight);
        }

        return $score;
    }

    /**
     * Calculate CPU component score
     */
    private function calculateCpuScore(): int
    {
        if ($this->cpuResults === []) {
            return 0;
        }

        $score = 0;
        // Weight practical operations higher
        $importantOps = [
            'md5_simple', 'md5_session', 'md5_cache_key',
            'serialize_config', 'serialize_lang',
            'str_replace', 'htmlspecialchars',
            'regex_routing', 'array_map', 'array_filter',
        ];

        foreach ($this->cpuResults as $key => $result) {
            $weight = in_array($key, $importantOps, true) ? 1.5 : 1.0;
            $opsSec = $result['ops_sec'];

            // Normalize: 100K ops/sec = 100 pts * weight
            $score += (int) (($opsSec / 1000) * $weight);
        }

        return $score;
    }

    /**
     * Calculate File I/O component score
     */
    private function calculateFileIOScore(): int
    {
        if ($this->fileIOResults === []) {
            return 0;
        }

        $score = 0;
        // Weight practical operations higher
        $weights = [
            'config_load' => 2.0,
            'read_1k' => 1.0,
            'read_10k' => 1.5,
            'write_10k' => 1.5,
            'append_lock' => 2.0,
        ];

        foreach ($this->fileIOResults as $key => $result) {
            $weight = $weights[$key] ?? 1.0;
            $opsSec = $result['ops_sec'];

            // Normalize: 100K ops/sec = 100 pts * weight
            $score += (int) (($opsSec / 1000) * $weight);
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
            'warnings' => $this->warnings,
            'data_volume' => $this->dataVolume,
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

        // Parse warnings
        if (isset($data['warnings']) && is_array($data['warnings'])) {
            foreach ($data['warnings'] as $warning) {
                if (is_array($warning) && isset($warning['category'], $warning['message'])) {
                    $category = $warning['category'];
                    $message = $warning['message'];
                    if (is_string($category) && is_string($message)) {
                        $result->addWarning($category, $message);
                    }
                }
            }
        }

        // Parse data volume
        if (isset($data['data_volume']) && is_array($data['data_volume'])) {
            $dataVolume = [];
            foreach ($data['data_volume'] as $key => $value) {
                if (is_string($key) && is_numeric($value)) {
                    $dataVolume[$key] = (int)$value;
                }
            }
            if ($dataVolume !== []) {
                $result->setDataVolume($dataVolume);
            }
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
