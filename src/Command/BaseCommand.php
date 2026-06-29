<?php

namespace KVS\CLI\Command;

use KVS\CLI\Command\Traits\InputHelperTrait;
use KVS\CLI\Config\Configuration;
use KVS\CLI\Constants;
use KVS\CLI\Docker\DockerDetector;
use KVS\CLI\Output\Formatter;
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
            $config = array_replace(is_array($config ?? null) ? $config : [], $this->getKvsRuntimeConfig());

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

        $hostsToTry = $this->getDatabaseHostsToTry($dbConfig['host']);

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

        $hostsToTry = $this->getDatabaseHostsToTry($dbConfig['host']);

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
     * Database host fallback is handled while loading Configuration, where it can
     * be based on DNS resolution instead of failed authentication attempts.
     *
     * @return list<string>
     */
    protected function getDatabaseHostsToTry(string $configuredHost): array
    {
        return [$configuredHost];
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

        $config['project_path'] = $this->config->getKvsPath();
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

    /**
     * Parse a status filter and render CLI-friendly validation errors.
     *
     * @param array<string, int> $aliases
     * @param list<int> $numericStatuses
     * @return int|false|null False means validation failed.
     */
    protected function parseStatusFilterOrFail(InputInterface $input, array $aliases, array $numericStatuses = []): int|false|null
    {
        try {
            return $this->parseStatusFilter($input, $aliases, $numericStatuses);
        } catch (\InvalidArgumentException $e) {
            $this->io()->error($e->getMessage());
            return false;
        }
    }

    protected function containsLikePattern(string $value): string
    {
        return '%' . $this->escapeLikePattern($value) . '%';
    }

    protected function likeEscapeSql(): string
    {
        return " ESCAPE '!'";
    }

    /**
     * @param list<string> $likeColumns
     * @param array<string, int|string> $params
     */
    protected function buildAdminSearchCondition(
        string $idColumn,
        array $likeColumns,
        string $search,
        array &$params,
        string $paramPrefix = 'search'
    ): string {
        $conditions = [];
        $trimmedSearch = trim($search);

        if (preg_match('/^([\ ]*[0-9]+[\ ]*,[\ ]*)+[0-9]+[\ ]*$/', $trimmedSearch) === 1) {
            $ids = array_values(array_unique(array_map('intval', array_map('trim', explode(',', $trimmedSearch)))));
            $idParams = [];
            foreach ($ids as $index => $id) {
                $key = "{$paramPrefix}_id_{$index}";
                $params[$key] = $id;
                $idParams[] = ":{$key}";
            }
            $conditions[] = "{$idColumn} IN (" . implode(', ', $idParams) . ')';
        } elseif (preg_match('/^\d+$/', $trimmedSearch) === 1) {
            $key = "{$paramPrefix}_id";
            $params[$key] = (int) $trimmedSearch;
            $conditions[] = "{$idColumn} = :{$key}";
        }

        if ($likeColumns !== []) {
            $key = "{$paramPrefix}_like";
            $params[$key] = $this->containsLikePattern($search);
            $likeEscape = $this->likeEscapeSql();
            foreach ($likeColumns as $column) {
                $conditions[] = "{$column} LIKE :{$key}{$likeEscape}";
            }
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    private function escapeLikePattern(string $value): string
    {
        return str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $value
        );
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

    protected function getOptionalPositiveIntOption(InputInterface $input, string $name): int|false|null
    {
        $value = $this->getStringOption($input, $name);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            $this->io()->error(sprintf('Invalid value for --%s (use: integer >= 1)', $name));
            return false;
        }

        return (int) $value;
    }

    protected function getOptionalNonNegativeIntOption(InputInterface $input, string $name): int|false|null
    {
        $value = $this->getStringOption($input, $name);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            $this->io()->error(sprintf('Invalid value for --%s (use: integer >= 0)', $name));
            return false;
        }

        return (int) $value;
    }

    protected function resolveUserIdOption(\PDO $db, InputInterface $input, string $name = 'user'): int|false|null
    {
        return $this->resolveReferenceIdOption($db, $input, $name, 'users', 'user_id', 'username');
    }

    protected function resolveCategoryIdOption(\PDO $db, InputInterface $input, string $name = 'category'): int|false|null
    {
        return $this->resolveReferenceIdOption($db, $input, $name, 'categories', 'category_id', 'title');
    }

    protected function resolveCategoryGroupIdOption(
        \PDO $db,
        InputInterface $input,
        string $name = 'category-group'
    ): int|false|null {
        return $this->resolveReferenceIdOption(
            $db,
            $input,
            $name,
            'categories_groups',
            'category_group_id',
            'title'
        );
    }

    protected function resolveContentSourceIdOption(
        \PDO $db,
        InputInterface $input,
        string $name = 'content-source'
    ): int|false|null {
        return $this->resolveReferenceIdOption(
            $db,
            $input,
            $name,
            'content_sources',
            'content_source_id',
            'title'
        );
    }

    protected function resolveDvdIdOption(\PDO $db, InputInterface $input, string $name = 'dvd'): int|false|null
    {
        return $this->resolveReferenceIdOption($db, $input, $name, 'dvds', 'dvd_id', 'title');
    }

    protected function resolveModelIdOption(\PDO $db, InputInterface $input, string $name = 'model'): int|false|null
    {
        return $this->resolveReferenceIdOption($db, $input, $name, 'models', 'model_id', 'title');
    }

    protected function resolvePlaylistIdOption(\PDO $db, InputInterface $input, string $name = 'playlist'): int|false|null
    {
        return $this->resolveReferenceIdOption($db, $input, $name, 'playlists', 'playlist_id', 'title');
    }

    protected function resolveTagIdOption(\PDO $db, InputInterface $input, string $name = 'tag'): int|false|null
    {
        return $this->resolveReferenceIdOption($db, $input, $name, 'tags', 'tag_id', 'tag');
    }

    protected function findReferenceIdByText(
        \PDO $db,
        string $table,
        string $idColumn,
        string $textColumn,
        string $value
    ): ?int {
        $stmt = $db->prepare(sprintf(
            'SELECT %s FROM %s WHERE %s = :value LIMIT 1',
            $idColumn,
            $this->table($table),
            $textColumn
        ));
        $stmt->execute(['value' => $value]);

        $id = $stmt->fetchColumn();
        return is_numeric($id) ? (int) $id : null;
    }

    private function resolveReferenceIdOption(
        \PDO $db,
        InputInterface $input,
        string $name,
        string $table,
        string $idColumn,
        string $textColumn
    ): int|false|null {
        $value = $this->getStringOption($input, $name);
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            $this->io()->error(sprintf('Invalid value for --%s (use: integer >= 0 or name)', $name));
            return false;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
            $this->io()->error(sprintf('Invalid value for --%s (use: integer >= 0 or name)', $name));
            return false;
        }

        return $this->findReferenceIdByText($db, $table, $idColumn, $textColumn, $value) ?? 0;
    }

    protected function getRequiredPositiveId(?string $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            $this->io()->error(sprintf('%s ID is required', $label));
            return null;
        }

        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            $this->io()->error(sprintf('Invalid %s ID (use: integer >= 1)', $label));
            return null;
        }

        return (int) $value;
    }

    /**
     * @param list<string> $optionNames
     */
    protected function hasConflictingBoolOptions(InputInterface $input, array $optionNames): bool
    {
        $enabled = [];
        foreach ($optionNames as $name) {
            if ($this->getBoolOption($input, $name)) {
                $enabled[] = '--' . $name;
            }
        }

        if (count($enabled) < 2) {
            return false;
        }

        $last = array_pop($enabled);
        $this->io()->error(sprintf(
            'Options %s and %s cannot be used together',
            implode(', ', $enabled),
            $last
        ));
        return true;
    }

    /**
     * @param list<string> $optionNames
     */
    protected function rejectUnsupportedOptionsForAction(
        InputInterface $input,
        string $action,
        array $optionNames
    ): bool {
        if ($this->getStringArgument($input, 'action') !== $action) {
            return false;
        }

        return $this->rejectUnsupportedOptions($input, $action, $optionNames);
    }

    /**
     * @param list<string> $optionNames
     */
    protected function rejectUnsupportedOptions(
        InputInterface $input,
        string $action,
        array $optionNames
    ): bool {
        foreach ($optionNames as $name) {
            if (!$this->isOptionExplicitlySet($input, $name)) {
                continue;
            }

            $this->io()->error(sprintf(
                'The %s action does not support --%s. Remove --%s or use an action that supports it.',
                $action,
                $name,
                $name
            ));

            return true;
        }

        return false;
    }

    protected function isOptionExplicitlySet(InputInterface $input, string $name): bool
    {
        if (!$input->hasOption($name)) {
            return false;
        }

        if ($input->hasParameterOption('--' . $name)) {
            return true;
        }

        $value = $input->getOption($name);
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return false;
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

    protected function isTableFormat(InputInterface $input): bool
    {
        return $this->getStringOptionOrDefault($input, 'format', 'table') === 'table';
    }

    /**
     * @param list<string> $allowedFormats
     */
    protected function validateOutputFormat(InputInterface $input, array $allowedFormats): ?string
    {
        $format = $this->getStringOptionOrDefault($input, 'format', 'table');
        if (!in_array($format, $allowedFormats, true)) {
            $this->io()->error(sprintf(
                'Invalid value for --format "%s" (expected: %s)',
                $format,
                implode(', ', $allowedFormats)
            ));
            return null;
        }

        return $format;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $defaultFields
     */
    protected function displayFormattedRows(InputInterface $input, array $rows, array $defaultFields): int
    {
        try {
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($rows, $this->io());
            return self::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $this->io()->error($e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param list<list<mixed>> $rows
     * @param array<string, mixed> $extraFields
     */
    protected function displayDetailRows(InputInterface $input, array $rows, array $extraFields = []): int
    {
        $record = [];
        foreach ($rows as $row) {
            if (!isset($row[0])) {
                continue;
            }

            $label = is_scalar($row[0]) ? (string) $row[0] : '';
            $key = $this->detailLabelToKey($label);
            if ($key === '') {
                continue;
            }

            $record[$key] = $this->stripConsoleMarkup($row[1] ?? '');
        }

        foreach ($extraFields as $key => $value) {
            $record[$key] = is_string($value) ? $this->stripConsoleMarkup($value) : $value;
        }

        return $this->displayFormattedRows($input, [$record], array_keys($record));
    }

    private function detailLabelToKey(string $label): string
    {
        $key = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', trim($label)));
        return trim($key, '_');
    }

    protected function stripConsoleMarkup(mixed $value): mixed
    {
        if (!is_scalar($value) && $value !== null) {
            return $value;
        }

        $text = (string) $value;
        return preg_replace('/<\\/?(?:fg|bg|options)(?:=[^>]*)?>|<\\/>/', '', $text) ?? $text;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function buildKvsWebsiteLink(array $row, string $idField, string $patternKey): string
    {
        $dir = $row['dir'] ?? null;
        if (!is_scalar($dir) || (string) $dir === '') {
            return '';
        }

        $statusId = $row['status_id'] ?? null;
        $statusId = is_numeric($statusId) ? (int) $statusId : 0;
        $websiteUiParams = $this->loadKvsSystemSettingsFile('website_ui_params.dat');

        $allowedStatuses = [0, 1, 5];
        $disabledAvailability = $websiteUiParams['DISABLED_CONTENT_AVAILABILITY'] ?? null;
        if (is_numeric($disabledAvailability) && (int) $disabledAvailability === 2) {
            $allowedStatuses = [0, 1, 2, 3, 5];
        }
        if (!in_array($statusId, $allowedStatuses, true)) {
            return '';
        }

        $id = $row[$idField] ?? null;
        $pattern = $websiteUiParams[$patternKey] ?? null;
        $projectUrl = $this->config->get('project_url', '');
        if (!is_scalar($id) || !is_scalar($pattern) || (string) $pattern === '' || !is_scalar($projectUrl)) {
            return '';
        }

        $path = str_replace(
            ['%ID%', '%DIR%'],
            [(string) $id, (string) $dir],
            (string) $pattern
        );

        return (string) $projectUrl . '/' . $path;
    }

    /**
     * @param array<string, int|string> $params
     */
    protected function buildKvsWebsiteLinkSearchCondition(
        string $idColumn,
        string $dirColumn,
        string $patternKey,
        string $search,
        array &$params,
        string $paramPrefix
    ): ?string {
        if (filter_var($search, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $websiteUiParams = $this->loadKvsSystemSettingsFile('website_ui_params.dat');
        $pattern = $websiteUiParams[$patternKey] ?? null;
        if (!is_scalar($pattern) || (string) $pattern === '') {
            return null;
        }

        $regex = preg_quote((string) $pattern, '|');
        $regex = str_replace(
            [preg_quote('%ID%', '|'), preg_quote('%DIR%', '|')],
            ['(?P<kvs_id>[0-9]+)', '(?P<kvs_dir>.+?)'],
            $regex
        );

        if (preg_match("|{$regex}|i", $search, $matches) !== 1) {
            return null;
        }

        if (isset($matches['kvs_id']) && is_numeric($matches['kvs_id']) && (int) $matches['kvs_id'] > 0) {
            $key = "{$paramPrefix}_id";
            $params[$key] = (int) $matches['kvs_id'];
            return "{$idColumn} = :{$key}";
        }

        if (isset($matches['kvs_dir']) && trim($matches['kvs_dir']) !== '') {
            $key = "{$paramPrefix}_dir";
            $params[$key] = trim($matches['kvs_dir']);
            return "{$dirColumn} = :{$key}";
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadKvsSystemSettingsFile(string $filename): array
    {
        $file = $this->config->getKvsPath() . '/admin/data/system/' . $filename;
        if (!is_file($file)) {
            return [];
        }

        $content = file_get_contents($file);
        if ($content === false || $content === '') {
            return [];
        }

        $result = @unserialize($content, ['allowed_classes' => false]);
        if (!is_array($result)) {
            return [];
        }

        $settings = [];
        foreach ($result as $key => $value) {
            if (is_string($key)) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    protected function displayMetricRows(InputInterface $input, array $rows): void
    {
        $formatter = new Formatter(
            $input->getOptions(),
            ['section', 'metric', 'value', 'display_value', 'label']
        );
        $formatter->display($rows, $this->io());
    }

    /**
     * @return array{section: string, metric: string, value: mixed, display_value: string, label: string}
     */
    protected function metricRow(
        string $section,
        string $metric,
        mixed $value,
        ?string $displayValue = null,
        string $label = ''
    ): array {
        return [
            'section' => $section,
            'metric' => $metric,
            'value' => $value,
            'display_value' => $displayValue ?? (is_scalar($value) || $value === null ? (string) $value : ''),
            'label' => $label,
        ];
    }

    protected function formatKvsIp(mixed $value): string
    {
        if (!is_scalar($value) || $value === '') {
            return '';
        }

        if (is_string($value) && (str_contains($value, '.') || str_contains($value, ':'))) {
            return $value;
        }

        if (!is_numeric($value)) {
            return (string) $value;
        }

        $ip = (int) $value;
        if ($ip > 4294967295) {
            $parts = [];
            $parts[0] = intdiv($ip, 65536 * 65536 * 65536);
            $parts[1] = intdiv($ip - $parts[0] * 65536 * 65536 * 65536, 65536 * 65536);
            $parts[2] = intdiv($ip - $parts[0] * 65536 * 65536 * 65536 - $parts[1] * 65536 * 65536, 65536);
            $parts[3] = $ip - $parts[0] * 65536 * 65536 * 65536 - $parts[1] * 65536 * 65536 - $parts[2] * 65536;
            $parts = array_map('dechex', $parts);
            $parts[1] = str_pad($parts[1], 4, '0', STR_PAD_LEFT);
            $parts[2] = str_pad($parts[2], 4, '0', STR_PAD_LEFT);

            return "0:0:0:0:{$parts[0]}:{$parts[1]}:{$parts[2]}:{$parts[3]}";
        }

        $first = intdiv($ip, 256 * 256 * 256);
        $second = intdiv($ip - $first * 256 * 256 * 256, 256 * 256);
        $third = intdiv($ip - $first * 256 * 256 * 256 - $second * 256 * 256, 256);
        $fourth = $ip - $first * 256 * 256 * 256 - $second * 256 * 256 - $third * 256;

        return "$first.$second.$third.$fourth";
    }

    /**
     * @param list<string> $availableActions
     */
    protected function failUnknownAction(string $command, string $action, array $availableActions): int
    {
        $this->io()->error(sprintf(
            'Unknown %s action "%s". Available actions: %s.',
            $command,
            $action,
            implode(', ', $availableActions)
        ));

        return self::FAILURE;
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
