<?php

namespace KVS\CLI\Config;

use KVS\CLI\Constants;

class Configuration
{
    /** @var array<string, mixed> */
    private array $config = [];
    private string $kvsPath;
    /** @var array<string, string> */
    private array $dbConfig = [];
    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->loadConfiguration();
    }

    private function loadConfiguration(): void
    {
        $this->kvsPath = $this->findKvsPath();

        if ($this->kvsPath !== '' && file_exists($this->kvsPath . '/admin/include/setup_db.php')) {
            $this->loadDatabaseConfig();
        }

        $this->loadProjectConfig();
    }

    private function findKvsPath(): string
    {
        $allowMissingKvs = ($this->options['allow_missing_kvs'] ?? false) === true;

        // 1. Check --path parameter (highest priority)
        if (isset($this->options['path']) && is_string($this->options['path'])) {
            $path = $this->options['path'];
            if (!$this->isKvsInstallation($path)) {
                if ($allowMissingKvs) {
                    return '';
                }
                throw new \Exception("The path '$path' does not contain a valid KVS installation.");
            }
            return $path;
        }

        // 2. Check KVS_PATH environment variable
        $envPath = getenv('KVS_PATH');
        if ($envPath !== false && $envPath !== '' && $this->isKvsInstallation($envPath)) {
            return $envPath;
        }

        // 3. Auto-detect from current directory (walk up the tree)
        $dir = getcwd();
        while ($dir !== false && $dir !== '/') {
            if ($this->isKvsInstallation($dir)) {
                return $dir;
            }
            $dir = dirname($dir);
            // Stop at root to avoid infinite loop
            if ($dir === '/') {
                break;
            }
        }

        if ($allowMissingKvs) {
            return '';
        }

        throw new \Exception('KVS installation not found. Use --path=' . \KVS\CLI\Application::EXAMPLE_PATH . ' or run from KVS directory.');
    }

    private function isKvsInstallation(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        // KVS signature files
        $requiredFiles = [
            '/admin/include/setup_db.php',
            '/admin/include/setup.php',
        ];

        foreach ($requiredFiles as $file) {
            $fullPath = $path . $file;
            if (!file_exists($fullPath)) {
                return false;
            }
        }

        return true;
    }

    private function loadDatabaseConfig(): void
    {
        $dbConfigFile = $this->kvsPath . '/admin/include/setup_db.php';
        if (file_exists($dbConfigFile)) {
            $content = file_get_contents($dbConfigFile);
            if ($content === false) {
                return;
            }

            $host = $this->extractDefineValue($content, 'DB_HOST');
            $user = $this->extractDefineValue($content, 'DB_LOGIN');
            $password = $this->extractDefineValue($content, 'DB_PASS');
            $database = $this->extractDefineValue($content, 'DB_DEVICE');

            if ($host !== null && $host !== '') {
                $this->dbConfig['host'] = $host;
            }
            if ($user !== null && $user !== '') {
                $this->dbConfig['user'] = $user;
            }
            if ($password !== null && $password !== '') {
                $this->dbConfig['password'] = $password;
            }
            if ($database !== null && $database !== '') {
                $this->dbConfig['database'] = $database;
            }
        }

        // Environment variable overrides (highest priority)
        $envHost = getenv('KVS_DB_HOST');
        if ($envHost !== false && $envHost !== '') {
            $this->dbConfig['host'] = $envHost;
        }
        $envUser = getenv('KVS_DB_USER');
        if ($envUser !== false && $envUser !== '') {
            $this->dbConfig['user'] = $envUser;
        }
        $envPass = getenv('KVS_DB_PASS');
        if ($envPass !== false && $envPass !== '') {
            $this->dbConfig['password'] = $envPass;
        }
        $envDb = getenv('KVS_DB_NAME');
        if ($envDb !== false && $envDb !== '') {
            $this->dbConfig['database'] = $envDb;
        }

        // Smart fallback: if hostname doesn't resolve, try alternatives
        $this->applyHostnameFallback();
    }

    /**
     * Apply smart hostname fallback for Docker/host networking issues.
     * If the configured hostname doesn't resolve, try common alternatives.
     */
    private function applyHostnameFallback(): void
    {
        $host = $this->dbConfig['host'] ?? '';
        // Extract host without port for comparison
        $hostWithoutPort = str_contains($host, ':') ? explode(':', $host, 2)[0] : $host;
        if ($host === '' || $hostWithoutPort === '127.0.0.1' || $hostWithoutPort === 'localhost') {
            return; // Already using localhost, no fallback needed
        }

        // Check if hostname resolves (without port)
        if ($this->hostnameResolves($hostWithoutPort)) {
            return; // Hostname resolves, use it
        }

        // Hostname doesn't resolve - try fallbacks
        $fallbacks = [
            '127.0.0.1',  // Most common: Docker port exposed to host
        ];

        // If hostname looks like a Docker container name, try prefixed versions
        if (!str_contains($host, '.') && !str_contains($host, ':')) {
            // Try common Docker naming patterns
            $fallbacks = array_merge([
                'kvs-' . $host,      // e.g., mariadb -> kvs-mariadb
                $host . '-db',       // e.g., mariadb -> mariadb-db
            ], $fallbacks);
        }

        foreach ($fallbacks as $fallback) {
            if ($this->hostnameResolves($fallback)) {
                $this->dbConfig['host'] = $fallback;
                $this->dbConfig['_fallback_used'] = '1';
                $this->dbConfig['_original_host'] = $host;
                return;
            }
        }

        // No fallback worked, keep original (will fail with clear error)
    }

    /**
     * Check if a hostname resolves to an IP address.
     */
    private function hostnameResolves(string $hostname): bool
    {
        // IP addresses always "resolve"
        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Check DNS resolution
        $resolved = gethostbyname($hostname);
        return $resolved !== $hostname;
    }

    /**
     * Extract value from define() statement, handling various formats:
     * - Simple: define('KEY', 'value')
     * - With getenv: define('KEY', getenv('VAR') ?: 'default')
     */
    private function extractDefineValue(string $content, string $key): ?string
    {
        $escapedKey = preg_quote($key, '/');

        $result = preg_match("/define\\(['\"]" . $escapedKey . "['\"],\\s*['\"]([^'\"]+)['\"]\\)/", $content, $matches);
        if ($result !== false && $result === 1) {
            return $matches[1];
        }

        $pattern2 = "/define\\(['\"]" . $escapedKey . "['\"],\\s*getenv\\(['\"]([^'\"]+)['\"]\\)"
            . "\\s*\\?:\\s*['\"]([^'\"]*)['\"]\\)/";
        if (preg_match($pattern2, $content, $matches) === 1) {
            $envVar = $matches[1];
            $default = $matches[2];
            $envValue = getenv($envVar);
            return $envValue !== false && $envValue !== '' ? $envValue : $default;
        }

        $pattern3 = "/define\\(['\"]" . $escapedKey . "['\"],\\s*getenv\\(['\"]([^'\"]+)['\"]\\)"
            . "\\s*\\?\\?\\s*['\"]([^'\"]*)['\"]\\)/";
        if (preg_match($pattern3, $content, $matches) === 1) {
            $envVar = $matches[1];
            $default = $matches[2];
            $envValue = getenv($envVar);
            return $envValue !== false ? $envValue : $default;
        }

        $result4 = preg_match("/define\\(['\"]" . $escapedKey . "['\"],\\s*getenv\\(['\"]([^'\"]+)['\"]\\)\\)/", $content, $matches);
        if ($result4 !== false && $result4 === 1) {
            $envVar = $matches[1];
            $envValue = getenv($envVar);
            return $envValue !== false ? $envValue : null;
        }

        return null;
    }

    private function loadProjectConfig(): void
    {
        $configFile = $this->kvsPath . '/admin/include/setup.php';
        if (file_exists($configFile)) {
            ob_start();
            include $configFile;
            ob_end_clean();

            if (isset($config) && is_array($config)) {
                /** @var array<string, mixed> $config */
                $this->config = $config;
            }
        }
    }

    public function getKvsPath(): string
    {
        return $this->kvsPath;
    }

    public function getAdminPath(): string
    {
        return $this->kvsPath . '/admin';
    }

    public function getContentPath(): string
    {
        // Check for explicit content path in config
        $videosPathConfig = $this->config['content_path_videos_sources'] ?? null;
        if (is_string($videosPathConfig) && is_dir($videosPathConfig)) {
            $basename = basename($videosPathConfig);
            if ($basename === Constants::CONTENT_VIDEOS_SOURCES) {
                return dirname($videosPathConfig);
            }

            // Alternate layout: content/videos/sources.
            return dirname(dirname($videosPathConfig));
        }

        // Try project_path/contents/ (standard layout)
        $projectPathConfig = $this->config['project_path'] ?? null;
        $projectPath = is_string($projectPathConfig) ? $projectPathConfig : dirname($this->kvsPath);
        $standardPath = $projectPath . '/' . Constants::CONTENT_DIR;
        if (is_dir($standardPath)) {
            return $standardPath;
        }

        $singularProjectContentPath = $projectPath . '/content';
        if (is_dir($singularProjectContentPath)) {
            return $singularProjectContentPath;
        }

        // Fallback: try kvsPath/contents (when project_path in config is stale/wrong)
        $kvsContentPath = $this->kvsPath . '/' . Constants::CONTENT_DIR;
        if (is_dir($kvsContentPath)) {
            return $kvsContentPath;
        }

        // Older/local test installs can use singular content.
        $kvsSingularContentPath = $this->kvsPath . '/content';
        if (is_dir($kvsSingularContentPath)) {
            return $kvsSingularContentPath;
        }

        // Try project_path/../content (alternate layout)
        $alternatePath = dirname($projectPath) . '/content';
        if (is_dir($alternatePath)) {
            return $alternatePath;
        }

        // Return kvsPath-based path for better error reporting
        return $kvsContentPath;
    }

    /**
     * Get path for video source files
     */
    public function getVideoSourcesPath(): string
    {
        return $this->getConfiguredContentSubPath(
            'content_path_videos_sources',
            Constants::CONTENT_VIDEOS_SOURCES
        );
    }

    /**
     * Get path for video screenshots
     */
    public function getVideoScreenshotsPath(): string
    {
        return $this->getConfiguredContentSubPath(
            'content_path_videos_screenshots',
            Constants::CONTENT_VIDEOS_SCREENSHOTS
        );
    }

    /**
     * Get path for album source files
     */
    public function getAlbumSourcesPath(): string
    {
        return $this->getConfiguredContentSubPath(
            'content_path_albums_sources',
            Constants::CONTENT_ALBUMS_SOURCES
        );
    }

    /**
     * Get path for category files
     */
    public function getCategoriesPath(): string
    {
        return $this->getConfiguredContentSubPath(
            'content_path_categories',
            Constants::CONTENT_CATEGORIES
        );
    }

    private function getConfiguredContentSubPath(string $configKey, string $subPath): string
    {
        $configuredPath = $this->config[$configKey] ?? null;
        $fallbackPath = $this->getContentPath() . '/' . $subPath;

        if (is_string($configuredPath) && $configuredPath !== '') {
            if (is_dir($configuredPath) || !is_dir($fallbackPath)) {
                return $configuredPath;
            }
        }

        return $fallbackPath;
    }

    /**
     * @return array<string, string>
     */
    public function getDatabaseConfig(): array
    {
        return $this->dbConfig;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProjectConfig(): array
    {
        return $this->config;
    }

    public function isKvsInstalled(): bool
    {
        return $this->kvsPath !== '' && $this->dbConfig !== [];
    }

    public function getTablePrefix(): string
    {
        $prefix = $this->config['tables_prefix'] ?? null;
        if (is_string($prefix)) {
            return $prefix;
        }
        return Constants::DEFAULT_TABLE_PREFIX;
    }

    public function getMultiTablePrefix(): string
    {
        $prefix = $this->config['tables_prefix_multi'] ?? null;
        if (is_string($prefix)) {
            return $prefix;
        }
        return $this->getTablePrefix();
    }

    public function getKvsVersion(): string
    {
        $version = $this->config['project_version'] ?? null;
        if (is_string($version)) {
            return $version;
        }
        return '';
    }
}
