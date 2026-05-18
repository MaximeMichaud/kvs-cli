<?php

namespace KVS\CLI\Command;

use KVS\CLI\Command\Traits\InputHelperTrait;
use KVS\CLI\Config\Configuration;
use KVS\CLI\Constants;
use KVS\CLI\Docker\DockerDetector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class BaseCommand extends Command
{
    use InputHelperTrait;

    protected Configuration $config;
    protected ?SymfonyStyle $io = null;
    private ?DockerDetector $docker = null;

    public function __construct(Configuration $config)
    {
        $this->config = $config;
        parent::__construct();
    }

    /**
     * Get Docker detector instance (lazy-loaded).
     * Automatically sets KVS path for multi-site detection.
     */
    protected function docker(): DockerDetector
    {
        if ($this->docker === null) {
            $this->docker = new DockerDetector();
            // Set KVS path for multi-site volume mount detection
            $kvsPath = $this->config->getKvsPath();
            if ($kvsPath !== '') {
                $this->docker->setKvsPath($kvsPath);
            }
        }
        return $this->docker;
    }

    /**
     * Check if KVS is running in Docker mode.
     */
    protected function isDockerMode(): bool
    {
        return $this->docker()->isKvsInDocker();
    }

    /**
     * Get PHP ini setting (automatically uses Docker if KVS runs there).
     */
    protected function getPhpSetting(string $name): string|false
    {
        if ($this->isDockerMode()) {
            $value = $this->docker()->getPhpIni($name);
            return $value !== null ? $value : false;
        }
        return ini_get($name);
    }

    /**
     * Check if PHP extension is loaded (automatically uses Docker if KVS runs there).
     */
    protected function isExtensionLoaded(string $extension): bool
    {
        if ($this->isDockerMode()) {
            $loaded = $this->docker()->isPhpExtensionLoaded($extension);
            return $loaded ?? false;
        }
        return extension_loaded($extension);
    }

    /**
     * Get PHP version (automatically uses Docker if KVS runs there).
     */
    protected function getKvsPhpVersion(): string
    {
        if ($this->isDockerMode()) {
            $version = $this->docker()->getPhpVersion();
            return $version ?? PHP_VERSION;
        }
        return PHP_VERSION;
    }

    /**
     * Execute PHP code and get result (automatically uses Docker if KVS runs there).
     */
    protected function evalPhp(string $code): ?string
    {
        if ($this->isDockerMode()) {
            return $this->docker()->execPhp($code);
        }

        ob_start();
        try {
            eval($code);
            $output = ob_get_clean();
            return $output !== false && $output !== '' ? $output : null;
        } catch (\Throwable $e) {
            ob_end_clean();
            return null;
        }
    }

    /**
     * Get all loaded PHP extensions (automatically uses Docker if KVS runs there).
     *
     * @return list<string>
     */
    protected function getLoadedExtensions(): array
    {
        if ($this->isDockerMode()) {
            $result = $this->docker()->execPhp('echo implode("\n", get_loaded_extensions());');
            if ($result !== null) {
                return array_values(array_filter(
                    array_map('trim', explode("\n", $result)),
                    static fn(string $s): bool => $s !== ''
                ));
            }
            return [];
        }
        return get_loaded_extensions();
    }

    /**
     * Get OPcache configuration (automatically uses Docker if KVS runs there).
     *
     * @return array<string, mixed>|false
     */
    protected function getOpcacheConfig(): array|false
    {
        if ($this->isDockerMode()) {
            $result = $this->docker()->execPhp(
                'echo function_exists("opcache_get_configuration") ? json_encode(opcache_get_configuration()) : "false";'
            );
            if ($result !== null && $result !== 'false') {
                $decoded = json_decode(trim($result), true);
                /** @var array<string, mixed>|false */
                return is_array($decoded) ? $decoded : false;
            }
            return false;
        }

        if (!function_exists('opcache_get_configuration')) {
            return false;
        }
        $config = opcache_get_configuration();
        if ($config === false) {
            return false;
        }
        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * Get SymfonyStyle IO instance (guaranteed non-null after initialize)
     */
    protected function io(): SymfonyStyle
    {
        assert($this->io !== null, 'io() called before initialize()');
        return $this->io;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);

        if (!$this->config->isKvsInstalled()) {
            $this->io()->warning('KVS installation not found or not properly configured.');
            $this->io()->note('Make sure KVS is installed and the database is configured.');
        }
    }

    /**
     * @param list<string> $args
     */
    protected function executePhpScript(string $scriptPath, array $args = []): ?string
    {
        if (!file_exists($scriptPath)) {
            $this->io()->error("Script not found: $scriptPath");
            return null;
        }

        $command = escapeshellarg($this->config->getPhpPath()) . ' ' . escapeshellarg($scriptPath);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        exec($command . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            $this->io()->error("Script execution failed: " . implode("\n", $output));
            return null;
        }

        return implode("\n", $output);
    }

    /**
     * Run a callback with the native KVS admin include context loaded.
     *
     * @param callable(): mixed $callback
     * @param list<string> $includeFiles
     */
    protected function runWithKvsAdminContext(callable $callback, array $includeFiles = []): mixed
    {
        $kvsPath = $this->config->getKvsPath();
        $adminPath = $kvsPath . '/admin';
        $includePath = $adminPath . '/include';

        if (!is_dir($adminPath)) {
            throw new \RuntimeException(sprintf('KVS admin directory not found: %s', $adminPath));
        }

        $originalDir = getcwd();
        if ($originalDir === false) {
            throw new \RuntimeException('Failed to get current working directory');
        }

        $originalIncludePath = get_include_path();
        $originalSession = $_SESSION ?? null;
        $originalKvsConfig = $GLOBALS['config'] ?? null;

        if (!chdir($adminPath)) {
            throw new \RuntimeException(sprintf('Failed to switch to KVS admin directory: %s', $adminPath));
        }

        try {
            set_include_path($includePath . PATH_SEPARATOR . $originalIncludePath);

            global $config;
            include $includePath . '/setup.php';

            $files = array_values(array_unique(array_merge([
                'setup_db.php',
                'functions_base.php',
                'functions.php',
            ], $includeFiles)));

            foreach ($files as $file) {
                require_once $includePath . '/' . $file;
            }

            $_SESSION['userdata'] = [
                'user_id' => 1,
                'login' => 'kvs-cli',
                'is_superadmin' => 1,
                'content_delete_daily_limit' => PHP_INT_MAX,
            ];

            return $callback();
        } finally {
            set_include_path($originalIncludePath);
            if ($originalSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $originalSession;
            }
            if ($originalKvsConfig === null) {
                unset($GLOBALS['config']);
            } else {
                $GLOBALS['config'] = $originalKvsConfig;
            }
            chdir($originalDir);
        }
    }

    protected function getDatabaseConnection(bool $quiet = false): ?\PDO
    {
        $dbConfig = $this->config->getDatabaseConfig();

        // Validate required configuration keys (password can be empty)
        $requiredKeys = ['host', 'database', 'user'];
        foreach ($requiredKeys as $key) {
            if (!isset($dbConfig[$key]) || $dbConfig[$key] === '') {
                if (!$quiet) {
                    $this->io()->error("Database configuration missing: $key");
                }
                return null;
            }
        }
        // Password must exist but can be empty string
        if (!array_key_exists('password', $dbConfig)) {
            if (!$quiet) {
                $this->io()->error('Database configuration missing: password');
            }
            return null;
        }

        // Try original host first, then fallback to localhost/127.0.0.1 for Docker scenarios
        $hostsToTry = [$dbConfig['host']];

        // If host looks like a Docker hostname (no dots, not localhost/127.0.0.1), add fallbacks
        $originalHost = $dbConfig['host'];
        if (!str_contains($originalHost, '.') && $originalHost !== 'localhost' && $originalHost !== '127.0.0.1') {
            $hostsToTry[] = '127.0.0.1';
            $hostsToTry[] = 'localhost';
        }

        $lastError = null;
        foreach ($hostsToTry as $host) {
            try {
                // Parse host:port format if present
                $port = 3306;
                if (str_contains($host, ':')) {
                    [$host, $portStr] = explode(':', $host, 2);
                    $port = (int) $portStr;
                }

                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=' . Constants::DB_CHARSET,
                    $host,
                    $port,
                    $dbConfig['database']
                );

                return new \PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => 3,
                ]);
            } catch (\PDOException $e) {
                $lastError = $e;
                continue;
            }
        }

        if (!$quiet && $lastError !== null) {
            $this->io()->error('Database connection failed: ' . $lastError->getMessage());
        }
        return null;
    }

    protected function getMysqliConnection(bool $quiet = false): ?\mysqli
    {
        if (!class_exists(\mysqli::class)) {
            if (!$quiet) {
                $this->io()->error('MySQLi extension is not available');
            }
            return null;
        }

        $dbConfig = $this->config->getDatabaseConfig();

        $requiredKeys = ['host', 'database', 'user'];
        foreach ($requiredKeys as $key) {
            if (!isset($dbConfig[$key]) || $dbConfig[$key] === '') {
                if (!$quiet) {
                    $this->io()->error("Database configuration missing: $key");
                }
                return null;
            }
        }
        if (!array_key_exists('password', $dbConfig)) {
            if (!$quiet) {
                $this->io()->error('Database configuration missing: password');
            }
            return null;
        }

        $hostsToTry = [$dbConfig['host']];
        $originalHost = $dbConfig['host'];
        if (!str_contains($originalHost, '.') && $originalHost !== 'localhost' && $originalHost !== '127.0.0.1') {
            $hostsToTry[] = '127.0.0.1';
            $hostsToTry[] = 'localhost';
        }

        $lastError = 'unknown error';
        mysqli_report(MYSQLI_REPORT_OFF);

        foreach ($hostsToTry as $host) {
            $port = 3306;
            if (str_contains($host, ':')) {
                [$host, $portStr] = explode(':', $host, 2);
                $port = (int) $portStr;
            }

            try {
                $mysqli = new \mysqli($host, $dbConfig['user'], $dbConfig['password'], $dbConfig['database'], $port);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                continue;
            }

            if ($mysqli->connect_errno === 0) {
                $mysqli->set_charset(Constants::DB_CHARSET);
                $mysqli->query("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION', SESSION SQL_BIG_SELECTS = 1, SESSION wait_timeout = 3600");
                return $mysqli;
            }

            $lastError = $mysqli->connect_error . ' (' . $mysqli->connect_errno . ')';
            $mysqli->close();
        }

        if (!$quiet) {
            $this->io()->error('Database connection failed: ' . $lastError);
        }
        return null;
    }

    /**
     * Build the KVS-style $config array exposed to eval, eval-file and shell user code.
     *
     * @return array<string, mixed>
     */
    protected function getKvsRuntimeConfig(): array
    {
        $config = $this->config->getProjectConfig();
        $dbConfig = $this->config->getDatabaseConfig();
        $tablePrefix = $this->config->getTablePrefix();
        $multiTablePrefix = $this->config->getMultiTablePrefix();
        $kvsVersion = $this->config->getKvsVersion();

        $config['project_path'] ??= $this->config->getKvsPath();
        $config['project_version'] ??= $kvsVersion !== '' ? $kvsVersion : 'unknown';
        $config['tables_prefix'] ??= $tablePrefix;
        $config['tables_prefix_multi'] ??= $multiTablePrefix;
        $config['is_clone_db'] ??= $tablePrefix === $multiTablePrefix ? 'false' : 'true';

        // Backward-compatible convenience keys previously exposed by eval.
        $config['db_host'] ??= $dbConfig['host'] ?? null;
        $config['db_name'] ??= $dbConfig['database'] ?? null;

        return $config;
    }

    protected function defineKvsDatabaseConstantsForUserCode(): void
    {
        $dbConfig = $this->config->getDatabaseConfig();
        $constants = [
            'DB_HOST' => $dbConfig['host'] ?? null,
            'DB_LOGIN' => $dbConfig['user'] ?? null,
            'DB_PASS' => $dbConfig['password'] ?? null,
            'DB_DEVICE' => $dbConfig['database'] ?? null,
        ];

        foreach ($constants as $name => $value) {
            if (!defined($name) && is_string($value)) {
                define($name, $value);
            }
        }
    }

    /**
     * Parse a status filter from --status, accepting both named aliases and KVS numeric status IDs.
     *
     * @param array<string, int> $aliases
     * @param list<int> $numericStatuses
     */
    protected function parseStatusFilter(InputInterface $input, array $aliases, array $numericStatuses = []): ?int
    {
        $status = $this->getStringOption($input, 'status');
        if ($status === null) {
            return null;
        }

        $statusKey = strtolower(trim($status));
        if (array_key_exists($statusKey, $aliases)) {
            return $aliases[$statusKey];
        }

        if ($numericStatuses === []) {
            $numericStatuses = array_values(array_unique($aliases));
        }

        if (preg_match('/^\d+$/', $statusKey) === 1) {
            $statusId = (int) $statusKey;
            if (in_array($statusId, $numericStatuses, true)) {
                return $statusId;
            }
        }

        $validStatuses = array_keys($aliases);
        foreach ($numericStatuses as $numericStatus) {
            $validStatuses[] = (string) $numericStatus;
        }

        throw new \InvalidArgumentException(sprintf(
            'Invalid status "%s". Valid values: %s',
            $status,
            implode(', ', array_values(array_unique($validStatuses)))
        ));
    }

    protected function getPositiveIntOptionOrDefault(InputInterface $input, string $name, int $default): ?int
    {
        $value = $this->getStringOption($input, $name);
        if ($value === null) {
            return $default;
        }

        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            $this->io()->error(sprintf('Invalid value for --%s (use: integer >= 1)', $name));
            return null;
        }

        return (int) $value;
    }

    /**
     * Render a table with consistent box style
     *
     * @param list<string> $headers
     * @param list<list<string|int|null>> $rows
     */
    protected function renderTable(array $headers, array $rows): void
    {
        $table = new Table($this->io());
        $table->setStyle(Constants::TABLE_STYLE);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
    }

    /**
     * Get prefixed table name (reads tables_prefix from KVS config)
     */
    protected function table(string $name): string
    {
        return $this->config->getTablePrefix() . $name;
    }

    /**
     * Get prefixed table name for multi-site shared tables.
     */
    protected function multiTable(string $name): string
    {
        return $this->config->getMultiTablePrefix() . $name;
    }

    protected function writeAdminAuditLog(
        \PDO $db,
        int $actionId,
        int $objectId,
        int $objectTypeId,
        ?string $actionDetails = null
    ): void {
        $table = $this->table('admin_audit_log');
        $params = [
            'user_id' => 1,
            'username' => 'kvs-cli',
            'action_id' => $actionId,
            'object_id' => $objectId,
            'object_type_id' => $objectTypeId,
            'action_details' => $actionDetails,
            'added_date' => date('Y-m-d H:i:s'),
        ];

        try {
            $stmt = $db->prepare("
                INSERT INTO {$table}
                    (user_id, username, action_id, object_id, object_type_id, action_details, added_date)
                VALUES
                    (:user_id, :username, :action_id, :object_id, :object_type_id, :action_details, :added_date)
            ");
            $stmt->execute($params);
        } catch (\PDOException) {
            unset($params['action_details']);
            try {
                $stmt = $db->prepare("
                    INSERT INTO {$table}
                        (user_id, username, action_id, object_id, object_type_id, added_date)
                    VALUES
                        (:user_id, :username, :action_id, :object_id, :object_type_id, :added_date)
                ");
                $stmt->execute($params);
            } catch (\PDOException) {
                // Audit log table can be absent in minimal test or legacy installs.
            }
        }
    }
}
