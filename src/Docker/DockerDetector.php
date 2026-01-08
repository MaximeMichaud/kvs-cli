<?php

declare(strict_types=1);

namespace KVS\CLI\Docker;

/**
 * Auto-detect KVS Docker containers by convention.
 *
 * Container naming convention (from KVS-install docker-compose.yml):
 * - kvs-php      : PHP-FPM
 * - kvs-mariadb  : MariaDB
 * - kvs-memcached: Memcached (profile: memcached)
 * - kvs-dragonfly: Dragonfly (profile: dragonfly)
 * - kvs-cron     : Cron
 * - kvs-nginx    : Nginx
 */
class DockerDetector
{
    private const CONTAINERS = [
        'php' => 'kvs-php',
        'mariadb' => 'kvs-mariadb',
        'memcached' => 'kvs-memcached',
        'dragonfly' => 'kvs-dragonfly',
        'cron' => 'kvs-cron',
        'nginx' => 'kvs-nginx',
    ];

    private ?bool $dockerAvailable = null;

    /** @var array<string, bool> */
    private array $runningContainers = [];

    private bool $detected = false;

    /**
     * Check if Docker CLI is available.
     */
    public function isDockerAvailable(): bool
    {
        if ($this->dockerAvailable !== null) {
            return $this->dockerAvailable;
        }

        $which = @shell_exec('which docker 2>/dev/null');
        $this->dockerAvailable = $which !== null && $which !== false && trim($which) !== '';

        return $this->dockerAvailable;
    }

    /**
     * Detect running KVS containers.
     */
    public function detect(): void
    {
        if ($this->detected) {
            return;
        }

        $this->detected = true;

        if (!$this->isDockerAvailable()) {
            return;
        }

        // Single docker command to get all running container names
        $output = @shell_exec('docker ps --format "{{.Names}}" 2>/dev/null');
        if ($output === null || $output === false) {
            return;
        }

        $runningNames = array_filter(
            array_map('trim', explode("\n", $output)),
            static fn(string $s): bool => $s !== ''
        );

        foreach (self::CONTAINERS as $service => $containerName) {
            $this->runningContainers[$service] = in_array($containerName, $runningNames, true);
        }
    }

    /**
     * Check if KVS is running in Docker (at least PHP container exists).
     */
    public function isKvsInDocker(): bool
    {
        $this->detect();
        return $this->isRunning('php');
    }

    /**
     * Check if a specific service container is running.
     */
    public function isRunning(string $service): bool
    {
        $this->detect();
        return $this->runningContainers[$service] ?? false;
    }

    /**
     * Get container name for a service.
     */
    public function getContainerName(string $service): ?string
    {
        return self::CONTAINERS[$service] ?? null;
    }

    /**
     * Get the cache container (memcached or dragonfly).
     */
    public function getCacheContainer(): ?string
    {
        $this->detect();

        if ($this->isRunning('dragonfly')) {
            return self::CONTAINERS['dragonfly'];
        }

        if ($this->isRunning('memcached')) {
            return self::CONTAINERS['memcached'];
        }

        return null;
    }

    /**
     * Execute a command in a container.
     *
     * @return string|null Output or null on failure
     */
    public function exec(string $service, string $command, int $timeout = 10): ?string
    {
        $containerName = self::CONTAINERS[$service] ?? null;
        if ($containerName === null || !$this->isRunning($service)) {
            return null;
        }

        $fullCommand = sprintf(
            'docker exec %s %s 2>/dev/null',
            escapeshellarg($containerName),
            $command
        );

        $output = @shell_exec($fullCommand);

        if ($output === null || $output === false) {
            return null;
        }

        return $output;
    }

    /**
     * Execute PHP code in the PHP container.
     *
     * @return string|null Output or null on failure
     */
    public function execPhp(string $phpCode): ?string
    {
        return $this->exec('php', 'php -r ' . escapeshellarg($phpCode));
    }

    /**
     * Get PHP ini value from Docker container.
     */
    public function getPhpIni(string $setting): ?string
    {
        $result = $this->execPhp("echo ini_get('$setting');");
        return $result !== null ? trim($result) : null;
    }

    /**
     * Check if PHP extension is loaded in Docker container.
     */
    public function isPhpExtensionLoaded(string $extension): ?bool
    {
        $result = $this->execPhp("echo extension_loaded('$extension') ? '1' : '0';");
        if ($result === null) {
            return null;
        }
        return trim($result) === '1';
    }

    /**
     * Get PHP version from Docker container.
     */
    public function getPhpVersion(): ?string
    {
        $result = $this->execPhp('echo PHP_VERSION;');
        return $result !== null ? trim($result) : null;
    }

