<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

use PDO;

/**
 * System metrics collection
 *
 * Collects real system information: load, memory, disk I/O
 */
class SystemBench
{
    /**
     * Collect system metrics
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $metrics = [];

        // Load average (Linux/Unix)
        $loadAvg = $this->getLoadAverage();
        if ($loadAvg !== null) {
            $metrics['load_1m'] = $loadAvg[0];
            $metrics['load_5m'] = $loadAvg[1];
            $metrics['load_15m'] = $loadAvg[2];
        }

        // Memory usage
        $memory = $this->getMemoryInfo();
        if ($memory !== []) {
            $metrics['memory_total'] = $memory['total'];
            $metrics['memory_used'] = $memory['used'];
            $metrics['memory_free'] = $memory['free'];
            $metrics['memory_percent'] = $memory['percent'];
        }

        // CPU info
        $cpuInfo = $this->getCpuInfo();
        if ($cpuInfo !== []) {
            $metrics['cpu_model'] = $cpuInfo['model'];
            $metrics['cpu_cores'] = $cpuInfo['cores'];
        }

        // Disk I/O (if available)
        $diskStats = $this->getDiskStats();
        if ($diskStats !== []) {
            $metrics['disk_read_ops'] = $diskStats['read_ops'];
            $metrics['disk_write_ops'] = $diskStats['write_ops'];
        }

        return $metrics;
    }

    /**
     * Get load average
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function getLoadAverage(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load !== false) {
                return [$load[0], $load[1], $load[2]];
            }
        }

        // Fallback for Linux
        if (file_exists('/proc/loadavg')) {
            $content = file_get_contents('/proc/loadavg');
            if ($content !== false) {
                $parts = explode(' ', $content);
                if (count($parts) >= 3) {
                    return [
                        (float)$parts[0],
                        (float)$parts[1],
                        (float)$parts[2],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Get memory information
     *
     * @return array{total: int, used: int, free: int, percent: float}|array{}
     */
    private function getMemoryInfo(): array
    {
        if (!file_exists('/proc/meminfo')) {
            return [];
        }

        $content = file_get_contents('/proc/meminfo');
        if ($content === false) {
            return [];
        }

        $memInfo = [];
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches) === 1) {
                $memInfo[$matches[1]] = (int)$matches[2] * 1024; // Convert KB to bytes
            }
        }

        if (!isset($memInfo['MemTotal'])) {
            return [];
        }

        $total = $memInfo['MemTotal'];
        $free = ($memInfo['MemFree'] ?? 0) + ($memInfo['Buffers'] ?? 0) + ($memInfo['Cached'] ?? 0);
        $used = $total - $free;

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Get CPU information
     *
     * @return array{model: string, cores: int}|array{}
     */
    private function getCpuInfo(): array
    {
        if (!file_exists('/proc/cpuinfo')) {
            return [];
        }

        $content = file_get_contents('/proc/cpuinfo');
        if ($content === false) {
            return [];
        }

        $model = 'Unknown';
        $cores = 0;

        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, 'model name')) {
                $parts = explode(':', $line, 2);
                if (isset($parts[1])) {
                    $model = trim($parts[1]);
                }
            }
            if (str_starts_with($line, 'processor')) {
                $cores++;
            }
        }

        return [
            'model' => $model,
            'cores' => $cores > 0 ? $cores : 1,
        ];
    }

    /**
     * Get disk I/O statistics
     *
     * @return array{read_ops: int, write_ops: int}|array{}
     */
    private function getDiskStats(): array
    {
        if (!file_exists('/proc/diskstats')) {
            return [];
        }

        $content = file_get_contents('/proc/diskstats');
        if ($content === false) {
            return [];
        }

        $readOps = 0;
        $writeOps = 0;

        foreach (explode("\n", $content) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if ($parts === false || count($parts) < 14) {
                continue;
            }

            // Only count actual block devices (sda, nvme0n1, etc), not partitions
            $device = $parts[2];
            if (preg_match('/^(sd[a-z]|nvme\d+n\d+|vd[a-z])$/', $device) !== 1) {
                continue;
            }

            $readOps += (int) $parts[3];
            $writeOps += (int) $parts[7];
        }

        if ($readOps === 0 && $writeOps === 0) {
            return [];
        }

        return [
            'read_ops' => $readOps,
            'write_ops' => $writeOps,
        ];
    }

    /**
     * Get database information
     *
     * @return array{db_type: string, db_version: string, db_server_info: string}|array{}
     */
    public function getDatabaseInfo(?PDO $db): array
    {
        if ($db === null) {
            return [];
        }

        try {
            $stmt = $db->query('SELECT VERSION()');
            if ($stmt === false) {
                return [];
            }

            $version = $stmt->fetchColumn();
            if (!is_string($version)) {
                return [];
            }

            // Detect MariaDB vs MySQL
            $dbType = 'mysql';
            if (stripos($version, 'mariadb') !== false) {
                $dbType = 'mariadb';
            }

            // Get server info
            $serverInfo = $db->getAttribute(PDO::ATTR_SERVER_INFO);
            if (!is_string($serverInfo)) {
                $serverInfo = $version;
            }

            return [
                'db_type' => $dbType,
                'db_version' => $version,
                'db_server_info' => $serverInfo,
            ];
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Get system info for the report header
     *
     * @return array<string, string|bool|array<int, string>>
     */
    public function getSystemInfo(): array
    {
        $hostname = gethostname();

        $info = [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'arch' => php_uname('m'),
            'hostname' => $hostname !== false ? $hostname : 'unknown',
        ];

        // PHP configuration
        $info['memory_limit'] = ini_get('memory_limit');
        $info['max_execution_time'] = ini_get('max_execution_time');

        // Relevant extensions for KVS
        $relevantExtensions = ['memcached', 'redis', 'curl', 'gd', 'imagick', 'pdo_mysql'];
        $loadedExtensions = [];
        foreach ($relevantExtensions as $ext) {
            if (extension_loaded($ext)) {
                $loadedExtensions[] = $ext;
            }
        }
        $info['extensions'] = $loadedExtensions;

        // OPcache status
        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status();
            $info['opcache'] = $status !== false;

            if ($status !== false && isset($status['jit']) && is_array($status['jit']) && isset($status['jit']['enabled'])) {
                $info['jit'] = $status['jit']['enabled'] === true;
            } else {
                $info['jit'] = false;
            }
        } else {
            $info['opcache'] = false;
            $info['jit'] = false;
        }

        // OS name
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/etc/os-release')) {
            $osRelease = parse_ini_file('/etc/os-release');
            if ($osRelease !== false && isset($osRelease['PRETTY_NAME'])) {
                $prettyName = $osRelease['PRETTY_NAME'];
                $info['os_name'] = is_scalar($prettyName) ? (string) $prettyName : 'Unknown';
            }
        }

        // Web server (if running as FPM)
        if (isset($_SERVER['SERVER_SOFTWARE']) && is_string($_SERVER['SERVER_SOFTWARE'])) {
            $info['web_server'] = $_SERVER['SERVER_SOFTWARE'];
        }

        return $info;
    }

    /**
     * Get PHP-FPM info via HTTP request
     *
     * This fetches the actual PHP configuration from the web server,
     * not the CLI configuration which can be different.
     *
     * @return array<string, string|bool|array<int, string>>
     */
    public function getSystemInfoViaHttp(string $baseUrl): array
    {
        $info = [];

        // First, try to get info from response headers
        $headerInfo = $this->getPhpInfoFromHeaders($baseUrl);
        if ($headerInfo !== []) {
            $info = array_merge($info, $headerInfo);
        }

        // Try to fetch PHP info from a KVS admin endpoint that exposes PHP version
        $adminInfo = $this->getPhpInfoFromAdmin($baseUrl);
        if ($adminInfo !== []) {
            $info = array_merge($info, $adminInfo);
        }

        // If we got PHP version from HTTP, mark it as FPM info
        if (isset($info['php_version'])) {
            $info['php_sapi'] = 'fpm (detected via HTTP)';
        }

        return $info;
    }

    /**
     * Get PHP info from HTTP response headers
     *
     * @return array<string, string>
     */
    private function getPhpInfoFromHeaders(string $baseUrl): array
    {
        if (!extension_loaded('curl')) {
            return [];
        }

        $ch = curl_init($baseUrl . '/');
        if ($ch === false) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);

        if (!is_string($response)) {
            return [];
        }

        $info = [];

        // Parse X-Powered-By header for PHP version
        if (preg_match('/X-Powered-By:\s*PHP\/([0-9.]+)/i', $response, $matches) === 1) {
            $info['php_version'] = $matches[1];
        }

        // Parse Server header for web server info
        if (preg_match('/Server:\s*([^\r\n]+)/i', $response, $matches) === 1) {
            $info['web_server'] = trim($matches[1]);
        }

        return $info;
    }

    /**
     * Get PHP info from KVS admin page (parses HTML for PHP version)
     *
     * @return array<string, string|bool>
     */
    private function getPhpInfoFromAdmin(string $baseUrl): array
    {
        if (!extension_loaded('curl')) {
            return [];
        }

        $ch = curl_init($baseUrl . '/admin/');
        if ($ch === false) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'KVS-CLI-Benchmark/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!is_string($response) || $httpCode !== 200) {
            return [];
        }

        $info = [];

        // KVS admin pages sometimes show PHP version in footer or system info
        // Look for common patterns
        if (preg_match('/PHP\s+Version[:\s]+([0-9.]+)/i', $response, $matches) === 1) {
            $info['php_version'] = $matches[1];
        }

        // Check for OPcache mentions
        if (stripos($response, 'opcache') !== false) {
            // If OPcache is mentioned, it's likely enabled
            if (preg_match('/opcache[:\s]*(enabled|on|active)/i', $response) === 1) {
                $info['opcache'] = true;
            }
        }

        return $info;
    }

    /**
     * Get combined system info (CLI + HTTP/FPM)
     *
     * Prefers HTTP-detected info for PHP version and OPcache since that's
     * what actually serves web requests.
     *
     * @return array<string, string|bool|array<int, string>>
     */
    public function getCombinedSystemInfo(string $baseUrl = ''): array
    {
        // Get CLI info as base
        $info = $this->getSystemInfo();

        // If we have a URL, try to get FPM info and override relevant fields
        if ($baseUrl !== '') {
            $httpInfo = $this->getSystemInfoViaHttp($baseUrl);

            if (isset($httpInfo['php_version'])) {
                $info['php_version'] = $httpInfo['php_version'];
                $info['php_sapi'] = 'fpm';
                $info['source'] = 'HTTP (PHP-FPM)';
            }

            if (isset($httpInfo['opcache'])) {
                $info['opcache'] = $httpInfo['opcache'];
            }

            if (isset($httpInfo['web_server'])) {
                $info['web_server'] = $httpInfo['web_server'];
            }
        }

        return $info;
    }
}
