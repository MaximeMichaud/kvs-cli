<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

/**
 * File I/O benchmark simulating KVS patterns
 *
 * Tests real KVS file operations:
 * - Serialize/unserialize for .dat config files
 * - Small file reads (language files, configs)
 * - Temp file writes (cache, sessions)
 * - Directory scanning (plugin/block discovery)
 * - Lock contention (LOCK_EX for stats logging)
 */
class FileIOBench
{
    use BenchmarkHelper;

    private int $iterations;
    private string $tempDir;
    private bool $cleanupNeeded = false;

    public function __construct(int $iterations = 100)
    {
        $this->iterations = $iterations;
        $this->tempDir = sys_get_temp_dir() . '/kvs_bench_' . uniqid();
    }

    /**
     * Run all file I/O benchmarks
     */
    public function run(BenchmarkResult $result): void
    {
        // Create temp directory for tests
        if (!$this->setupTempDir()) {
            return;
        }

        try {
            // Test 1: Serialize/Unserialize (core KVS pattern)
            $this->benchSerialize($result);

            // Test 2: Small file reads (config loading)
            $this->benchSmallReads($result);

            // Test 3: File writes (cache/temp files)
            $this->benchWrites($result);

            // Test 4: Directory listing (plugin/block scanning)
            $this->benchDirectoryListing($result);

            // Test 5: Lock contention (KVS stats logging pattern)
            $this->benchLockContention($result);
        } finally {
            // Always cleanup
            $this->cleanup();
        }
    }

    /**
     * Benchmark serialize/unserialize operations
     * KVS uses: $data = unserialize(file_get_contents('file.dat'))
     */
    private function benchSerialize(BenchmarkResult $result): void
    {
        // Simulate KVS config array (realistic structure)
        $configData = $this->generateKvsConfig();

        // Simulate language file data
        $langData = $this->generateLangData();

        $serializeTimings = [];
        $unserializeTimings = [];
        $configTimings = [];
        $langTimings = [];

        // Test config serialization (small, frequently accessed)
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            $serialized = serialize($configData);
            $serializeTimings[] = (microtime(true) - $start) * 1000;

            $start = microtime(true);
            $unserialized = unserialize($serialized);
            $unserializeTimings[] = (microtime(true) - $start) * 1000;
        }

        // Test language file pattern (larger, loaded on every page)
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            $serialized = serialize($langData);
            $langTimings[] = (microtime(true) - $start) * 1000;

