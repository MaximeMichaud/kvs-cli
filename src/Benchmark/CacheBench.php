<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

use KVS\CLI\Constants;
use Memcached;

/**
 * Object cache benchmark (Memcached protocol compatible)
 *
 * Supports:
 * - Memcached (native)
 * - Dragonfly (Memcached protocol compatible)
 *
 * Tests real KVS caching patterns:
 * - Page cache with MD5 hash keys (like KVS does)
 * - Bulk GET/SET operations
 * - Hit ratio simulation
 */
class CacheBench
{
    private int $iterations;
    private ?Memcached $memcached = null;

    /** @var array{host: string, port: int} */
    private array $config;

    /**
     * @param array{host?: string, port?: int} $config
     */
    public function __construct(array $config = [], int $iterations = 100)
    {
        $this->iterations = $iterations;
        $this->config = [
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? Constants::DEFAULT_MEMCACHE_PORT,
        ];
    }

    /**
     * Connect to Memcached server
     */
    public function connect(): bool
    {
        if (!class_exists('Memcached')) {
            return false;
        }

        try {
            $mc = new Memcached();
            $mc->addServer($this->config['host'], $this->config['port']);
            $mc->setOption(Memcached::OPT_CONNECT_TIMEOUT, 1000);

            // Test connection
            $mc->set('_kvs_bench_test', '1', 1);
            if ($mc->getResultCode() !== Memcached::RES_SUCCESS) {
                return false;
            }

            $this->memcached = $mc;
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get cache server version
     */
    public function getCacheVersion(): string
    {
        if ($this->memcached === null) {
            return 'unknown';
        }

        $stats = $this->memcached->getStats();
        $server = $this->config['host'] . ':' . $this->config['port'];
        return $stats[$server]['version'] ?? 'unknown';
    }

    /**
     * Detect cache backend type
     *
     * Attempts to identify if this is Memcached, Dragonfly, or other
     * compatible server by checking version string patterns.
     *
     * @return string Backend type: 'memcached', 'dragonfly', or 'unknown'
     */
    public function detectBackendType(): string
    {
        if ($this->memcached === null) {
            return 'unknown';
        }

        $version = $this->getCacheVersion();

        // Dragonfly version patterns:
        // - Contains "dragonfly" in the string
        // - Starts with "df-"
        // - Starts with "v" followed by version (e.g., "v1.35.1")
        if (stripos($version, 'dragonfly') !== false || stripos($version, 'df-') !== false) {
            return 'dragonfly';
        }

        // Dragonfly often reports versions like "v1.35.1" (with 'v' prefix)
        if (preg_match('/^v\d+\.\d+/', $version) === 1) {
            return 'dragonfly';
        }

        // Standard memcached versions are typically just numbers (e.g., "1.6.18")
        // without 'v' prefix
        if (preg_match('/^\d+\.\d+/', $version) === 1) {
            return 'memcached';
        }

        return 'unknown';
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->memcached !== null;
    }

    /**
     * Run cache benchmarks
     */
    public function run(BenchmarkResult $result): void
    {
        if (!$this->isConnected()) {
            return;
        }

        // Test 1: Simple GET/SET latency (like KVS page cache)
        $this->benchSimpleOperations($result);

        // Test 2: Large value operations (page content)
        $this->benchLargeValues($result);

        // Test 3: Bulk operations (multi-get/set)
        $this->benchBulkOperations($result);

        // Test 4: Key pattern matching KVS style
        $this->benchKvsPattern($result);

        // Memcached keys will auto-expire, no cleanup needed
    }

    /**
     * Benchmark simple GET/SET operations
     */
    private function benchSimpleOperations(BenchmarkResult $result): void
    {
        $setTimings = [];
        $getTimings = [];
        $keyBase = 'kvs_bench_simple_';

        // SET operations
        for ($i = 0; $i < $this->iterations; $i++) {
            $key = $keyBase . $i;
            $value = 'test_value_' . $i;

            $start = microtime(true);
            $this->cacheSet($key, $value, 60);
            $setTimings[] = (microtime(true) - $start) * 1000;
        }

        // GET operations
        for ($i = 0; $i < $this->iterations; $i++) {
            $key = $keyBase . $i;

            $start = microtime(true);
            $this->cacheGet($key);
            $getTimings[] = (microtime(true) - $start) * 1000;
        }

        $result->recordCache('simple_set', 'Simple SET', $this->calculateCacheStats($setTimings));
        $result->recordCache('simple_get', 'Simple GET', $this->calculateCacheStats($getTimings));
    }

    /**
     * Benchmark large value operations (simulating page cache)
     */
    private function benchLargeValues(BenchmarkResult $result): void
    {
        // Simulate page content (~50KB like a typical KVS page)
        $pageContent = str_repeat('Lorem ipsum dolor sit amet. ', 2000);
        $setTimings = [];
        $getTimings = [];
        $keyBase = 'kvs_bench_page_';
        $iterations = min(50, $this->iterations);

        // SET large values
        for ($i = 0; $i < $iterations; $i++) {
            $key = $keyBase . md5((string)$i); // KVS uses MD5 hashes for cache keys

            $start = microtime(true);
            $this->cacheSet($key, $pageContent, 300);
            $setTimings[] = (microtime(true) - $start) * 1000;
        }

        // GET large values
        for ($i = 0; $i < $iterations; $i++) {
            $key = $keyBase . md5((string)$i);

            $start = microtime(true);
            $this->cacheGet($key);
            $getTimings[] = (microtime(true) - $start) * 1000;
        }

        $result->recordCache('page_set', 'Page SET (~50KB)', $this->calculateCacheStats($setTimings));
        $result->recordCache('page_get', 'Page GET (~50KB)', $this->calculateCacheStats($getTimings));
    }

    /**
     * Benchmark bulk operations
     */
    private function benchBulkOperations(BenchmarkResult $result): void
    {
        $keyBase = 'kvs_bench_bulk_';
        $batchSize = 100;
        $batches = max(1, intdiv($this->iterations, $batchSize));

        $setTimings = [];
        $getTimings = [];

        for ($batch = 0; $batch < $batches; $batch++) {
            $keys = [];
            $data = [];
            for ($i = 0; $i < $batchSize; $i++) {
                $key = $keyBase . ($batch * $batchSize + $i);
                $keys[] = $key;
                $data[$key] = 'bulk_value_' . $i;
            }

            // Multi-SET
            $start = microtime(true);
            $this->cacheSetMulti($data, 60);
            $setTimings[] = (microtime(true) - $start) * 1000;

            // Multi-GET
            $start = microtime(true);
            $this->cacheGetMulti($keys);
            $getTimings[] = (microtime(true) - $start) * 1000;
        }

        $result->recordCache('bulk_set', "Multi-SET ({$batchSize} keys)", $this->calculateCacheStats($setTimings));
        $result->recordCache('bulk_get', "Multi-GET ({$batchSize} keys)", $this->calculateCacheStats($getTimings));
    }

    /**
     * Benchmark KVS-style cache patterns
     * KVS uses: md5($language . $page_url . serialize($params)) as cache key
     */
    private function benchKvsPattern(BenchmarkResult $result): void
    {
        $setTimings = [];
        $getTimings = [];
        $hitCount = 0;
        $iterations = min(100, $this->iterations);

        // Simulate KVS page cache pattern
        $pages = ['/videos/', '/categories/', '/tags/', '/members/', '/'];
        $languages = ['en', 'fr', 'de', 'es'];

        for ($i = 0; $i < $iterations; $i++) {
            $page = $pages[$i % count($pages)];
            $lang = $languages[$i % count($languages)];
            $params = ['page' => $i % 10, 'sort' => 'date'];

            // KVS-style cache key
            $key = 'kvs_page_' . md5($lang . $page . serialize($params));
            $content = '<html>' . str_repeat('content', 500) . '</html>';

            // SET
            $start = microtime(true);
            $this->cacheSet($key, $content, 300);
            $setTimings[] = (microtime(true) - $start) * 1000;

            // GET (simulate 80% hit ratio)
            if ($i % 5 !== 0) {
                $start = microtime(true);
                $cached = $this->cacheGet($key);
                $getTimings[] = (microtime(true) - $start) * 1000;

                if ($cached !== false && $cached !== null) {
                    $hitCount++;
                }
            }
        }

        $hitRatio = count($getTimings) > 0 ? ($hitCount / count($getTimings)) * 100 : 0;

        $setStats = $this->calculateCacheStats($setTimings);
        $getStats = $this->calculateCacheStats($getTimings);
        $getStats['hit_ratio'] = $hitRatio;

        $result->recordCache('kvs_set', 'KVS Page Cache SET', $setStats);
        $result->recordCache('kvs_get', 'KVS Page Cache GET', $getStats);
    }

    /**
     * Calculate statistics from timings
     *
     * @param array<int, float> $timings
     * @return array{avg: float, min: float, max: float, p50: float, p95: float, p99: float, ops_sec: float, samples: int}
     */
    private function calculateCacheStats(array $timings): array
    {
        if ($timings === []) {
            return [
                'avg' => 0.0,
                'min' => 0.0,
                'max' => 0.0,
                'p50' => 0.0,
                'p95' => 0.0,
                'p99' => 0.0,
                'ops_sec' => 0.0,
                'samples' => 0,
            ];
        }

        sort($timings);
        $count = count($timings);
        $avg = array_sum($timings) / $count;

        return [
            'avg' => $avg,
            'min' => $timings[0],
            'max' => $timings[$count - 1],
            'p50' => $this->percentile($timings, 50),
            'p95' => $this->percentile($timings, 95),
            'p99' => $this->percentile($timings, 99),
            'ops_sec' => $avg > 0 ? 1000 / $avg : 0,
            'samples' => $count,
        ];
    }

    /**
     * @param array<int, float> $sorted
     */
    private function percentile(array $sorted, int $p): float
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
     * Cache SET operation
     */
    private function cacheSet(string $key, string $value, int $ttl): bool
    {
        if ($this->memcached === null) {
            return false;
        }
        return $this->memcached->set($key, $value, $ttl);
    }

    /**
     * Cache GET operation
     */
    private function cacheGet(string $key): mixed
    {
        if ($this->memcached === null) {
            return false;
        }
        return $this->memcached->get($key);
    }

    /**
     * Cache multi-SET operation
     *
     * @param array<string, string> $data
     */
    private function cacheSetMulti(array $data, int $ttl): bool
    {
        if ($this->memcached === null) {
            return false;
        }
        return $this->memcached->setMulti($data, $ttl);
    }

    /**
     * Cache multi-GET operation
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function cacheGetMulti(array $keys): array
    {
        if ($this->memcached === null) {
            return [];
        }
        $result = $this->memcached->getMulti($keys);
        return is_array($result) ? $result : [];
    }

    /**
     * Close connection
     */
    public function close(): void
    {
        if ($this->memcached !== null) {
            $this->memcached->quit();
            $this->memcached = null;
        }
    }
}
