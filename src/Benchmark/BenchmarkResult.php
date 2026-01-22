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
     * Category weights for score calculation
     *
     * These weights determine how much each category contributes to the final score.
     * Total must equal 1.0 (100%).
     */
    private const WEIGHTS = [
        'db' => 0.35,      // 35% - Database performance (most critical for KVS)
        'cache' => 0.25,   // 25% - Cache performance
        'cpu' => 0.25,     // 25% - CPU performance
        'fileio' => 0.15,  // 15% - File I/O performance
    ];

    /**
     * Baseline values for score calibration (Geekbench-style)
     *
     * These represent a "typical good server" performance.
     * Score of 1000 = baseline performance.
     * Score of 2000 = 2x faster than baseline.
     *
     * Baseline: Decent VPS (4 vCPU, 8GB RAM, SSD, PHP 8.1, MariaDB 10.6)
     *
     * NOTE: DB score only uses synthetic operations (INSERT/UPDATE/user_lookup)
     * that don't depend on data volume. Queries like video_listing, search, etc.
     * are shown for info but excluded from score - they vary by data size.
     */
    private const BASELINE = [
        // Database baselines - ONLY synthetic operations (data-independent)
        // video_listing, search, stats_aggregation excluded - they depend on data volume
        'db' => [
            'user_lookup' => 5000.0,       // Indexed lookup - O(1)
            'insert' => 10000.0,           // Synthetic INSERT
            'update' => 10000.0,           // Synthetic UPDATE
        ],
        // Cache baselines (ops/sec - higher = better)
        'cache' => [
            'simple_set' => 30000.0,
            'simple_get' => 40000.0,
            'page_set' => 15000.0,
            'page_get' => 20000.0,
            'bulk_set' => 500.0,
            'bulk_get' => 10000.0,
            'kvs_set' => 30000.0,
            'kvs_get' => 40000.0,
        ],
        // CPU baselines (ops/sec - higher = better)
        'cpu' => [
            'md5_simple' => 30000.0,
            'md5_session' => 150000.0,
            'md5_cache_key' => 200000.0,
            'md5_file_1k' => 100000.0,
            'md5_file_100k' => 5000.0,
            'serialize_config' => 50000.0,
            'serialize_lang' => 15000.0,
            'json_config' => 30000.0,
            'json_lang' => 6000.0,
            'str_replace' => 30000.0,
            'htmlspecialchars' => 30000.0,
            'str_concat' => 50000.0,
            'sprintf' => 70000.0,
            'regex_routing' => 500000.0,
            'regex_links' => 200000.0,
            'regex_email' => 2000000.0,
            'stats_calc' => 50000.0,
            'array_sort' => 4000.0,
            'percentile' => 10000.0,
            'array_map' => 35000.0,
            'array_filter' => 30000.0,
            'array_column' => 80000.0,
            'array_merge' => 500000.0,
            'usort' => 3000.0,
        ],
        // File I/O baselines (ops/sec - higher = better)
        'fileio' => [
            'serialize_config' => 1000000.0,
            'unserialize_config' => 500000.0,
            'serialize_lang' => 40000.0,
            'config_load' => 100000.0,
            'read_1k' => 120000.0,
            'read_10k' => 100000.0,
            'read_100k' => 40000.0,
            'write_10k' => 50000.0,
            'write_fsync' => 45000.0,
            'scandir' => 40000.0,
            'glob' => 25000.0,
            'filemtime' => 8000.0,
            'append_nolock' => 150000.0,
            'append_lock' => 130000.0,
            'append_flock' => 120000.0,
        ],
    ];

    /**
     * Calculate composite performance score (Geekbench-style calibration)
     *
     * Uses baseline calibration where 1000 = reference system performance.
     * Final score = weighted geometric mean of category scores.
     *
     * Categories and weights:
     * - Database: 35% (most important for KVS)
     * - Cache: 25%
     * - CPU: 25%
     * - File I/O: 15%
     *
     * HTTP is excluded from score - it measures network, not server performance.
     */
    public function calculateScore(): int
    {
        // Calculate weighted arithmetic mean of category scores
        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach (self::WEIGHTS as $category => $weight) {
            $score = $this->calculateCategoryScore($category);
            if ($score > 0) {
                $weightedSum += $score * $weight;
                $totalWeight += $weight;
            }
        }

        if ($totalWeight === 0.0) {
            return 0;
        }

        return (int) round($weightedSum / $totalWeight);
    }

    /**
     * Calculate score for a single category using geometric mean
     *
     * Each test score = (measured / baseline) * 1000
     * Category score = geometric mean of all test scores
     */
    private function calculateCategoryScore(string $category): float
    {
        $baselines = self::BASELINE[$category] ?? [];
        $scores = [];

        switch ($category) {
            case 'db':
                foreach ($this->dbResults as $key => $result) {
                    $baseline = $baselines[$key] ?? null;
                    if ($baseline !== null && $result['queries_sec'] > 0) {
                        $scores[] = ($result['queries_sec'] / $baseline) * 1000;
                    }
                }
                break;

            case 'cache':
                foreach ($this->cacheResults as $key => $result) {
                    $baseline = $baselines[$key] ?? null;
                    if ($baseline !== null && $result['ops_sec'] > 0) {
                        $scores[] = ($result['ops_sec'] / $baseline) * 1000;
                    }
                }
                break;

            case 'cpu':
                foreach ($this->cpuResults as $key => $result) {
                    $baseline = $baselines[$key] ?? null;
                    if ($baseline !== null && $result['ops_sec'] > 0) {
                        $scores[] = ($result['ops_sec'] / $baseline) * 1000;
                    }
                }
                break;

            case 'fileio':
                foreach ($this->fileIOResults as $key => $result) {
                    $baseline = $baselines[$key] ?? null;
                    if ($baseline !== null && $result['ops_sec'] > 0) {
                        $scores[] = ($result['ops_sec'] / $baseline) * 1000;
                    }
                }
                break;
        }

        if ($scores === []) {
            return 0.0;
        }

        // Geometric mean
        $product = 1.0;
        foreach ($scores as $score) {
            $product *= $score;
        }

        return pow($product, 1 / count($scores));
    }

    /**
     * Get rating based on score (baseline = 1000)
     */
    public function getRating(): string
    {
        $score = $this->calculateScore();

        if ($score === 0) {
            return 'N/A - No benchmarks run';
        }

        // Rating based on score relative to baseline (1000)
        if ($score >= 1500) {
            return '★★★★★ Excellent (150%+ of baseline)';
        } elseif ($score >= 1200) {
            return '★★★★☆ Very Good (120%+ of baseline)';
        } elseif ($score >= 900) {
            return '★★★☆☆ Good (90%+ of baseline)';
        } elseif ($score >= 600) {
            return '★★☆☆☆ Fair (60%+ of baseline)';
        } else {
            return '★☆☆☆☆ Below baseline';
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
            'weights' => self::WEIGHTS,  // Include weights used for calculation
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
     * @param array<array-key, mixed> $data
     */
    private static function getFloat(array $data, string $key): float
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (float)$data[$key] : 0.0;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function getInt(array $data, string $key): int
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (int)$data[$key] : 0;
    }
}
