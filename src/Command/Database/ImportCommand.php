<?php

namespace KVS\CLI\Command\Database;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'db:import',
    description: 'Import database from SQL file',
    aliases: ['database:import', 'db:restore']
)]
class ImportCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'SQL file to import (supports .sql, .gz, .gzip, .zst, .zstd, .xz, .bz2, .bzip2)')
            ->setHelp(<<<'EOT'
Import KVS database from SQL file.

<info>Supported formats:</info>
  • Uncompressed SQL (.sql)
  • Gzip compressed (.gz, .gzip)
  • Zstandard compressed (.zst, .zstd)
  • XZ compressed (.xz)
  • Bzip2 compressed (.bz2, .bzip2)

<info>Examples:</info>
  kvs db:import backup.sql              # Import uncompressed SQL
  kvs db:import backup.sql.gz            # Import gzip compressed
  kvs db:import backup.sql.zstd          # Import zstd compressed
  kvs db:import backup.sql.xz            # Import xz compressed
  kvs db:import backup.sql.bz2           # Import bzip2 compressed

<comment>Warning:</comment> This will overwrite all existing data in the database!
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->getStringArgument($input, 'file');

        if ($file === null || trim($file) === '') {
            $this->io()->error('Import file path cannot be empty');
            return self::FAILURE;
        }

        if (!file_exists($file)) {
            $this->io()->error("File not found: $file");
            return self::FAILURE;
        }

        if (is_dir($file)) {
            $this->io()->error("Import path is a directory: $file");
            return self::FAILURE;
        }

        if (!is_file($file)) {
            $this->io()->error("Import path is not a regular file: $file");
            return self::FAILURE;
        }

        if (!is_readable($file)) {
            $this->io()->error("Import file is not readable: $file");
            return self::FAILURE;
        }

        $dbConfig = $this->config->getDatabaseConfig();

        if ($dbConfig === []) {
            $this->io()->error('Database configuration not found');
            return self::FAILURE;
        }

        $this->io()->warning('This will overwrite existing data in the database!');

        if ($this->io()->confirm('Do you want to continue?', false) !== true) {
            if (!$input->isInteractive()) {
                $this->io()->error('Database import cancelled in non-interactive mode.');
                $this->io()->text('Run the command interactively to confirm the destructive import.');
                return self::FAILURE;
            }

            $this->io()->info('Database import cancelled.');
            return self::SUCCESS;
        }

        $this->io()->info('Starting database import...');

        $compressionFormat = $this->detectCompressionFormat($file);
        if ($compressionFormat !== null && $compressionFormat !== '') {
            $this->io()->info("Streaming decompression ($compressionFormat)...");
        }

        // Parse host and port (host may be in "host:port" format)
        $host = $dbConfig['host'];
        $port = (string) Constants::DEFAULT_MYSQL_PORT;
        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        $command = [
            'mysql',
            '-h', $host,
            '-P', $port,
            '-u', $dbConfig['user'],
            '--default-character-set=' . Constants::DB_CHARSET,
            $dbConfig['database'],
        ];

        $env = ['MYSQL_PWD' => $dbConfig['password']];

        try {
            ['process' => $process, 'input' => $input] = $this->createImportProcess(
                $command,
                $env,
                $file,
                $compressionFormat
            );
        } catch (\RuntimeException $e) {
            $this->io()->error($e->getMessage());
            return self::FAILURE;
        }

        $process->setTimeout(Constants::DB_PROCESS_TIMEOUT);

        $progressBar = $this->io()->createProgressBar();
        $progressBar->start();

        try {
            $process->run(function ($type, $buffer) use ($progressBar) {
                if ($type === Process::ERR && is_string($buffer) && trim($buffer) !== '') {
                    $filteredBuffer = $this->filterMysqlClientStderr($buffer);
                    if (trim($filteredBuffer) !== '') {
                        $this->io()->warning($filteredBuffer);
                    }
                }
                $progressBar->advance();
            });
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        $progressBar->finish();
        $this->io()->newLine();

        if (!$process->isSuccessful()) {
            $this->io()->error('Database import failed');
            return self::FAILURE;
        }

        $this->io()->success('Database imported successfully');

        return self::SUCCESS;
    }

    /**
     * Detect compression format from file extension
     */
    private function detectCompressionFormat(string $file): ?string
    {
        if (str_ends_with($file, '.gz') || str_ends_with($file, '.gzip')) {
            return 'gzip';
        }
        if (str_ends_with($file, '.zst') || str_ends_with($file, '.zstd')) {
            return 'zstd';
        }
        if (str_ends_with($file, '.xz')) {
            return 'xz';
        }
        if (str_ends_with($file, '.bz2') || str_ends_with($file, '.bzip2')) {
            return 'bzip2';
        }

        return null;
    }

    /**
     * @param list<string> $mysqlCommand
     * @param array<string, string> $env
     * @return array{process: Process, input: resource|null}
     */
    private function createImportProcess(array $mysqlCommand, array $env, string $file, ?string $compressionFormat): array
    {
        if ($compressionFormat === null || $compressionFormat === '') {
            $mysqlCommand[0] = $this->requireImportCommand();
            $input = fopen($file, 'rb');
            if ($input === false) {
                throw new \RuntimeException('Failed to open SQL file for import');
            }

            $process = new Process($mysqlCommand, null, $env);
            $process->setInput($input);

            return ['process' => $process, 'input' => $input];
        }

        if ($compressionFormat === 'gzip') {
            $mysqlCommand[0] = $this->requireImportCommand();
            $input = fopen('compress.zlib://' . $file, 'rb');
            if ($input === false) {
                throw new \RuntimeException('Failed to open gzip file for import');
            }

            $process = new Process($mysqlCommand, null, $env);
            $process->setInput($input);

            return ['process' => $process, 'input' => $input];
        }

        $decompressCommand = $this->getExternalDecompressionCommand($file, $compressionFormat);
        $mysqlCommand[0] = $this->requireImportCommand();
        $shellCommand = $this->buildShellCommand($decompressCommand) . ' | ' . $this->buildShellCommand($mysqlCommand);

        return [
            'process' => new Process(['bash', '-o', 'pipefail', '-c', $shellCommand], null, $env),
            'input' => null,
        ];
    }

    private function requireImportCommand(): string
    {
        $path = (new ExecutableFinder())->find('mysql');
        if ($path === null) {
            throw new \RuntimeException(
                "Required import tool 'mysql' was not found in PATH. Install mysql-client or mariadb-client and try again."
            );
        }

        return $path;
    }

    private function filterMysqlClientStderr(string $buffer): string
    {
        $lines = preg_split('/\R/', $buffer);
        if ($lines === false) {
            return $buffer;
        }

        $filtered = array_filter($lines, static function (string $line): bool {
            return trim($line) !== 'WARNING: option --ssl-verify-server-cert is disabled, because of an insecure passwordless login.';
        });

        return implode(PHP_EOL, $filtered);
    }

    /**
     * @return list<string>
     */
    private function getExternalDecompressionCommand(string $file, string $format): array
    {
        return match ($format) {
            'zstd' => [$this->requireDecompressionCommand('zstd', 'zstd'), '-d', '-c', $file],
            'xz' => [$this->requireDecompressionCommand('xz', 'xz-utils'), '-d', '-c', $file],
            'bzip2' => [$this->requireDecompressionCommand('bzip2', 'bzip2'), '-d', '-c', $file],
            default => throw new \RuntimeException('Unsupported compression format'),
        };
    }

    private function requireDecompressionCommand(string $command, string $package): string
    {
        $path = (new ExecutableFinder())->find($command);
        if ($path === null) {
            throw new \RuntimeException(sprintf(
                "Required decompression tool '%s' was not found in PATH. Install %s and try again.",
                $command,
                $package
            ));
        }

        return $path;
    }

    /**
     * @param list<string> $command
     */
    private function buildShellCommand(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }
}
