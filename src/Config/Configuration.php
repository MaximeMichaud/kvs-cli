<?php

namespace KVS\CLI\Config;

class Configuration
{
    private array $config = [];
    private string $kvsPath;
    private array $dbConfig = [];
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->loadConfiguration();
    }

    private function loadConfiguration(): void
    {
        $this->kvsPath = $this->findKvsPath();

        if ($this->kvsPath && file_exists($this->kvsPath . '/admin/include/setup_db.php')) {
            $this->loadDatabaseConfig();
        }

        $this->loadProjectConfig();
    }

    private function findKvsPath(): string
    {
        // 1. Check --path parameter (highest priority)
        if (isset($this->options['path'])) {
            $path = $this->options['path'];
            if (!$this->isKvsInstallation($path)) {
                throw new \Exception("The path '$path' does not contain a valid KVS installation.");
            }
            return $path;
        }

        // 2. Check KVS_PATH environment variable
        $envPath = getenv('KVS_PATH');
        if ($envPath && $this->isKvsInstallation($envPath)) {
            return $envPath;
        }

        // 3. Auto-detect from current directory (walk up the tree)
        $dir = getcwd();
        while ($dir && $dir !== '/') {
            if ($this->isKvsInstallation($dir)) {
                return $dir;
            }
            $dir = dirname($dir);
            // Stop at root to avoid infinite loop
            if ($dir === '/') {
                break;
            }
        }

        throw new \Exception('KVS installation not found. Use --path=/path/to/kvs or run from KVS directory.');
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

            // Match define() with optional spaces: define('KEY', 'value') or define('KEY','value')
            if (preg_match("/define\\('DB_HOST',\\s*'([^']+)'\\)/", $content, $matches)) {
                $this->dbConfig['host'] = $matches[1];
            }
            if (preg_match("/define\\('DB_LOGIN',\\s*'([^']+)'\\)/", $content, $matches)) {
                $this->dbConfig['user'] = $matches[1];
            }
            if (preg_match("/define\\('DB_PASS',\\s*'([^']+)'\\)/", $content, $matches)) {
                $this->dbConfig['password'] = $matches[1];
            }
            if (preg_match("/define\\('DB_DEVICE',\\s*'([^']+)'\\)/", $content, $matches)) {
                $this->dbConfig['database'] = $matches[1];
            }
        }
    }

    private function loadProjectConfig(): void
    {
        $configFile = $this->kvsPath . '/admin/include/setup.php';
        if (file_exists($configFile)) {
            ob_start();
            include $configFile;
            ob_end_clean();

            if (isset($config) && is_array($config)) {
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
        return dirname($this->kvsPath) . '/content';
    }

    public function getDatabaseConfig(): array
    {
        return $this->dbConfig;
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function isKvsInstalled(): bool
    {
        return !empty($this->kvsPath) && !empty($this->dbConfig);
    }
}
