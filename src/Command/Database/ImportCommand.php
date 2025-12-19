<?php

namespace KVS\CLI\Command\Database;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
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
            ->addArgument('file', InputArgument::REQUIRED, 'SQL file to import (supports .sql, .gz, .zstd, .xz, .bz2)')
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
        $file = $input->getArgument('file');

        if (!file_exists($file)) {
            $this->io->error("File not found: $file");
            return self::FAILURE;
        }

        $dbConfig = $this->config->getDatabaseConfig();

        if (empty($dbConfig)) {
            $this->io->error('Database configuration not found');
            return self::FAILURE;
        }

        $this->io->warning('This will overwrite existing data in the database!');

        if (!$this->io->confirm('Do you want to continue?', false)) {
            return self::SUCCESS;
        }

        $this->io->info('Starting database import...');

        // Detect compression format
        $compressionFormat = $this->detectCompressionFormat($file);
        $tempFile = null;

        if ($compressionFormat) {
            $this->io->info("Decompressing file ($compressionFormat)...");
            $sqlContent = $this->decompressFile($file, $compressionFormat);

            if ($sqlContent === false) {
                $this->io->error("Failed to decompress file");
                return self::FAILURE;
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'kvs_import_');
            file_put_contents($tempFile, $sqlContent);
            $file = $tempFile;
        }

        $command = [
            'mysql',
            '-h', $dbConfig['host'],
            '-u', $dbConfig['user'],
            '-p' . $dbConfig['password'],
            '--default-character-set=' . Constants::DB_CHARSET,
            $dbConfig['database'],
        ];

        $process = new Process($command);
        $process->setInput(file_get_contents($file));
        $process->setTimeout(3600);

        $progressBar = $this->io->createProgressBar();
        $progressBar->start();

        $process->run(function ($type, $buffer) use ($progressBar) {
            if ($type === Process::ERR && trim($buffer)) {
                $this->io->warning($buffer);
            }
            $progressBar->advance();
        });

        $progressBar->finish();
        $this->io->newLine();

        if ($tempFile && file_exists($tempFile)) {
            unlink($tempFile);
        }

        if (!$process->isSuccessful()) {
            $this->io->error('Database import failed');
            return self::FAILURE;
        }

        $this->io->success('Database imported successfully');

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
     * Decompress file based on format
     */
    private function decompressFile(string $file, string $format): string|false
    {
        switch ($format) {
            case 'gzip':
                return gzdecode(file_get_contents($file));

            case 'zstd':
                // Use zstd command-line tool
                $process = new Process(['zstd', '-d', '-c', $file]);
                $process->run();
                if (!$process->isSuccessful()) {
                    return false;
                }
                return $process->getOutput();

            case 'xz':
                // Use xz command-line tool
                $process = new Process(['xz', '-d', '-c', $file]);
                $process->run();
                if (!$process->isSuccessful()) {
                    return false;
                }
                return $process->getOutput();

            case 'bzip2':
                // Use bzip2 command-line tool
                $process = new Process(['bzip2', '-d', '-c', $file]);
                $process->run();
                if (!$process->isSuccessful()) {
                    return false;
                }
                return $process->getOutput();

            default:
                return false;
        }
    }
}
