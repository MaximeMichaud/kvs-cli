<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

use KVS\CLI\Config\Configuration;

/**
 * Executes benchmarks on the KVS server via temporary PHP file.
 *
 * This allows benchmarks to run in the actual PHP-FPM environment
 * (with OPcache, JIT, and real server configuration) rather than CLI.
 *
 * Security:
 * - Random 32-char filename (impossible to guess)
 * - Random token required in query string
 * - File deleted immediately after execution
 * - Timeout protection
 */
class RemoteBenchmarkClient
{
    private Configuration $config;
    private ?string $lastError = null;

    // Benchmark iterations (reduced for server-side to avoid timeout)
    private int $cpuIterations = 500;
    private int $cacheIterations = 50;
    private int $fileIterations = 50;
    private int $dbIterations = 5;

    // Timeout for remote execution (seconds)
    private int $timeout = 120;

    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

    /**
     * Set benchmark iterations.
     */
    public function setIterations(int $cpu, int $cache, int $file, int $db): self
    {
        $this->cpuIterations = $cpu;
        $this->cacheIterations = $cache;
        $this->fileIterations = $file;
        $this->dbIterations = $db;
        return $this;
    }

    /**
     * Set execution timeout in seconds.
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(30, min(300, $timeout));
        return $this;
    }

    /**
     * Run benchmarks on the server and return results.
     *
     * Returns array with:
     * - success: bool - whether execution succeeded
     * - source: string - 'fpm' or 'error'
     * - error?: string - error message if failed
     * - php_info?: array - PHP info from server
     * - cpu?: array - CPU benchmark results
     * - cache?: array - cache benchmark results
     * - fileio?: array - file I/O benchmark results
     * - database?: array - database benchmark results
     * - execution_time?: float - total execution time
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $this->lastError = null;

        $kvsPath = $this->config->getKvsPath();
        $projectUrl = $this->config->get('project_url');

        if (!is_string($projectUrl) || $projectUrl === '') {
            $this->lastError = 'project_url not configured in KVS';
            return ['success' => false, 'source' => 'error', 'error' => $this->lastError];
        }

        // Generate secure random filename and token
        try {
            $randomBytes = random_bytes(16);
            $filename = 'kvs_bench_' . bin2hex($randomBytes) . '.php';
            $token = bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            $this->lastError = 'Failed to generate random token: ' . $e->getMessage();
            return ['success' => false, 'source' => 'error', 'error' => $this->lastError];
        }

        $filepath = $kvsPath . '/' . $filename;

        // Generate and write benchmark code
        $phpCode = $this->generateBenchmarkCode($token);
        if (file_put_contents($filepath, $phpCode) === false) {
            $this->lastError = 'Failed to write benchmark file: ' . $filepath;
            return ['success' => false, 'source' => 'error', 'error' => $this->lastError];
        }

        // Make file readable by web server
        chmod($filepath, 0644);

        try {
            // Execute via HTTP
            $url = rtrim($projectUrl, '/') . '/' . $filename . '?t=' . $token;
            $response = $this->httpGet($url);

            if ($response === null) {
                return ['success' => false, 'source' => 'error', 'error' => $this->lastError ?? 'HTTP request failed'];
            }

            // Parse JSON response
            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                $this->lastError = 'Invalid JSON response from server';
                return ['success' => false, 'source' => 'error', 'error' => $this->lastError];
            }

            // Check for server-side error
            if (isset($decoded['error']) && is_string($decoded['error'])) {
                $this->lastError = 'Server error: ' . $decoded['error'];
                return ['success' => false, 'source' => 'error', 'error' => $this->lastError];
            }

            $decoded['success'] = true;
            $decoded['source'] = 'fpm';

            /** @var array<string, mixed> $decoded */
            return $decoded;
        } finally {
            // Always delete temp file
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

    /**
     * Check if remote execution is available.
     */
    public function isAvailable(): bool
    {
        $kvsPath = $this->config->getKvsPath();
        $projectUrl = $this->config->get('project_url');

        if ($kvsPath === '' || !is_dir($kvsPath) || !is_writable($kvsPath)) {
            return false;
        }

        if (!is_string($projectUrl) || $projectUrl === '') {
            return false;
        }

        return true;
    }

    /**
     * Get last error message.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Generate the benchmark PHP code to run on the server.
     */
    private function generateBenchmarkCode(string $token): string
    {
        $cpuIter = $this->cpuIterations;
        $cacheIter = $this->cacheIterations;
        $fileIter = $this->fileIterations;
        $dbIter = $this->dbIterations;
        $timeout = $this->timeout;

        // Get database config from KVS
        $dbConfig = $this->config->getDatabaseConfig();
        // Use original host if fallback was applied (Docker: host sees 127.0.0.1 but FPM sees container name)
        $originalHost = $dbConfig['_original_host'] ?? null;
        $dbHost = is_string($originalHost) && $originalHost !== ''
            ? addslashes($originalHost)
            : addslashes($dbConfig['host'] ?? '127.0.0.1');
        $dbUser = addslashes($dbConfig['user'] ?? '');
        $dbPass = addslashes($dbConfig['password'] ?? '');
        $dbName = addslashes($dbConfig['database'] ?? '');
        $tablePrefix = addslashes($this->config->getTablePrefix());

        // Get memcached config
        $mcHost = $this->config->get('memcache_server', '127.0.0.1');
        $mcHost = is_string($mcHost) ? addslashes($mcHost) : '127.0.0.1';
        $mcPort = $this->config->get('memcache_port', 11211);
        $mcPort = is_int($mcPort) ? $mcPort : 11211;

        return <<<PHP
<?php
/**
 * KVS CLI Remote Benchmark
 * Auto-generated and auto-deleted after execution.
 */

// Security: Validate token
if ((\$_GET['t'] ?? '') !== '{$token}') {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Forbidden']));
}

// Prevent caching
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Set execution limits
set_time_limit({$timeout});
ini_set('memory_limit', '256M');
error_reporting(E_ALL);
ini_set('display_errors', '0');

\$startTime = microtime(true);
\$results = [
    'php_info' => [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'opcache_enabled' => function_exists('opcache_get_status') && is_array(@opcache_get_status(false)),
        'jit_enabled' => false,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => (int) ini_get('max_execution_time'),
    ],
    'cpu' => [],
    'cache' => ['connected' => false, 'results' => []],
    'fileio' => [],
    'database' => ['connected' => false, 'results' => []],
];

// Check JIT
if (function_exists('opcache_get_status')) {
    \$status = @opcache_get_status(false);
    if (is_array(\$status) && isset(\$status['jit']['enabled'])) {
        \$results['php_info']['jit_enabled'] = (bool) \$status['jit']['enabled'];
    }
}

// ============================================================
// CPU BENCHMARKS
// ============================================================

function runCpuBenchmarks(int \$iterations): array {
    \$results = [];

    // MD5 hashing (100 strings per iteration)
    \$secretKey = 'kvs_cryptographic_verification_key_12345';
    \$paths = [];
    for (\$i = 0; \$i < 100; \$i++) {
        \$paths[] = "/content/videos/{\$i}/video_{\$i}_720p.mp4";
    }

    \$timings = [];
    for (\$i = 0; \$i < \$iterations; \$i++) {
        \$start = hrtime(true);
        foreach (\$paths as \$path) {
            \$hash = md5(\$secretKey . \$path);
        }
        \$timings[] = (hrtime(true) - \$start) / 1_000_000;
    }
    \$results['md5_simple'] = calcStats(\$timings, 'MD5 Hash (100 strings)');

    // Serialize config
    \$configData = [
        'project_path' => '/var/www/kvs',
        'project_url' => 'https://example.com',
        'version' => '6.3.2',
        'categories' => array_map(fn(\$i) => ['id' => \$i, 'title' => "Cat \$i"], range(1, 50)),
    ];

    \$timings = [];
    for (\$i = 0; \$i < \$iterations; \$i++) {
        \$start = hrtime(true);
        \$s = serialize(\$configData);
        \$d = unserialize(\$s);
        \$timings[] = (hrtime(true) - \$start) / 1_000_000;
    }
    \$results['serialize_config'] = calcStats(\$timings, 'Serialize Config');

    // JSON encode/decode
    \$timings = [];
    for (\$i = 0; \$i < \$iterations; \$i++) {
        \$start = hrtime(true);
        \$j = json_encode(\$configData);
        \$d = json_decode(\$j, true);
        \$timings[] = (hrtime(true) - \$start) / 1_000_000;
    }
    \$results['json_config'] = calcStats(\$timings, 'JSON Config');

    // String operations
    \$template = '<div class="video-item">{TITLE}</div><span>{VIEWS} views</span>';
    \$timings = [];
    for (\$i = 0; \$i < \$iterations; \$i++) {
        \$start = hrtime(true);
        for (\$j = 0; \$j < 100; \$j++) {
            \$output = str_replace(['{TITLE}', '{VIEWS}'], ["Video \$j", (string)(\$j * 1000)], \$template);
        }
        \$timings[] = (hrtime(true) - \$start) / 1_000_000;
    }
    \$results['str_replace'] = calcStats(\$timings, 'str_replace (100x)');

    // Regex
    \$urls = ['/videos/123/my-video', '/categories/popular/page/5', '/user/profile/john'];
    \$timings = [];
    for (\$i = 0; \$i < \$iterations; \$i++) {
        \$start = hrtime(true);
        foreach (\$urls as \$url) {
            preg_match('#^/videos/(\\d+)/([a-z0-9-]+)\$#', \$url, \$m);
            preg_match('#^/categories/([a-z]+)/page/(\\d+)\$#', \$url, \$m);
        }
        \$timings[] = (hrtime(true) - \$start) / 1_000_000;
    }
    \$results['regex_routing'] = calcStats(\$timings, 'Regex Routing');

    // Array operations
    \$videos = array_map(fn(\$i) => ['id' => \$i, 'title' => "Video \$i", 'views' => mt_rand(100, 100000)], range(1, 500));

    \$timings = [];
    for (\$i = 0; \$i < \$iterations; \$i++) {
        \$start = hrtime(true);
        \$titles = array_map(fn(\$v) => \$v['title'], \$videos);
        \$timings[] = (hrtime(true) - \$start) / 1_000_000;
    }
    \$results['array_map'] = calcStats(\$timings, 'array_map (500 items)');

    \$timings = [];
    for (\$i = 0; \$i < \$iterations; \$i++) {
        \$start = hrtime(true);
        \$popular = array_filter(\$videos, fn(\$v) => \$v['views'] > 50000);
        \$timings[] = (hrtime(true) - \$start) / 1_000_000;
    }
    \$results['array_filter'] = calcStats(\$timings, 'array_filter (500 items)');

    return \$results;
}

// ============================================================
// CACHE BENCHMARKS
// ============================================================

function runCacheBenchmarks(string \$host, int \$port, int \$iterations): array {
    \$result = ['connected' => false, 'type' => 'unknown', 'version' => 'unknown', 'results' => []];

    if (!class_exists('Memcached')) {
        return \$result;
    }

    try {
        \$mc = new Memcached();
        \$mc->addServer(\$host, \$port);
        \$mc->setOption(Memcached::OPT_CONNECT_TIMEOUT, 1000);

        // Test connection
        \$mc->set('_kvs_bench_test', '1', 1);
        if (\$mc->getResultCode() !== Memcached::RES_SUCCESS) {
            return \$result;
        }

        \$result['connected'] = true;

        // Get version
        \$stats = \$mc->getStats();
        \$server = "\$host:\$port";
        \$result['version'] = \$stats[\$server]['version'] ?? 'unknown';

        // Detect type (Dragonfly vs Memcached)
        \$version = \$result['version'];
        if (stripos(\$version, 'dragonfly') !== false || preg_match('/^v\\d+\\.\\d+/', \$version)) {
            \$result['type'] = 'dragonfly';
        } else {
            \$result['type'] = 'memcached';
        }

        // Simple SET
        \$timings = [];
        for (\$i = 0; \$i < \$iterations; \$i++) {
            \$start = hrtime(true);
            \$mc->set('kvs_bench_' . \$i, 'test_value_' . \$i, 60);
            \$timings[] = (hrtime(true) - \$start) / 1_000_000;
        }
        \$result['results']['simple_set'] = calcStats(\$timings, 'Simple SET');

        // Simple GET
        \$timings = [];
        for (\$i = 0; \$i < \$iterations; \$i++) {
            \$start = hrtime(true);
            \$mc->get('kvs_bench_' . \$i);
            \$timings[] = (hrtime(true) - \$start) / 1_000_000;
        }
        \$result['results']['simple_get'] = calcStats(\$timings, 'Simple GET');

        // Large value (~50KB)
        \$largeValue = str_repeat('Lorem ipsum dolor sit amet. ', 2000);
        \$timings = [];
        for (\$i = 0; \$i < min(20, \$iterations); \$i++) {
            \$start = hrtime(true);
            \$mc->set('kvs_bench_large_' . \$i, \$largeValue, 60);
            \$timings[] = (hrtime(true) - \$start) / 1_000_000;
        }
        \$result['results']['page_set'] = calcStats(\$timings, 'Page SET (~50KB)');

        \$mc->quit();

    } catch (Exception \$e) {
        \$result['error'] = \$e->getMessage();
    }

    return \$result;
}

// ============================================================
// FILE I/O BENCHMARKS
// ============================================================

function runFileIOBenchmarks(int \$iterations): array {
    \$results = [];
    \$tempDir = sys_get_temp_dir() . '/kvs_bench_' . uniqid();
    @mkdir(\$tempDir, 0755, true);

    // Serialize write/read
    \$configData = [
        'project_path' => '/var/www/kvs',
        'settings' => ['videos_per_page' => 20, 'cache_ttl' => 300],
        'categories' => array_map(fn(\$i) => ['id' => \$i, 'title' => "Cat \$i"], range(1, 50)),
    ];

    \$testFile = \$tempDir . '/test_config.dat';
    file_put_contents(\$testFile, serialize(\$configData));

    \$timings = [];
    for (\$i = 0; \$i < \$iterations; \$i++) {
        \$start = hrtime(true);
        \$content = file_get_contents(\$testFile);
        \$data = unserialize(\$content);
        \$timings[] = (hrtime(true) - \$start) / 1_000_000;
    }
    \$results['config_load'] = calcStats(\$timings, 'Config Load (read+unserialize)');

    // Write 10KB
    \$content = str_repeat('Sample content. ', 500);
    \$timings = [];
    for (\$i = 0; \$i < \$iterations; \$i++) {
        \$file = \$tempDir . "/write_test_{\$i}.tmp";
        \$start = hrtime(true);
        file_put_contents(\$file, \$content);
        \$timings[] = (hrtime(true) - \$start) / 1_000_000;
    }
    \$results['write_10kb'] = calcStats(\$timings, 'Write 10KB file');

    // Read 10KB
    \$testReadFile = \$tempDir . '/read_test.txt';
    file_put_contents(\$testReadFile, \$content);
    \$timings = [];
    for (\$i = 0; \$i < \$iterations; \$i++) {
        \$start = hrtime(true);
        \$data = file_get_contents(\$testReadFile);
        \$timings[] = (hrtime(true) - \$start) / 1_000_000;
    }
    \$results['read_10kb'] = calcStats(\$timings, 'Read 10KB file');

    // Cleanup
    \$files = glob(\$tempDir . '/*');
    if (\$files) {
        foreach (\$files as \$f) @unlink(\$f);
    }
    @rmdir(\$tempDir);

    return \$results;
}

// ============================================================
// DATABASE BENCHMARKS
// ============================================================

function runDatabaseBenchmarks(string \$host, string \$user, string \$pass, string \$dbname, string \$prefix, int \$iterations): array {
    \$result = ['connected' => false, 'type' => 'unknown', 'version' => 'unknown', 'results' => []];

    if (\$dbname === '' || \$user === '') {
        \$result['error'] = 'Missing database credentials (host=' . \$host . ', user=' . (\$user ?: 'empty') . ', db=' . (\$dbname ?: 'empty') . ')';
        return \$result;
    }

    try {
        \$dsn = "mysql:host={\$host};dbname={\$dbname};charset=utf8mb4";
        \$pdo = new PDO(\$dsn, \$user, \$pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        \$result['connected'] = true;

        // Get version
        \$stmt = \$pdo->query('SELECT VERSION() as v');
        \$row = \$stmt->fetch();
        \$result['version'] = \$row['v'] ?? 'unknown';
        \$result['type'] = stripos(\$result['version'], 'mariadb') !== false ? 'mariadb' : 'mysql';

        // Check if videos table exists
        \$videosTable = \$prefix . 'videos';
        try {
            \$pdo->query("SELECT 1 FROM {\$videosTable} LIMIT 1");
        } catch (PDOException \$e) {
            return \$result;
        }

        // Video listing
        \$query = "SELECT video_id, title, added_date, video_viewed, rating, duration, status_id
                   FROM {\$videosTable} WHERE status_id = 1 ORDER BY added_date DESC LIMIT 20";
        \$timings = [];
        for (\$i = 0; \$i < \$iterations; \$i++) {
            \$start = hrtime(true);
            \$stmt = \$pdo->query(\$query);
            \$stmt->fetchAll();
            \$timings[] = (hrtime(true) - \$start) / 1_000_000;
        }
        \$result['results']['video_listing'] = calcDbStats(\$timings, 'Video Listing (20 items)');

        // Video count
        \$query = "SELECT COUNT(*) FROM {\$videosTable} WHERE status_id = 1";
        \$timings = [];
        for (\$i = 0; \$i < \$iterations; \$i++) {
            \$start = hrtime(true);
            \$stmt = \$pdo->query(\$query);
            \$stmt->fetchColumn();
            \$timings[] = (hrtime(true) - \$start) / 1_000_000;
        }
        \$result['results']['video_count'] = calcDbStats(\$timings, 'Video Count Query');

        // LIKE search
        \$query = "SELECT video_id, title FROM {\$videosTable} WHERE status_id = 1 AND title LIKE '%test%' LIMIT 20";
        \$timings = [];
        for (\$i = 0; \$i < \$iterations; \$i++) {
            \$start = hrtime(true);
            \$stmt = \$pdo->query(\$query);
            \$stmt->fetchAll();
            \$timings[] = (hrtime(true) - \$start) / 1_000_000;
        }
        \$result['results']['search'] = calcDbStats(\$timings, 'LIKE Search Query');

        // INSERT (temp table)
        \$tempTable = \$prefix . 'bench_temp_' . uniqid();
        \$pdo->exec("CREATE TEMPORARY TABLE {\$tempTable} (id INT AUTO_INCREMENT PRIMARY KEY, data VARCHAR(255))");
        \$stmt = \$pdo->prepare("INSERT INTO {\$tempTable} (data) VALUES (?)");
        \$timings = [];
        for (\$i = 0; \$i < \$iterations * 5; \$i++) {
            \$start = hrtime(true);
            \$stmt->execute(['benchmark_data_' . \$i]);
            \$timings[] = (hrtime(true) - \$start) / 1_000_000;
        }
        \$result['results']['insert'] = calcDbStats(\$timings, 'INSERT (temp table)');

    } catch (PDOException \$e) {
        \$result['error'] = \$e->getMessage();
    }

    return \$result;
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function calcStats(array \$timings, string \$name): array {
    if (empty(\$timings)) {
        return ['name' => \$name, 'avg' => 0, 'min' => 0, 'max' => 0, 'p50' => 0, 'p95' => 0, 'std_dev' => 0, 'ops_sec' => 0, 'samples' => 0];
    }

    sort(\$timings);
    \$count = count(\$timings);
    \$sum = array_sum(\$timings);
    \$avg = \$sum / \$count;

    \$squaredDiffs = array_map(fn(\$t) => (\$t - \$avg) ** 2, \$timings);
    \$variance = array_sum(\$squaredDiffs) / \$count;
    \$stdDev = sqrt(\$variance);

    \$p50idx = max(0, min(\$count - 1, (int) ceil(0.50 * \$count) - 1));
    \$p95idx = max(0, min(\$count - 1, (int) ceil(0.95 * \$count) - 1));

    return [
        'name' => \$name,
        'avg' => round(\$avg, 4),
        'min' => round(\$timings[0], 4),
        'max' => round(\$timings[\$count - 1], 4),
        'p50' => round(\$timings[\$p50idx], 4),
        'p95' => round(\$timings[\$p95idx], 4),
        'std_dev' => round(\$stdDev, 4),
        'ops_sec' => \$avg > 0 ? round(1000 / \$avg, 2) : 0,
        'samples' => \$count,
    ];
}

function calcDbStats(array \$timings, string \$name): array {
    if (empty(\$timings)) {
        return ['name' => \$name, 'avg_ms' => 0, 'queries_sec' => 0, 'samples' => 0];
    }

    \$count = count(\$timings);
    \$avg = array_sum(\$timings) / \$count;

    return [
        'name' => \$name,
        'avg_ms' => round(\$avg, 4),
        'queries_sec' => \$avg > 0 ? round(1000 / \$avg, 2) : 0,
        'samples' => \$count,
    ];
}

// ============================================================
// EXECUTE BENCHMARKS
// ============================================================

try {
    // CPU benchmarks
    \$results['cpu'] = runCpuBenchmarks({$cpuIter});

    // Cache benchmarks
    \$results['cache'] = runCacheBenchmarks('{$mcHost}', {$mcPort}, {$cacheIter});

    // File I/O benchmarks
    \$results['fileio'] = runFileIOBenchmarks({$fileIter});

    // Database benchmarks
    \$results['database'] = runDatabaseBenchmarks('{$dbHost}', '{$dbUser}', '{$dbPass}', '{$dbName}', '{$tablePrefix}', {$dbIter});

    \$results['execution_time'] = round(microtime(true) - \$startTime, 2);

} catch (Throwable \$e) {
    \$results['error'] = \$e->getMessage();
}

echo json_encode(\$results, JSON_PRETTY_PRINT);
PHP;
    }

    /**
     * Make HTTP GET request with timeout.
     */
    private function httpGet(string $url): ?string
    {
        // Try cURL first
        if (function_exists('curl_init') && $url !== '') {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_USERAGENT, 'KVS-CLI-Benchmark/1.0');

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                $this->lastError = "HTTP request failed (code {$httpCode}): {$error}";
                return null;
            }

            return is_string($response) ? $response : null;
        }

        // Fallback to file_get_contents
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'user_agent' => 'KVS-CLI-Benchmark/1.0',
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->lastError = 'HTTP request failed (file_get_contents)';
            return null;
        }

        return $response;
    }
}
