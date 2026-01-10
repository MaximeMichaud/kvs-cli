<?php

namespace KVS\CLI\Tests;

use PDO;

/**
 * Test Helper - Provides shared utilities for test suite
 *
 * Eliminates hardcoded values by reading from environment variables
 * with sensible defaults for local development.
 */
class TestHelper
{
    /**
     * Get database configuration from environment variables
     *
     * Environment variables (set in phpunit.xml or shell):
     * - KVS_TEST_DB_HOST (default: 127.0.0.1)
     * - KVS_TEST_DB_PORT (default: 3306)
     * - KVS_TEST_DB_USER (default: kvs_user)
     * - KVS_TEST_DB_PASS (default: kvs_pass)
     * - KVS_TEST_DB_NAME (default: kvs_test)
     *
     * @return array{host: string, port: int, user: string, pass: string, database: string}
     */
    public static function getDbConfig(): array
    {
        return [
            'host' => getenv('KVS_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('KVS_TEST_DB_PORT') ?: 3306),
            'user' => getenv('KVS_TEST_DB_USER') ?: 'kvs_user',
            'pass' => getenv('KVS_TEST_DB_PASS') ?: 'kvs_pass',
            'database' => getenv('KVS_TEST_DB_NAME') ?: 'kvs_test',
        ];
    }

    /**
     * Create PDO instance with test database configuration
     *
     * @return PDO
     * @throws \PDOException if connection fails
     */
    public static function getPDO(): PDO
    {
        $config = self::getDbConfig();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database']
        );

        return new PDO(
            $dsn,
            $config['user'],
            $config['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 1,
            ]
        );
    }

    /**
     * Create mock KVS database configuration file (setup_db.php)
     *
     * Used by tests that need to create a temporary KVS installation
     * with valid database configuration.
     *
     * @param string $dir Directory where to create admin/include/setup_db.php
     * @param array{host?: string, user?: string, password?: string, database?: string} $overrides Optional config overrides
     * @return void
     */
    public static function createMockDbConfig(string $dir, array $overrides = []): void
    {
        $defaults = self::getDbConfig();

        $config = [
            'host' => $overrides['host'] ?? $defaults['host'],
            'user' => $overrides['user'] ?? $defaults['user'],
            'pass' => $overrides['password'] ?? $defaults['pass'],
            'database' => $overrides['database'] ?? $defaults['database'],
        ];

        $includeDir = $dir . '/admin/include';
        if (!is_dir($includeDir)) {
            mkdir($includeDir, 0755, true);
        }

        $content = <<<PHP
<?php
define('DB_HOST', '{$config['host']}');
define('DB_LOGIN', '{$config['user']}');
define('DB_PASS', '{$config['pass']}');
define('DB_DEVICE', '{$config['database']}');

PHP;

        file_put_contents($includeDir . '/setup_db.php', $content);
    }

    /**
     * Create mock KVS setup.php configuration file
     *
     * @param string $dir Directory where to create admin/include/setup.php
     * @param array $config Optional config overrides
     * @return void
     */
    public static function createMockSetupConfig(string $dir, array $config = []): void
    {
        $includeDir = $dir . '/admin/include';
        if (!is_dir($includeDir)) {
            mkdir($includeDir, 0755, true);
        }

        $defaults = [
            'project_version' => '6.3.2',
            'project_name' => 'Test KVS',
            'memcache_server' => '127.0.0.1',
            'memcache_port' => '11211',
        ];

        $merged = array_merge($defaults, $config);

        $configLines = [];
        foreach ($merged as $key => $value) {
            $configLines[] = sprintf('$config[\'%s\'] = \'%s\';', $key, $value);
        }

        $content = "<?php\n" . implode("\n", $configLines) . "\n";
        file_put_contents($includeDir . '/setup.php', $content);
    }

    /**
     * Create complete mock KVS installation structure
     *
     * Creates all required directories and files for a valid KVS installation
     * that can be used in tests.
     *
     * @param string $dir Base directory for KVS installation
     * @param array $setupConfig Optional setup.php config overrides
     * @return void
     */
    public static function createMockKvsInstallation(string $dir, array $setupConfig = []): void
    {
        // Create directory structure
        $dirs = [
            '/admin/include',
            '/admin/data/system',
            '/admin/data/engine',
            '/admin/smarty/cache',
            '/admin/logs',
            '/admin/plugins',
            '/content',
        ];

        foreach ($dirs as $subdir) {
            $path = $dir . $subdir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }

        // Create configuration files
        self::createMockDbConfig($dir);
        self::createMockSetupConfig($dir, $setupConfig);

        // Create version file
        file_put_contents(
            $dir . '/admin/include/version.php',
            '<?php define("KVS_VERSION", "6.3.2");'
        );
    }

    /**
     * Get project temp directory for tests
     *
     * Returns the project's temp directory, creates it if needed.
     * Prefer this over sys_get_temp_dir() to keep test artifacts
     * within the project.
     *
     * @return string Absolute path to project temp directory
     */
    public static function getProjectTempDir(): string
    {
        $tempDir = dirname(__DIR__) . '/temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        return $tempDir;
    }

    /**
     * Create unique temporary directory within project
     *
     * @param string $prefix Prefix for directory name
     * @return string Absolute path to created directory
     */
    public static function createTempDir(string $prefix = 'kvs-test-'): string
    {
        $dir = self::getProjectTempDir() . '/' . $prefix . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Remove directory recursively
     *
     * @param string $dir Directory to remove
     * @return void
     */
    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Get the table prefix for KVS tables
     *
     * Reads from KVS_TEST_TABLE_PREFIX environment variable,
     * defaults to 'ktvs_' which is the standard KVS prefix.
     *
     * @return string Table prefix (e.g., 'ktvs_')
     */
    public static function getTablePrefix(): string
    {
        return getenv('KVS_TEST_TABLE_PREFIX') ?: 'ktvs_';
    }

    /**
     * Get prefixed table name
     *
     * @param string $table Base table name (without prefix)
     * @return string Full table name with prefix
     */
    public static function table(string $table): string
    {
        return self::getTablePrefix() . $table;
    }
}
