<?php

namespace KVS\CLI\Command\Database;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'db:export',
    description: 'Export KVS database',
    aliases: ['database:export', 'db:dump']
)]
class ExportCommand extends BaseCommand
{
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
        $dbConfig = $this->config->getDatabaseConfig();

        if ($dbConfig === []) {
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

            $fileExtension .= '.' . $compressFormat;
            $this->io()->comment("Compression: $compressFormat");
        }

        $outputFile = $this->getStringOption($input, 'output') ?? sprintf(
            'kvs_backup_%s%s',
            date('Y-m-d_H-i-s'),
            $fileExtension
        );

        $this->io()->info('Starting database export...');

        // Parse host and port
        $host = $dbConfig['host'];
        $port = Constants::DEFAULT_MYSQL_PORT;
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

        // Merge with current env to preserve PATH, etc.
        $env = array_merge($_ENV, getenv(), ['MYSQL_PWD' => $dbConfig['password']]);

        if ($this->getBoolOption($input, 'no-data')) {
            $command[] = '--no-data';
        }

        $tables = $this->getStringOption($input, 'tables');
        if ($tables !== null) {
            $command[] = $dbConfig['database'];
            $command = array_merge($command, explode(',', $tables));
        } else {
            $command[] = $dbConfig['database'];
        }

        $process = new Process($command, null, $env);
        $process->setTimeout(3600);

        $progressBar = $this->io()->createProgressBar();
        $progressBar->start();

        $process->run(function ($type, $buffer) use ($progressBar) {
            $progressBar->advance();
        });

        $progressBar->finish();
        $this->io()->newLine();

        if (!$process->isSuccessful()) {
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

        $sqlContent = $process->getOutput();

        // Compress if requested
        if ($compressor !== null) {
            $this->io()->info("Compressing with $compressFormat...");
            $compressProcess = new Process([$compressor['command']]);
            $compressProcess->setInput($sqlContent);
            $compressProcess->run();

            if (!$compressProcess->isSuccessful()) {
                $this->io()->error("Compression failed");
                return self::FAILURE;
            }

            file_put_contents($outputFile, $compressProcess->getOutput());
        } else {
            file_put_contents($outputFile, $sqlContent);
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

    /**
     * Detect database dump command
     */
    private function getDumpCommand(): ?string
    {
        // First, detect database type by running mysql --version
        $mysqlPathResult = shell_exec('which mysql 2>/dev/null');
        if ($mysqlPathResult === null || $mysqlPathResult === false) {
            return $this->tryAlternateDumpCommands();
        }
        $mysqlPath = trim($mysqlPathResult);

        if ($mysqlPath !== '') {
            $versionOutputResult = shell_exec("$mysqlPath --version 2>/dev/null");
            if ($versionOutputResult === null || $versionOutputResult === false) {
                return $this->tryAlternateDumpCommands();
            }
            $versionOutput = trim($versionOutputResult);

            // Check if it's MariaDB
            if (stripos($versionOutput, 'MariaDB') !== false) {
                // Try mariadb-dump first
                $mariadbDumpResult = shell_exec('which mariadb-dump 2>/dev/null');
                if ($mariadbDumpResult !== null && $mariadbDumpResult !== false) {
                    $mariadbDump = trim($mariadbDumpResult);
                    if ($mariadbDump !== '') {
                        return $mariadbDump;
                    }
                }
            }
        }

        return $this->tryAlternateDumpCommands();
    }

    /**
     * Try alternate dump commands
     */
    private function tryAlternateDumpCommands(): ?string
    {
        // Fallback to mysqldump
        $mysqldumpResult = shell_exec('which mysqldump 2>/dev/null');
        if ($mysqldumpResult !== null && $mysqldumpResult !== false) {
            $mysqldump = trim($mysqldumpResult);
            if ($mysqldump !== '') {
                return $mysqldump;
            }
        }

        // Try mariadb-dump as last resort
        $mariadbDumpResult = shell_exec('which mariadb-dump 2>/dev/null');
        if ($mariadbDumpResult !== null && $mariadbDumpResult !== false) {
            $mariadbDump = trim($mariadbDumpResult);
            if ($mariadbDump !== '') {
                return $mariadbDump;
            }
        }

        return null;
    }

    /**
     * Get compressor command and validate it's available
     *
     * @return array{command: string, test: string}|null
     */
    private function getCompressor(string $format): ?array
    {
        $compressors = [
            'gzip' => ['command' => 'gzip', 'test' => 'gzip --version'],
            'zstd' => ['command' => 'zstd', 'test' => 'zstd --version'],
            'xz' => ['command' => 'xz', 'test' => 'xz --version'],
            'bzip2' => ['command' => 'bzip2', 'test' => 'bzip2 --version'],
        ];

        if (!isset($compressors[$format])) {
            return null;
        }

        $compressor = $compressors[$format];

        // Check if command exists
        $pathResult = shell_exec("which {$compressor['command']} 2>/dev/null");
        if ($pathResult === null || $pathResult === false) {
            return null;
        }
        $path = trim($pathResult);
        if ($path === '') {
            return null;
        }

        return $compressor;
    }
}
