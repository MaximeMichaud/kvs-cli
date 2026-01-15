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

        $command = 'php ' . escapeshellarg($scriptPath);
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

    protected function getDatabaseConnection(bool $quiet = false): ?\PDO
    {
        $dbConfig = $this->config->getDatabaseConfig();

        if ($dbConfig === []) {
            if (!$quiet) {
                $this->io()->error('Database configuration not found');
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
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=' . Constants::DB_CHARSET,
                    $host,
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
}
