<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

/**
 * CPU-bound benchmark operations
 *
 * Tests operations that are CPU-intensive in KVS:
 * - MD5 hashing (used for cache keys, file validation, session IDs)
 * - Serialize vs JSON (KVS uses serialize() for .dat files)
 * - String operations (common in template processing)
 * - Regular expressions (used in URL routing, content filtering)
 *
 * These benchmarks are especially useful for comparing:
 * - PHP versions (8.1 vs 8.2 vs 8.3 vs 8.4)
 * - JIT enabled vs disabled
 * - OPcache configurations
 */
class CpuBench
{
    use BenchmarkHelper;

    private int $iterations;

    public function __construct(int $iterations = 1000)
    {
        $this->iterations = $iterations;
    }

    /**
     * Run all CPU benchmarks
     */
    public function run(BenchmarkResult $result): void
    {
        // Hashing operations (KVS hot path)
        $this->benchMd5($result);
        $this->benchMd5File($result);

        // Serialization comparison (critical for .dat files)
        $this->benchSerializeVsJson($result);

        // String operations (template processing)
        $this->benchStringOperations($result);

        // Regex operations (routing, filtering)
        $this->benchRegex($result);

        // Math operations (stats calculations)
        $this->benchMath($result);

        // Array operations (common in KVS)
        $this->benchArrayOperations($result);
    }

    /**
     * Benchmark MD5 hashing (used on EVERY file request in KVS)
     *
     * KVS pattern from get_file.php:
     * $hash_check = md5($config['cv'] . $file);
     */
    private function benchMd5(BenchmarkResult $result): void
    {
        $secretKey = 'kvs_cryptographic_verification_key_12345';
        $filePaths = [];

        // Generate realistic file paths
        for ($i = 0; $i < 100; $i++) {
            $filePaths[] = "/content/videos/{$i}/video_{$i}_720p.mp4";
        }

        // Simple MD5 (string)
        $simpleTimings = $this->runBenchmark(function () use ($secretKey, $filePaths): void {
            foreach ($filePaths as $path) {
                $hash = md5($secretKey . $path);
            }
        }, $this->iterations);

        // KVS session ID pattern: md5(mt_rand() . microtime())
        $sessionTimings = $this->runBenchmark(function (): void {
            for ($i = 0; $i < 10; $i++) {
                $sessionId = md5((string) mt_rand(0, 999999999) . microtime());
            }
        }, $this->iterations);

        // KVS cache key pattern: md5($lang . $url . serialize($params))
        $cacheKeyTimings = $this->runBenchmark(function (): void {
            $lang = 'en';
            $url = '/videos/category/popular';
            $params = ['page' => 1, 'sort' => 'date', 'period' => 'week'];

            for ($i = 0; $i < 10; $i++) {
                $cacheKey = md5($lang . $url . serialize($params));
            }
        }, $this->iterations);

        $result->recordCpu('md5_simple', 'MD5 Hash (100 strings)', $this->calculateStats($simpleTimings));
        $result->recordCpu('md5_session', 'MD5 Session ID (10x)', $this->calculateStats($sessionTimings));
        $result->recordCpu('md5_cache_key', 'MD5 Cache Key (10x)', $this->calculateStats($cacheKeyTimings));
    }

    /**
     * Benchmark MD5 file hashing (video file integrity)
     */
    private function benchMd5File(BenchmarkResult $result): void
    {
        // Create temp files of different sizes
        $tempDir = sys_get_temp_dir();
        $files = [
            'small' => $tempDir . '/bench_md5_1kb.tmp',
            'medium' => $tempDir . '/bench_md5_100kb.tmp',
        ];

        file_put_contents($files['small'], str_repeat('x', 1024));
        file_put_contents($files['medium'], str_repeat('x', 102400));

        // Benchmark file hashing
        $smallTimings = $this->runBenchmark(function () use ($files): void {
            md5_file($files['small']);
        }, min(100, $this->iterations));

        $mediumTimings = $this->runBenchmark(function () use ($files): void {
            md5_file($files['medium']);
        }, min(50, $this->iterations));

        // Cleanup
        @unlink($files['small']);
        @unlink($files['medium']);

        $result->recordCpu('md5_file_1kb', 'MD5 File (1KB)', $this->calculateStats($smallTimings));
        $result->recordCpu('md5_file_100kb', 'MD5 File (100KB)', $this->calculateStats($mediumTimings));
    }

