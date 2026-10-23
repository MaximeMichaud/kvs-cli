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
        $dbConfig = $this->config->getDatabaseConfig();
        $dbConfig['host'] = $dbConfig['_original_host'] ?? $dbConfig['host'] ?? '127.0.0.1';
        $mcHost = $this->config->get('memcache_server', '127.0.0.1');
        $mcPort = $this->config->get('memcache_port', 11211);
        $settings = var_export([
            'token' => $token,
            'timeout' => $this->timeout,
            'database' => $dbConfig,
            'prefix' => $this->config->getTablePrefix(),
            'cache' => [
                'host' => is_string($mcHost) ? $mcHost : '127.0.0.1',
                'port' => is_numeric($mcPort) ? (int) $mcPort : 11211,
            ],
            'cpu_iterations' => $this->cpuIterations,
            'cache_iterations' => $this->cacheIterations,
            'file_iterations' => $this->fileIterations,
            'db_iterations' => $this->dbIterations,
        ], true);

        $sources = $this->getBenchmarkSources();

        return <<<PHP
<?php
// Generated from the same workload classes used by CLI execution.
declare(strict_types=1);
namespace {
\$settings = {$settings};
if (!is_string(\$_GET['t'] ?? null) || !hash_equals(\$settings['token'], \$_GET['t'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    return;
}
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
\$originalMaxExecTime = ini_get('max_execution_time');
\$originalMemoryLimit = ini_get('memory_limit');
set_time_limit(\$settings['timeout']);
ini_set('display_errors', '0');
}
{$sources}
namespace {
\$startTime = microtime(true);
\$result = new \\KVS\\CLI\\BenchmarkRuntime\\Benchmark\\BenchmarkResult();
\$opcacheStatus = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
\$response = [
    'php_info' => [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'opcache_enabled' => is_array(\$opcacheStatus),
        'jit_enabled' => is_array(\$opcacheStatus) && (\$opcacheStatus['jit']['enabled'] ?? false),
        'memory_limit' => \$originalMemoryLimit,
        'max_execution_time' => \$originalMaxExecTime,
        'server_software' => \$_SERVER['SERVER_SOFTWARE'] ?? '',
    ],
    'cache' => ['connected' => false, 'results' => []],
    'database' => ['connected' => false, 'results' => []],
];
try {
    (new \\KVS\\CLI\\BenchmarkRuntime\\Benchmark\\CpuBench(\$settings['cpu_iterations']))->run(\$result);
    (new \\KVS\\CLI\\BenchmarkRuntime\\Benchmark\\FileIOBench(\$settings['file_iterations']))->run(\$result);
    \$cache = new \\KVS\\CLI\\BenchmarkRuntime\\Benchmark\\CacheBench(\$settings['cache'], \$settings['cache_iterations']);
    if (\$cache->connect()) {
        try {
            \$response['cache']['connected'] = true;
            \$response['cache']['type'] = \$cache->detectBackendType();
            \$response['cache']['version'] = \$cache->getCacheVersion();
            \$cache->run(\$result);
        } finally {
            \$cache->close();
        }
    }
    \$dbConfig = \$settings['database'];
    if ((\$dbConfig['database'] ?? '') !== '' && (\$dbConfig['user'] ?? '') !== '') {
        \$dsn = 'mysql:host=' . \$dbConfig['host'] . ';dbname=' . \$dbConfig['database'] . ';charset=utf8mb4';
        \$pdo = new PDO(\$dsn, \$dbConfig['user'], \$dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        \$version = (string) \$pdo->query('SELECT VERSION()')->fetchColumn();
        \$response['database']['connected'] = true;
        \$response['database']['version'] = \$version;
        \$response['database']['type'] = stripos(\$version, 'mariadb') !== false ? 'mariadb' : 'mysql';
        (new \\KVS\\CLI\\BenchmarkRuntime\\Benchmark\\DatabaseBench(
            \$pdo, \$settings['prefix'], \$settings['db_iterations']
        ))->run(\$result);
    }
} catch (Throwable \$e) {
    \$response['error'] = \$e->getMessage();
} finally {
    \\KVS\\CLI\\BenchmarkRuntime\\Service\\TempFileManager::cleanupAll();
}
\$response['cpu'] = \$result->getCpuResults();
\$response['fileio'] = \$result->getFileIOResults();
\$response['cache']['results'] = \$result->getCacheResults();
\$response['database']['results'] = \$result->getDbResults();
\$response['execution_time'] = microtime(true) - \$startTime;
echo json_encode(\$response, JSON_THROW_ON_ERROR);
}
PHP;
    }

    /**
     * Embed trusted source files directly, without eval or a server-side autoloader.
     */
    private function getBenchmarkSources(): string
    {
        $files = [
            '../Constants.php',
            '../Service/TempFileManager.php',
            'BenchmarkHelper.php',
            'BenchmarkResult.php',
            'CpuBench.php',
            'CacheBench.php',
            'FileIOBench.php',
            'DatabaseBench.php',
        ];
        $sources = '';
        foreach ($files as $file) {
            $source = file_get_contents(__DIR__ . '/' . $file);
            if ($source === false) {
                throw new \RuntimeException('Unable to load benchmark workload: ' . $file);
            }
            $source = str_replace('KVS\\CLI', 'KVS\\CLI\\BenchmarkRuntime', $source);
            $source = preg_replace(
                '/\A<\?php\s*(?:declare\(strict_types=1\);\s*)?namespace ([^;]+);/',
                'namespace $1 {',
                $source,
                1,
                $count
            );
            if ($source === null || $count !== 1) {
                throw new \RuntimeException('Unable to embed benchmark workload: ' . $file);
            }
            $sources .= $source . "\n}\n";
        }
        return $sources;
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