            $start = microtime(true);
            unserialize($serialized);
            $langTimings[] = (microtime(true) - $start) * 1000;
        }

        // Test full KVS pattern: file_get_contents + unserialize
        $testFile = $this->tempDir . '/test_config.dat';
        file_put_contents($testFile, serialize($configData));

        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            $content = file_get_contents($testFile);
            if ($content !== false) {
                $data = unserialize($content);
            }
            $configTimings[] = (microtime(true) - $start) * 1000;
        }

        $result->recordFileIO('serialize', 'Serialize Config', $this->calculateFileStats($serializeTimings));
        $result->recordFileIO('unserialize', 'Unserialize Config', $this->calculateFileStats($unserializeTimings));
        $result->recordFileIO('lang_serialize', 'Serialize Lang (~500 strings)', $this->calculateFileStats($langTimings));
        $result->recordFileIO('config_load', 'Config Load (read+unserialize)', $this->calculateFileStats($configTimings));
    }

    /**
     * Benchmark small file reads (typical KVS includes)
     */
    private function benchSmallReads(BenchmarkResult $result): void
    {
        // Create test files of various sizes
        $files = [
            'tiny' => str_repeat('x', 1024),        // 1KB
            'small' => str_repeat('x', 10240),      // 10KB
            'medium' => str_repeat('x', 102400),    // 100KB
        ];

        foreach ($files as $size => $content) {
            $file = $this->tempDir . "/read_{$size}.txt";
            file_put_contents($file, $content);
        }

        $timings = [
            'tiny' => [],
            'small' => [],
            'medium' => [],
        ];

        // Benchmark reads
        for ($i = 0; $i < $this->iterations; $i++) {
            foreach ($files as $size => $content) {
                $file = $this->tempDir . "/read_{$size}.txt";

                $start = microtime(true);
                $data = file_get_contents($file);
                $timings[$size][] = (microtime(true) - $start) * 1000;
            }
        }

        $result->recordFileIO('read_1kb', 'Read 1KB file', $this->calculateFileStats($timings['tiny']));
        $result->recordFileIO('read_10kb', 'Read 10KB file', $this->calculateFileStats($timings['small']));
        $result->recordFileIO('read_100kb', 'Read 100KB file', $this->calculateFileStats($timings['medium']));
    }

    /**
     * Benchmark file writes (cache/temp files)
     */
    private function benchWrites(BenchmarkResult $result): void
    {
        $content = str_repeat('Sample cache content. ', 500); // ~10KB
        $timings = [];
        $syncTimings = [];

        // Test buffered writes (typical)
        for ($i = 0; $i < $this->iterations; $i++) {
            $file = $this->tempDir . "/write_test_{$i}.tmp";

            $start = microtime(true);
            file_put_contents($file, $content);
            $timings[] = (microtime(true) - $start) * 1000;
        }

        // Test write + fsync (critical data)
        for ($i = 0; $i < min(50, $this->iterations); $i++) {
            $file = $this->tempDir . "/sync_test_{$i}.tmp";

            $start = microtime(true);
            $fp = fopen($file, 'w');
            if ($fp !== false) {
                fwrite($fp, $content);
                fflush($fp);
                fsync($fp);
                fclose($fp);
            }
            $syncTimings[] = (microtime(true) - $start) * 1000;
        }

        $result->recordFileIO('write_10kb', 'Write 10KB file', $this->calculateFileStats($timings));
        $result->recordFileIO('write_sync', 'Write + fsync', $this->calculateFileStats($syncTimings));
    }

    /**
     * Benchmark directory listing (plugin/block scanning)
     */
    private function benchDirectoryListing(BenchmarkResult $result): void
    {
        // Create directory structure similar to KVS
        $blockDir = $this->tempDir . '/blocks';
        mkdir($blockDir);

        // Create 65 dummy files (KVS has ~65 blocks)
        for ($i = 1; $i <= 65; $i++) {
            touch($blockDir . "/block_{$i}.php");
        }

        $scanTimings = [];
        $globTimings = [];
        $statTimings = [];

        // Test scandir (basic listing)
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            $files = scandir($blockDir);
            $scanTimings[] = (microtime(true) - $start) * 1000;
        }

        // Test glob (pattern matching)
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            $files = glob($blockDir . '/*.php');
            $globTimings[] = (microtime(true) - $start) * 1000;
        }

        // Test stat operations (checking file times)
        $filesForStat = glob($blockDir . '/*.php');
        if ($filesForStat === false) {
            $filesForStat = [];
        }
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = microtime(true);
            foreach ($filesForStat as $file) {
                $mtime = filemtime($file);
            }
            $statTimings[] = (microtime(true) - $start) * 1000;
        }

        $result->recordFileIO('scandir', 'scandir() 65 files', $this->calculateFileStats($scanTimings));
        $result->recordFileIO('glob', 'glob() pattern match', $this->calculateFileStats($globTimings));
        $result->recordFileIO('stat', 'filemtime() 65 files', $this->calculateFileStats($statTimings));
    }

    /**
     * Benchmark file lock contention (KVS stats logging pattern)
     *
     * KVS pattern from player/stats.php:
     * file_put_contents($file, $data, FILE_APPEND | LOCK_EX);
     *
     * This tests the overhead of exclusive locking on append operations,
     * simulating what happens when many concurrent requests log stats.
     */
    private function benchLockContention(BenchmarkResult $result): void
    {
        $statsFile = $this->tempDir . '/stats_log.dat';
        $logEntry = date('Y-m-d H:i:s') . '|view|video_123|user_456|' . "\n";

        // Initialize file
        file_put_contents($statsFile, '');

        // Append without lock
        $noLockTimings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = $this->startTimer();
            file_put_contents($statsFile, $logEntry, FILE_APPEND);
            $noLockTimings[] = $this->stopTimer($start);
        }

        // Reset file
        file_put_contents($statsFile, '');

        // Append with LOCK_EX (what KVS uses)
        $lockTimings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = $this->startTimer();
            file_put_contents($statsFile, $logEntry, FILE_APPEND | LOCK_EX);
            $lockTimings[] = $this->stopTimer($start);
        }

        // Also test flock pattern (more control)
        file_put_contents($statsFile, '');
        $flockTimings = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $start = $this->startTimer();
            $fp = fopen($statsFile, 'a');
            if ($fp !== false) {
                flock($fp, LOCK_EX);
                fwrite($fp, $logEntry);
                flock($fp, LOCK_UN);
                fclose($fp);
            }
            $flockTimings[] = $this->stopTimer($start);
        }

        $result->recordFileIO('append_no_lock', 'Append (no lock)', $this->calculateFileStats($noLockTimings));
        $result->recordFileIO('append_lock_ex', 'Append (LOCK_EX)', $this->calculateFileStats($lockTimings));
        $result->recordFileIO('append_flock', 'Append (flock)', $this->calculateFileStats($flockTimings));
    }

    /**
     * Generate realistic KVS config array
     *
     * @return array<string, mixed>
     */
    private function generateKvsConfig(): array
    {
        return [
            'project_path' => '/var/www/kvs',
            'project_url' => 'https://example.com',
            'project_version' => '6.3.2',
            'content_url_videos_sources' => 'https://cdn.example.com/videos',
            'cv' => md5('test_key'),
            'locale' => 'english',
            'cache_ttl' => 300,
            'stats_enable' => 1,
            'blocks_cache_enable' => 1,
            'videos_per_page' => 20,
            'albums_per_page' => 30,
            'categories' => [
                ['id' => 1, 'title' => 'Category 1'],
                ['id' => 2, 'title' => 'Category 2'],
                ['id' => 3, 'title' => 'Category 3'],
            ],
            'admin_user' => [
                'id' => 1,
                'username' => 'admin',
                'permissions' => ['videos', 'users', 'settings'],
            ],
        ];
    }

    /**
     * Generate realistic language data (KVS loads ~500 strings)
     *
     * @return array<string, string>
     */
    private function generateLangData(): array
    {
        $data = [];
        for ($i = 1; $i <= 500; $i++) {
            $data["lang_key_{$i}"] = "Translated string number {$i} with some text content";
        }
        return $data;
    }

    /**
     * Calculate statistics from timings for file I/O results
     *
     * @param array<int, float> $timings
     * @return array{avg: float, min: float, max: float, ops_sec: float, samples: int}
     */
    private function calculateFileStats(array $timings): array
    {
        if ($timings === []) {
            return [
                'avg' => 0.0,
                'min' => 0.0,
                'max' => 0.0,
                'ops_sec' => 0.0,
                'samples' => 0,
            ];
        }

        $count = count($timings);
        $avg = array_sum($timings) / $count;

        return [
            'avg' => round($avg, 4),
            'min' => round(min($timings), 4),
            'max' => round(max($timings), 4),
            'ops_sec' => $avg > 0 ? round(1000 / $avg, 2) : 0,
            'samples' => $count,
        ];
    }

    /**
     * Setup temporary directory for tests
     */
    private function setupTempDir(): bool
    {
        if (!mkdir($this->tempDir, 0700, true)) {
            return false;
        }
        $this->cleanupNeeded = true;
        return true;
    }

    /**
     * Clean up temporary files and directories
     */
    private function cleanup(): void
    {
        if (!$this->cleanupNeeded || !is_dir($this->tempDir)) {
            return;
        }

        // Remove all files and subdirectories
        $this->removeDirectory($this->tempDir);
        $this->cleanupNeeded = false;
    }

    /**
     * Recursively remove directory and contents
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $scanned = scandir($dir);
        $files = array_diff($scanned !== false ? $scanned : [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Get temp directory path (for testing)
     */
    public function getTempDir(): string
    {
        return $this->tempDir;
    }
}