    /**
     * Get all running KVS containers.
     *
     * @return array<string, string> service => container_name
     */
    public function getRunningContainers(): array
    {
        $this->detect();

        $running = [];
        foreach (self::CONTAINERS as $service => $containerName) {
            if ($this->runningContainers[$service] ?? false) {
                $running[$service] = $containerName;
            }
        }

        return $running;
    }

    /**
     * Get summary for display.
     *
     * @return array{docker_available: bool, kvs_in_docker: bool, containers: array<string, bool>}
     */
    public function getSummary(): array
    {
        $this->detect();

        return [
            'docker_available' => $this->isDockerAvailable(),
            'kvs_in_docker' => $this->isKvsInDocker(),
            'containers' => $this->runningContainers,
        ];
    }

    /**
     * Check cache connectivity (Dragonfly or Memcached).
     *
     * @return array{available: bool, type: string|null, memory_mb: int|null}
     */
    public function checkCache(): array
    {
        $result = [
            'available' => false,
            'type' => null,
            'memory_mb' => null,
        ];

        // Try Dragonfly first (uses Redis protocol)
        if ($this->isRunning('dragonfly')) {
            $pingResult = $this->exec('dragonfly', 'redis-cli PING 2>/dev/null');
            if ($pingResult !== null && str_contains(trim($pingResult), 'PONG')) {
                $result['available'] = true;
                $result['type'] = 'Dragonfly';

                // Get memory info
                $memResult = $this->exec('dragonfly', 'redis-cli INFO memory 2>/dev/null');
                if ($memResult !== null && preg_match('/maxmemory:(\d+)/', $memResult, $matches) === 1) {
                    $bytes = (int) $matches[1];
                    $result['memory_mb'] = $bytes > 0 ? (int) ($bytes / 1024 / 1024) : 512;
                }
                return $result;
            }
        }

        // Try Memcached container
        if ($this->isRunning('memcached')) {
            $statsResult = $this->exec('memcached', "sh -c 'echo stats | nc localhost 11211' 2>/dev/null");
            if ($statsResult !== null && str_contains($statsResult, 'STAT')) {
                $result['available'] = true;
                $result['type'] = 'Memcached';

                if (preg_match('/STAT limit_maxbytes (\d+)/', $statsResult, $matches) === 1) {
                    $result['memory_mb'] = (int) ((int) $matches[1] / 1024 / 1024);
                }
                return $result;
            }
        }

        return $result;
    }

    /**
     * Get cache memory via PHP container (fallback for custom server names).
     */
    public function getCacheMemoryViaPhp(string $server, int $port): ?int
    {
        $phpCode = <<<PHP
\$fp = @fsockopen('$server', $port, \$errno, \$errstr, 2);
if (\$fp === false) { echo 'FAIL'; exit; }
fwrite(\$fp, "stats\r\n");
\$response = '';
while (!feof(\$fp)) {
    \$line = fgets(\$fp, 256);
    if (\$line !== false) {
        \$response .= \$line;
        if (trim(\$line) === 'END') break;
    }
}
fclose(\$fp);
if (preg_match('/STAT limit_maxbytes (\d+)/', \$response, \$m)) {
    echo \$m[1];
} else {
    echo 'FAIL';
}
PHP;
        $result = $this->execPhp($phpCode);
        if ($result !== null && $result !== 'FAIL' && is_numeric(trim($result))) {
            return (int) ((int) trim($result) / 1024 / 1024);
        }

        return null;
    }

    /**
     * Get all PHP info at once (more efficient than multiple calls).
     *
     * @param list<string> $settings
     * @param list<string> $extensions
     * @return array{settings: array<string, string>, extensions: array<string, bool>, version: string}
     */
    public function getPhpInfo(array $settings = [], array $extensions = []): array
    {
        $settingsJson = json_encode($settings);
        $extensionsJson = json_encode($extensions);

        $phpCode = <<<PHP
\$settings = json_decode('$settingsJson', true);
\$extensions = json_decode('$extensionsJson', true);
\$result = ['settings' => [], 'extensions' => [], 'version' => PHP_VERSION];
foreach (\$settings as \$s) { \$result['settings'][\$s] = ini_get(\$s) ?: ''; }
foreach (\$extensions as \$e) { \$result['extensions'][\$e] = extension_loaded(\$e); }
echo json_encode(\$result);
PHP;

        $result = $this->execPhp($phpCode);
        if ($result !== null) {
            $decoded = json_decode(trim($result), true);
            if (is_array($decoded) && isset($decoded['settings'], $decoded['extensions'], $decoded['version'])) {
                /** @var array{settings: array<string, string>, extensions: array<string, bool>, version: string} */
                return $decoded;
            }
        }

        return ['settings' => [], 'extensions' => [], 'version' => ''];
    }
}
