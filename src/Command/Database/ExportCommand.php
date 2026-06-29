<?php

namespace KVS\CLI\Command\Database;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\SecureFileTrait;
use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'db:export',
    description: 'Export KVS database',
    aliases: ['database:export', 'db:dump']
)]
class ExportCommand extends BaseCommand
{
    use SecureFileTrait;

    private ?\PDO $tableLookupConnection = null;

    /** @var array<string, bool> */
    private array $tableLookupCache = [];

    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path')
            ->addOption('tables', null, InputOption::VALUE_REQUIRED, 'Specific tables to export (comma-separated)')
            ->addOption('no-data', null, InputOption::VALUE_NONE, 'Export structure only')
            ->addOption('compress', null, InputOption::VALUE_OPTIONAL, 'Compression format: gzip, zstd, xz, bzip2', false)
            ->setHelp(<<<'EOT'
Export KVS database to SQL file.

<info>Compression options:</info>
  --compress=gzip   Use gzip compression (default if --compress specified)
  --compress=zstd   Use Zstandard compression (faster, better ratio)
  --compress=xz     Use XZ compression (best ratio, slower)
  --compress=bzip2  Use bzip2 compression

<info>Examples:</info>
  kvs db:export                           # Export to timestamped file
  kvs db:export -o backup.sql             # Export to specific file
  kvs db:export --compress=zstd           # Export with zstd compression
  kvs db:export --no-data                 # Export structure only
  kvs db:export --tables=videos,users     # Export specific tables
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbConfig = $this->normalizeDatabaseConfig($this->config->getDatabaseConfig());
        if ($dbConfig === null) {
            $this->io()->error('Database configuration not found');
            return self::FAILURE;
        }

        // Detect dump command (mariadb-dump or mysqldump)
        $dumpCommand = $this->getDumpCommand();
        if ($dumpCommand === null || $dumpCommand === '') {
            $this->io()->error('Database dump command not found');
            $this->io()->newLine();
            $this->io()->text('<comment>Install one of:</comment>');
            $this->io()->text('  • <info>apt install mariadb-client</info>  (for MariaDB)');
            $this->io()->text('  • <info>apt install mysql-client</info>    (for MySQL)');
            return self::FAILURE;
        }

        $this->io()->comment("Using: $dumpCommand");

        // Handle compression
        $compressFormatRaw = $input->getOption('compress');
        $compressor = null;
        $fileExtension = '.sql';

        if ($compressFormatRaw !== false) {
            // If --compress is passed without value, default to gzip
            // PHPStan: VALUE_OPTIONAL with default false = false|null|string
            if ($compressFormatRaw === null) {
                $compressFormat = 'gzip';
            } else {
                // At this point PHPStan knows it's a string
                $compressFormat = $compressFormatRaw;
            }

            $compressor = $this->getCompressor($compressFormat);
            if ($compressor === null) {
                $this->io()->error("Compression format '$compressFormat' not supported or command not found");
                $this->io()->newLine();
                $this->io()->text('<comment>Available formats:</comment>');
                $this->io()->text('  • gzip  - Standard compression (best compatibility)');
                $this->io()->text('  • zstd  - Modern compression (fast, great ratio)');
                $this->io()->text('  • xz    - Maximum compression (slower)');
                $this->io()->text('  • bzip2 - Good compression');
                $this->io()->newLine();
                $this->io()->text('<comment>Install missing tools:</comment>');
                $this->io()->text('  apt install gzip zstd xz-utils bzip2');
                return self::FAILURE;
            }

            $fileExtension .= '.' . $compressor['extension'];
            $this->io()->comment("Compression: $compressFormat");
        }

        $outputFile = $this->getStringOption($input, 'output') ?? sprintf(
            'kvs_backup_%s%s',
            date('Y-m-d_H-i-s'),
            $fileExtension
        );
        if (!$this->validateOutputFile($outputFile)) {
            return self::FAILURE;
        }

        $this->io()->info('Starting database export...');

        // Parse host and port
        $host = $dbConfig['host'];
        $port = (string) Constants::DEFAULT_MYSQL_PORT;
        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        $command = [
            $dumpCommand,
            '-h', $host,
            '-P', $port,
            '-u', $dbConfig['user'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--default-character-set=' . Constants::DB_CHARSET,
        ];

        $env = $this->createProcessEnvironment($dbConfig['password']);

        if ($this->getBoolOption($input, 'no-data')) {
            $command[] = '--no-data';
        }

        $tables = $this->parseTablesOption($this->getStringOption($input, 'tables'), $dbConfig);
        if ($tables === false) {
            return self::FAILURE;
        }
        if ($tables !== null) {
            $command[] = $dbConfig['database'];
            foreach ($tables as $table) {
                $command[] = $table;
            }
        } else {
            $command[] = $dbConfig['database'];
        }

        if ($compressor !== null) {
            $this->io()->info("Streaming export through $compressFormat...");
        }

        $process = $this->createStreamingExportProcess($command, $env, $outputFile, $compressor);
        $exportSucceeded = $this->runStreamingExport($process, $outputFile);

        if ($exportSucceeded === false) {
            $this->io()->error('Database export failed');
            $this->io()->newLine();

            $errorOutput = trim($process->getErrorOutput());
            if ($errorOutput !== '') {
                $this->io()->text('<error>Error details:</error>');
                $this->io()->text($errorOutput);
            }

            // Common error hints
            $this->io()->newLine();
            $this->io()->text('<comment>Common issues:</comment>');
            $this->io()->text('  • Wrong database credentials in setup_db.php');
            $this->io()->text('  • Database server not running');
            $this->io()->text('  • Insufficient permissions');

            return self::FAILURE;
        }

        $fileSize = filesize($outputFile);
        if ($fileSize === false) {
            $this->io()->error('Failed to get file size');
            return self::FAILURE;
        }

        $this->io()->success(sprintf(
            'Database exported successfully to %s (%.2f MB)',
            $outputFile,
            $fileSize / 1024 / 1024
        ));

        return self::SUCCESS;
    }

    private function validateOutputFile(string $outputFile): bool
    {
        if (trim($outputFile) === '') {
            $this->io()->error('The --output option cannot be empty');
            return false;
        }

        if (is_dir($outputFile)) {
            $this->io()->error("Output path is a directory: $outputFile");
            return false;
        }

        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            $this->io()->error("Output directory does not exist: $outputDir");
            return false;
        }

        if (!is_writable($outputDir)) {
            $this->io()->error("Output directory is not writable: $outputDir");
            return false;
        }

        if (is_file($outputFile) && !is_writable($outputFile)) {
            $this->io()->error("Output file is not writable: $outputFile");
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $dbConfig
     * @return array{host: string, user: string, password: string, database: string}|null
     */
    private function normalizeDatabaseConfig(array $dbConfig): ?array
    {
        $host = $dbConfig['host'] ?? null;
        $user = $dbConfig['user'] ?? null;
        $password = $dbConfig['password'] ?? null;
        $database = $dbConfig['database'] ?? null;

        if (
            !is_string($host) || $host === ''
            || !is_string($user) || $user === ''
            || !is_string($password)
            || !is_string($database) || $database === ''
        ) {
            return null;
        }

        return [
            'host' => $host,
            'user' => $user,
            'password' => $password,
            'database' => $database,
        ];
    }

    /**
     * @param array{host: string, user: string, password: string, database: string} $dbConfig
     * @return list<string>|false|null
     */
    private function parseTablesOption(?string $tables, array $dbConfig): array|false|null
    {
        if ($tables === null) {
            return null;
        }

        if (trim($tables) === '') {
            $this->io()->error('The --tables option cannot be empty');
            return false;
        }

        $resolvedTables = [];
        $knownTables = $this->getKnownKvsTableMap();
        foreach (explode(',', $tables) as $rawTable) {
            $table = trim($rawTable);
            if ($table === '') {
                $this->io()->error('The --tables option contains an empty table name');
                return false;
            }
            $resolvedTables[] = $this->resolveTableName($table, $knownTables, $dbConfig);
        }

        return $resolvedTables;
    }

    /**
     * @param array<string, string> $knownTables
     * @param array{host: string, user: string, password: string, database: string} $dbConfig
     */
    private function resolveTableName(string $table, array $knownTables, array $dbConfig): string
    {
        if (isset($knownTables[$table])) {
            return $knownTables[$table];
        }

        if ($this->tableExistsForExport($table, $dbConfig)) {
            return $table;
        }

        $candidates = array_values(array_unique([
            $this->config->getTablePrefix() . $table,
            $this->config->getMultiTablePrefix() . $table,
        ]));

        foreach ($candidates as $candidate) {
            if ($candidate !== $table && $this->tableExistsForExport($candidate, $dbConfig)) {
                return $candidate;
            }
        }

        return $table;
    }

    /**
     * @return array<string, string>
     */
    private function getKnownKvsTableMap(): array
    {
        $databaseTablesFile = $this->config->getAdminPath() . '/include/database_tables.php';
        if (!is_file($databaseTablesFile)) {
            return [];
        }

        $contents = file_get_contents($databaseTablesFile);
        if ($contents === false) {
            return [];
        }

        $map = [];
        $pattern = '/\\$database_tables\\[\\]\\s*=\\s*"\\$config\\[(tables_prefix|tables_prefix_multi)\\]([a-z0-9_]+)"/';
        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $match) {
            $prefix = $match[1] === 'tables_prefix_multi'
                ? $this->config->getMultiTablePrefix()
                : $this->config->getTablePrefix();
            $shortName = $match[2];
            $map[$shortName] = $prefix . $shortName;
        }

        return $map;
    }

    /**
     * @param array{host: string, user: string, password: string, database: string} $dbConfig
     */
    protected function tableExistsForExport(string $table, array $dbConfig): bool
    {
        if (array_key_exists($table, $this->tableLookupCache)) {
            return $this->tableLookupCache[$table];
        }

        try {
            $connection = $this->tableLookupConnection ??= $this->getDatabaseConnection(true);
            if ($connection === null) {
                return $this->tableLookupCache[$table] = false;
            }

            $statement = $connection->prepare(
                'select table_name from information_schema.tables where table_schema = :schema and table_name = :table limit 1'
            );
            $statement->execute([
                'schema' => $dbConfig['database'],
                'table' => $table,
            ]);

            return $this->tableLookupCache[$table] = $statement->fetchColumn() !== false;
        } catch (\PDOException) {
            return $this->tableLookupCache[$table] = false;
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string|false> $env
     * @param array{command: string, test: string, extension: string}|null $compressor
     */
    private function createStreamingExportProcess(
        array $command,
        array $env,
        string $outputFile,
        ?array $compressor
    ): Process {
        $dumpCommand = implode(' ', array_map('escapeshellarg', $command));
        $outputRedirect = '> ' . escapeshellarg($outputFile);
        $shellCommand = $compressor === null
            ? "{$dumpCommand} {$outputRedirect}"
            : "{$dumpCommand} | " . escapeshellarg($compressor['command']) . " {$outputRedirect}";

        $process = new Process(['bash', '-o', 'pipefail', '-c', $shellCommand], null, $env);
        $process->setTimeout(Constants::DB_PROCESS_TIMEOUT);

        return $process;
    }

    private function runStreamingExport(Process $process, string $outputFile): bool
    {
        $progressBar = $this->io()->createProgressBar();
        $progressBar->start();

        $this->withSecureFileUmask(function () use ($process, $outputFile, $progressBar): void {
            if (is_file($outputFile)) {
                @unlink($outputFile);
            }

            $process->run(function () use ($progressBar): void {
                $progressBar->advance();
            });
        });

        $progressBar->finish();
        $this->io()->newLine();

        if (!$process->isSuccessful()) {
            if (is_file($outputFile)) {
                @unlink($outputFile);
            }
            return false;
        }

        return $this->restrictFilePermissions($outputFile);
    }

    /**
     * @return array<string, string|false>
     */
    private function createProcessEnvironment(string $password): array
    {
        $environment = [];

        foreach ([$_ENV, getenv()] as $source) {
            foreach ($source as $key => $value) {
                if (is_string($key) && (is_string($value) || $value === false)) {
                    $environment[$key] = $value;
                }
            }
        }

        $environment['MYSQL_PWD'] = $password;

        return $environment;
    }

    /**
     * Detect database dump command
     */
    private function getDumpCommand(): ?string
    {
        $executableFinder = new ExecutableFinder();

        // First, detect database type by running mysql --version
        $mysqlPath = $executableFinder->find('mysql');

        if ($mysqlPath !== null) {
            $versionProcess = new Process([$mysqlPath, '--version']);
            $versionProcess->setTimeout(10);
            $versionProcess->run();

            // Check if it's MariaDB
            if (
                $versionProcess->isSuccessful()
                && stripos($versionProcess->getOutput() . $versionProcess->getErrorOutput(), 'MariaDB') !== false
            ) {
                // Try mariadb-dump first
                $mariadbDump = $executableFinder->find('mariadb-dump');
                if ($mariadbDump !== null) {
                    return $mariadbDump;
                }
            }
        }

        return $this->tryAlternateDumpCommands($executableFinder);
    }

    /**
     * Try alternate dump commands
     */
    private function tryAlternateDumpCommands(ExecutableFinder $executableFinder): ?string
    {
        // Fallback to mysqldump
        $mysqldump = $executableFinder->find('mysqldump');
        if ($mysqldump !== null) {
            return $mysqldump;
        }

        // Try mariadb-dump as last resort
        $mariadbDump = $executableFinder->find('mariadb-dump');
        if ($mariadbDump !== null) {
            return $mariadbDump;
        }

        return null;
    }

    /**
     * Get compressor command and validate it's available
     *
     * @return array{command: string, test: string, extension: string}|null
     */
    private function getCompressor(string $format): ?array
    {
        $compressors = [
            'gzip' => ['command' => 'gzip', 'test' => 'gzip --version', 'extension' => 'gz'],
            'zstd' => ['command' => 'zstd', 'test' => 'zstd --version', 'extension' => 'zst'],
            'xz' => ['command' => 'xz', 'test' => 'xz --version', 'extension' => 'xz'],
            'bzip2' => ['command' => 'bzip2', 'test' => 'bzip2 --version', 'extension' => 'bz2'],
        ];

        if (!isset($compressors[$format])) {
            return null;
        }

        $compressor = $compressors[$format];

        $path = (new ExecutableFinder())->find($compressor['command']);
        if ($path === null) {
            return null;
        }

        $compressor['command'] = $path;

        return $compressor;
    }
}
