<?php

declare(strict_types=1);

namespace KVS\CLI\Docker;

/**
 * Auto-detect KVS Docker containers with multi-site support.
 *
 * Container naming convention (from KVS-install docker-compose.yml):
 * Single-site (legacy): kvs-php, kvs-mariadb, kvs-memcached, etc.
 * Multi-site: {prefix}-php, {prefix}-mariadb, {prefix}-memcached, etc.
 *   Example: kvs-maximemichaud-php, kvs-maximemichaud-mariadb, etc.
 *
 * Detection works by:
 * 1. Finding PHP containers by pattern (*-php)
 * 2. Matching against KVS path via volume mounts
 * 3. Deriving service prefix from container name
 */
class DockerDetector
{
    /** @var array<string, string> Service suffixes for container naming */
    private const SERVICE_SUFFIXES = [
        'php' => '-php',
        'mariadb' => '-mariadb',
        'memcached' => '-memcached',
        'dragonfly' => '-dragonfly',
        'cron' => '-cron',
        'nginx' => '-nginx',
    ];

    /** Legacy container names for backwards compatibility */
    private const LEGACY_CONTAINERS = [
        'php' => 'kvs-php',
        'mariadb' => 'kvs-mariadb',
        'memcached' => 'kvs-memcached',
        'dragonfly' => 'kvs-dragonfly',
        'cron' => 'kvs-cron',
        'nginx' => 'kvs-nginx',
    ];

    private ?bool $dockerAvailable = null;
    private ?string $containerPrefix = null;
    private ?string $kvsPath = null;

    /** @var array<string, bool> */
    private array $runningContainers = [];

    /** @var array<string, string> service => container_name */
    private array $containerNames = [];

    private bool $detected = false;

    /**
     * Set the KVS installation path for volume mount matching.
     */
    public function setKvsPath(string $path): self
    {
        $this->kvsPath = rtrim($path, '/');
        $this->detected = false; // Reset detection
        return $this;
    }

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
     * Detect running KVS containers with multi-site support.
     */
    public function detect(): void
    {
        if ($this->detected) {
            return;
        }

        $this->detected = true;
        $this->runningContainers = [];
        $this->containerNames = [];
        $this->containerPrefix = null;

        if (!$this->isDockerAvailable()) {
            return;
        }

        // Get all running container names
        $output = @shell_exec('docker ps --format "{{.Names}}" 2>/dev/null');
        if ($output === null || $output === false) {
            return;
        }

        /** @var list<non-empty-string> $runningNames */
        $runningNames = array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            static fn(string $s): bool => $s !== ''
        ));

        // Try to find PHP container matching our KVS path
        $prefix = $this->detectPrefixByVolume($runningNames);

        if ($prefix !== null) {
            $this->containerPrefix = $prefix;
            $this->buildContainerNames($prefix, $runningNames);
            return;
        }

        // Fall back to legacy detection (kvs-* containers)
        foreach (self::LEGACY_CONTAINERS as $service => $containerName) {
            if (in_array($containerName, $runningNames, true)) {
                $this->runningContainers[$service] = true;
                $this->containerNames[$service] = $containerName;
            }
        }

        if ($this->containerNames !== []) {
            $this->containerPrefix = 'kvs';
        }
    }

    /**
     * Detect container prefix by matching KVS path against volume mounts.
     *
     * @param list<non-empty-string> $runningNames
     */
    private function detectPrefixByVolume(array $runningNames): ?string
    {
        if ($this->kvsPath === null) {
            return null;
        }

        // Find all containers ending with -php
        $phpContainers = array_filter(
            $runningNames,
            static fn(string $name): bool => str_ends_with($name, '-php')
        );

        foreach ($phpContainers as $containerName) {
            // Inspect container for volume mounts
            $inspectCmd = sprintf(
                'docker inspect %s --format "{{range .Mounts}}{{.Source}}:{{.Destination}}{{\"\\n\"}}{{end}}" 2>/dev/null',
                escapeshellarg($containerName)
            );
            $mounts = @shell_exec($inspectCmd);

            if ($mounts === null || $mounts === false) {
                continue;
            }

            // Check if any mount matches our KVS path
            $mountLines = array_filter(
                array_map('trim', explode("\n", $mounts)),
                static fn(string $line): bool => $line !== ''
            );
            foreach ($mountLines as $mount) {
                [$source] = explode(':', $mount, 2);
                // Normalize paths with realpath for symlink support
                $realSource = realpath($source);
                $normalizedSource = $realSource !== false ? $realSource : $source;
                $realKvsPath = realpath($this->kvsPath);
                $normalizedKvsPath = $realKvsPath !== false ? $realKvsPath : $this->kvsPath;
                // Match exact path or parent path
                if ($normalizedSource === $normalizedKvsPath || str_starts_with($normalizedKvsPath, $normalizedSource . '/')) {
                    // Extract prefix: kvs-maximemichaud-php -> kvs-maximemichaud
                    return substr($containerName, 0, -4); // Remove '-php'
                }
            }
        }

        return null;
    }

    /**
     * Build container names from detected prefix.
     *
     * @param list<non-empty-string> $runningNames
     */
    private function buildContainerNames(string $prefix, array $runningNames): void
    {
        foreach (self::SERVICE_SUFFIXES as $service => $suffix) {
            $containerName = $prefix . $suffix;
            if (in_array($containerName, $runningNames, true)) {
                $this->runningContainers[$service] = true;
                $this->containerNames[$service] = $containerName;
            }
        }
    }

    /**
     * Get the detected container prefix.
     */
    public function getContainerPrefix(): ?string
    {
        $this->detect();
        return $this->containerPrefix;
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
        $this->detect();
        return $this->containerNames[$service] ?? null;
    }

    /**
     * Get the cache container (memcached or dragonfly).
     */
    public function getCacheContainer(): ?string
    {
        $this->detect();

        if ($this->isRunning('dragonfly')) {
            return $this->containerNames['dragonfly'] ?? null;
        }

        if ($this->isRunning('memcached')) {
            return $this->containerNames['memcached'] ?? null;
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
        $this->detect();

        $containerName = $this->containerNames[$service] ?? null;
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
        return $this->containerNames;
    }

    /**
     * Get summary for display.
     *
     * @return array{docker_available: bool, kvs_in_docker: bool, prefix: string|null, containers: array<string, bool>}
     */
    public function getSummary(): array
    {
        $this->detect();

        return [
            'docker_available' => $this->isDockerAvailable(),
            'kvs_in_docker' => $this->isKvsInDocker(),
            'prefix' => $this->containerPrefix,
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