    /**
     * Benchmark serialize() vs json_encode()
     *
     * KVS uses serialize() for all .dat files.
     * This test shows if JSON would be faster.
     */
    private function benchSerializeVsJson(BenchmarkResult $result): void
    {
        // Realistic KVS config structure
        $configData = [
            'project_path' => '/var/www/kvs',
            'project_url' => 'https://example.com',
            'version' => '6.3.2',
            'settings' => [
                'videos_per_page' => 20,
                'cache_ttl' => 300,
                'enabled_features' => ['comments', 'ratings', 'favorites'],
            ],
            'categories' => array_map(fn($i) => [
                'id' => $i,
                'title' => "Category {$i}",
                'slug' => "category-{$i}",
            ], range(1, 50)),
        ];

        // Large language data (500 strings)
        $langData = [];
        for ($i = 1; $i <= 500; $i++) {
            $langData["lang_key_{$i}"] = "Translated string number {$i} with some content";
        }

        // Serialize tests
        $serializeTimings = $this->runBenchmark(function () use ($configData): void {
            $serialized = serialize($configData);
            $data = unserialize($serialized);
        }, $this->iterations);

        $serializeLangTimings = $this->runBenchmark(function () use ($langData): void {
            $serialized = serialize($langData);
            $data = unserialize($serialized);
        }, $this->iterations);

        // JSON tests
        $jsonTimings = $this->runBenchmark(function () use ($configData): void {
            $json = json_encode($configData);
            if (is_string($json)) {
                $data = json_decode($json, true);
            }
        }, $this->iterations);

        $jsonLangTimings = $this->runBenchmark(function () use ($langData): void {
            $json = json_encode($langData);
            if (is_string($json)) {
                $data = json_decode($json, true);
            }
        }, $this->iterations);

        $result->recordCpu('serialize_config', 'Serialize Config (50 cats)', $this->calculateStats($serializeTimings));
        $result->recordCpu('serialize_lang', 'Serialize Lang (500 str)', $this->calculateStats($serializeLangTimings));
        $result->recordCpu('json_config', 'JSON Config (50 cats)', $this->calculateStats($jsonTimings));
        $result->recordCpu('json_lang', 'JSON Lang (500 str)', $this->calculateStats($jsonLangTimings));
    }

    /**
     * Benchmark string operations (template processing)
     */
    private function benchStringOperations(BenchmarkResult $result): void
    {
        $template = '<div class="video-item">{TITLE}</div><span>{VIEWS} views</span>';
        $html = str_repeat('<p>Sample content with special chars: é à ü ß</p>', 100);

        // String replacement (template processing)
        $replaceTimings = $this->runBenchmark(function () use ($template): void {
            for ($i = 0; $i < 100; $i++) {
                $output = str_replace(
                    ['{TITLE}', '{VIEWS}'],
                    ["Video Title {$i}", (string) ($i * 1000)],
                    $template
                );
            }
        }, $this->iterations);

        // HTML entity encoding
        $htmlEntitiesTimings = $this->runBenchmark(function () use ($html): void {
            $encoded = htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }, $this->iterations);

        // String concatenation vs sprintf
        $concatTimings = $this->runBenchmark(function (): void {
            for ($i = 0; $i < 100; $i++) {
                $str = 'Video #' . $i . ' - Title: ' . 'Example' . ' (' . $i . ' views)';
            }
        }, $this->iterations);

        $sprintfTimings = $this->runBenchmark(function (): void {
            for ($i = 0; $i < 100; $i++) {
                $str = sprintf('Video #%d - Title: %s (%d views)', $i, 'Example', $i);
            }
        }, $this->iterations);

        $result->recordCpu('str_replace', 'str_replace (100x)', $this->calculateStats($replaceTimings));
        $result->recordCpu('htmlspecialchars', 'htmlspecialchars (4KB)', $this->calculateStats($htmlEntitiesTimings));
        $result->recordCpu('concat', 'String concat (100x)', $this->calculateStats($concatTimings));
        $result->recordCpu('sprintf', 'sprintf (100x)', $this->calculateStats($sprintfTimings));
    }

    /**
     * Benchmark regex operations (routing, content filtering)
     */
    private function benchRegex(BenchmarkResult $result): void
    {
        $urls = [
            '/videos/123/my-video-title',
            '/categories/popular/page/5',
            '/search?q=test+query&page=2',
            '/user/profile/johndoe',
        ];

        $content = str_repeat('Some text with <a href="http://example.com">links</a> and more text. ', 50);

        // URL routing regex (common in KVS)
        $routeTimings = $this->runBenchmark(function () use ($urls): void {
            foreach ($urls as $url) {
                preg_match('#^/videos/(\d+)/([a-z0-9-]+)$#', $url, $matches);
                preg_match('#^/categories/([a-z]+)/page/(\d+)$#', $url, $matches);
                preg_match('#^/user/profile/([a-z0-9]+)$#i', $url, $matches);
            }
        }, $this->iterations);

        // Content filtering (link extraction)
        $contentTimings = $this->runBenchmark(function () use ($content): void {
            preg_match_all('#<a\s+href=["\']([^"\']+)["\'][^>]*>#i', $content, $matches);
        }, $this->iterations);

        // Email validation pattern
        $emailTimings = $this->runBenchmark(function (): void {
            $emails = ['user@example.com', 'invalid-email', 'test.user+tag@domain.co.uk'];
            foreach ($emails as $email) {
                $valid = preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email);
            }
        }, $this->iterations);

