<?php

namespace KVS\CLI\Config;

use KVS\CLI\Constants;

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

            $this->dbConfig['host'] = $this->extractDefineValue($content, 'DB_HOST');
            $this->dbConfig['user'] = $this->extractDefineValue($content, 'DB_LOGIN');
            $this->dbConfig['password'] = $this->extractDefineValue($content, 'DB_PASS');
            $this->dbConfig['database'] = $this->extractDefineValue($content, 'DB_DEVICE');

            // Remove empty values
            $this->dbConfig = array_filter($this->dbConfig, fn($v) => $v !== null && $v !== '');
        }
    }

    /**
     * Extract value from define() statement, handling various formats:
     * - Simple: define('KEY', 'value')
     * - With getenv: define('KEY', getenv('VAR') ?: 'default')
     */
    private function extractDefineValue(string $content, string $key): ?string
    {
        // Pattern 1: Simple string value - define('KEY', 'value')
        if (preg_match("/define\\(['\"]" . $key . "['\"],\\s*['\"]([^'\"]+)['\"]\\)/", $content, $matches)) {
            return $matches[1];
        }

        // Pattern 2: getenv with ?: fallback - define('KEY', getenv('VAR') ?: 'default')
        $pattern2 = "/define\\(['\"]" . $key . "['\"],\\s*getenv\\(['\"]([^'\"]+)['\"]\\)"
            . "\\s*\\?:\\s*['\"]([^'\"]*)['\"]\\)/";
        if (preg_match($pattern2, $content, $matches)) {
            $envVar = $matches[1];
            $default = $matches[2];
            $envValue = getenv($envVar);
            return $envValue !== false && $envValue !== '' ? $envValue : $default;
        }

        // Pattern 3: getenv with ?? fallback - define('KEY', getenv('VAR') ?? 'default')
        $pattern3 = "/define\\(['\"]" . $key . "['\"],\\s*getenv\\(['\"]([^'\"]+)['\"]\\)"
            . "\\s*\\?\\?\\s*['\"]([^'\"]*)['\"]\\)/";
        if (preg_match($pattern3, $content, $matches)) {
            $envVar = $matches[1];
            $default = $matches[2];
            $envValue = getenv($envVar);
            return $envValue !== false ? $envValue : $default;
        }

        // Pattern 4: Just getenv without fallback - define('KEY', getenv('VAR'))
        if (preg_match("/define\\(['\"]" . $key . "['\"],\\s*getenv\\(['\"]([^'\"]+)['\"]\\)\\)/", $content, $matches)) {
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
        // KVS stores content in project_path/contents/
        $projectPath = $this->config['project_path'] ?? dirname($this->kvsPath);
        return $projectPath . '/contents';
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

    public function getTablePrefix(): string
    {
        return $this->config['tables_prefix'] ?? Constants::DEFAULT_TABLE_PREFIX;
    }
}
