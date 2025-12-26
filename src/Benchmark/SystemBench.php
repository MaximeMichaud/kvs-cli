<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

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
            $device = $parts[2] ?? '';
            if (preg_match('/^(sd[a-z]|nvme\d+n\d+|vd[a-z])$/', $device) !== 1) {
                continue;
            }

            $readOps += (int)($parts[3] ?? 0);
            $writeOps += (int)($parts[7] ?? 0);
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
     * Get system info for the report header
     *
     * @return array<string, string|bool>
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

        // OPcache status
        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status();
            $info['opcache'] = $status !== false;

            if ($status !== false && isset($status['jit']['enabled'])) {
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
                $info['os_name'] = (string)$osRelease['PRETTY_NAME'];
            }
        }

        // Web server (if running as FPM)
        if (isset($_SERVER['SERVER_SOFTWARE']) && is_string($_SERVER['SERVER_SOFTWARE'])) {
            $info['web_server'] = $_SERVER['SERVER_SOFTWARE'];
        }

        return $info;
    }
}