        $result->recordCpu('regex_routing', 'Regex URL Routing (4 urls)', $this->calculateStats($routeTimings));
        $result->recordCpu('regex_content', 'Regex Link Extract (2KB)', $this->calculateStats($contentTimings));
        $result->recordCpu('regex_email', 'Regex Email Valid (3x)', $this->calculateStats($emailTimings));
    }

    /**
     * Benchmark math operations (stats calculations)
     */
    private function benchMath(BenchmarkResult $result): void
    {
        $viewCounts = array_map(fn() => mt_rand(100, 100000), range(1, 1000));
        $ratings = array_map(fn() => mt_rand(1, 5) + (mt_rand(0, 99) / 100), range(1, 1000));

        // Statistics calculation (like cron_stats.php)
        $statsTimings = $this->runBenchmark(function () use ($viewCounts, $ratings): void {
            $totalViews = array_sum($viewCounts);
            $avgViews = $totalViews / count($viewCounts);
            $maxViews = max($viewCounts);
            $minViews = min($viewCounts);

            $avgRating = array_sum($ratings) / count($ratings);
        }, $this->iterations);

        // Sorting (common in rankings)
        $sortTimings = $this->runBenchmark(function () use ($viewCounts): void {
            $sorted = $viewCounts;
            sort($sorted);
            rsort($sorted);
        }, min(100, $this->iterations));

        // Percentile calculation
        $percentileTimings = $this->runBenchmark(function () use ($viewCounts): void {
            $sorted = $viewCounts;
            sort($sorted);
            $count = count($sorted);
            $p50 = $sorted[(int) ($count * 0.50)];
            $p95 = $sorted[(int) ($count * 0.95)];
            $p99 = $sorted[(int) ($count * 0.99)];
        }, min(100, $this->iterations));

        $result->recordCpu('math_stats', 'Stats Calc (1000 items)', $this->calculateStats($statsTimings));
        $result->recordCpu('math_sort', 'Array Sort (1000 items)', $this->calculateStats($sortTimings));
        $result->recordCpu('math_percentile', 'Percentile (1000 items)', $this->calculateStats($percentileTimings));
    }

    /**
     * Benchmark array operations (common in KVS data processing)
     */
    private function benchArrayOperations(BenchmarkResult $result): void
    {
        $videos = array_map(fn($i) => [
            'id' => $i,
            'title' => "Video {$i}",
            'views' => mt_rand(100, 100000),
            'rating' => mt_rand(1, 5),
            'category_id' => mt_rand(1, 20),
        ], range(1, 500));

        // array_map (transform data)
        $mapTimings = $this->runBenchmark(function () use ($videos): void {
            $titles = array_map(fn($v) => $v['title'], $videos);
        }, $this->iterations);

        // array_filter (status filtering)
        $filterTimings = $this->runBenchmark(function () use ($videos): void {
            $popular = array_filter($videos, fn($v) => $v['views'] > 50000);
        }, $this->iterations);

        // array_column (extract field)
        $columnTimings = $this->runBenchmark(function () use ($videos): void {
            $ids = array_column($videos, 'id');
            $indexed = array_column($videos, null, 'id');
        }, $this->iterations);

        // array_merge (combining results)
        $chunks = array_chunk($videos, 100);
        $mergeTimings = $this->runBenchmark(function () use ($chunks): void {
            $merged = array_merge(...$chunks);
        }, $this->iterations);

        // usort (custom sorting)
        $usortTimings = $this->runBenchmark(function () use ($videos): void {
            $sorted = $videos;
            usort($sorted, fn($a, $b) => $b['views'] <=> $a['views']);
        }, min(100, $this->iterations));

        $result->recordCpu('array_map', 'array_map (500 items)', $this->calculateStats($mapTimings));
        $result->recordCpu('array_filter', 'array_filter (500 items)', $this->calculateStats($filterTimings));
        $result->recordCpu('array_column', 'array_column (500 items)', $this->calculateStats($columnTimings));
        $result->recordCpu('array_merge', 'array_merge (5x100)', $this->calculateStats($mergeTimings));
        $result->recordCpu('usort', 'usort (500 items)', $this->calculateStats($usortTimings));
    }
}
