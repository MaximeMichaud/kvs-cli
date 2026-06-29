<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\SecureFileTrait;
use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'system:backup',
    description: 'Create and list KVS backups',
    aliases: ['backup']
)]
class BackupCommand extends BaseCommand
{
    use SecureFileTrait;

    private const CREATE_TYPES = ['full', 'db', 'files'];
    private const LIST_FORMATS = ['table', 'json', 'csv', 'yaml', 'count'];

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Create and list KVS backups.

<info>EXAMPLES:</info>
  <comment>kvs system:backup --list</comment>
  <comment>kvs system:backup --list --format=json</comment>
  <comment>kvs system:backup --create --type=db</comment>
  <comment>kvs system:backup --create --type=files --output=/var/backups/kvs</comment>
HELP
            )
            ->addOption('create', null, InputOption::VALUE_NONE, 'Create a new backup')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available backups')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Backup type (full|db|files)', 'full')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory for backup')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format for --list (table|json|csv|yaml|count)', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasConflictingBoolOptions($input, ['create', 'list'])) {
            return self::FAILURE;
        }

        if ($this->getBoolOption($input, 'create')) {
            if ($this->rejectUnsupportedOptions($input, 'create', ['format'])) {
                return self::FAILURE;
            }

            return $this->createBackup(
                $this->getStringOptionOrDefault($input, 'type', 'full'),
                $this->getStringOption($input, 'output')
            );
        }

        if ($this->getBoolOption($input, 'list')) {
            if ($this->rejectUnsupportedOptions($input, 'list', ['type'])) {
                return self::FAILURE;
            }

            return $this->listBackups(
                $this->getStringOption($input, 'output'),
                $this->getStringOptionOrDefault($input, 'format', 'table')
            );
        }

        if ($this->rejectUnsupportedOptions($input, 'default', ['type', 'output', 'format'])) {
            return self::FAILURE;
        }

        $this->io()->info('Available options:');
        $this->io()->listing([
            '--create : Create a new backup',
            '--create --type=db : Create database backup only',
            '--create --type=files : Create files backup only',
            '--list : List available backups',
            '--list --output=/var/backups/kvs : List backups in a custom directory',
            '--list --format=json : List backups as JSON',
        ]);

        return self::SUCCESS;
    }

    private function createBackup(string $type, ?string $outputDir): int
    {
        if (!in_array($type, self::CREATE_TYPES, true)) {
            $this->io()->error(sprintf(
                'Invalid value for --type "%s" (expected: %s)',
                $type,
                implode(', ', self::CREATE_TYPES)
            ));
            return self::FAILURE;
        }

        $outputDir = $outputDir ?? dirname($this->config->getKvsPath()) . '/backups';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "kvs_backup_{$type}_{$timestamp}";
        $success = true;

        if ($type === 'full' || $type === 'db') {
            $success = $this->createDatabaseBackup($outputDir, $backupName);
        }

        if ($type === 'full' || $type === 'files') {
            $success = $this->createFilesBackup($outputDir, $backupName) && $success;
        }

        if ($type === 'full' && $success) {
            $success = $this->createFullArchive($outputDir, $backupName);
        }

        if (!$success) {
            if ($type === 'full') {
                $this->cleanupPartialBackupFiles($outputDir, $backupName);
            }
            return self::FAILURE;
        }

        $this->io()->success('Backup created: ' . $this->getCreatedBackupPath($outputDir, $backupName, $type));
        return self::SUCCESS;
    }

    private function getCreatedBackupPath(string $outputDir, string $backupName, string $type): string
    {
        return match ($type) {
            'db' => "$outputDir/{$backupName}_db.sql.gz",
            'files' => "$outputDir/{$backupName}_files.tar.gz",
            'full' => "$outputDir/{$backupName}_full.tar.gz",
            default => "$outputDir/$backupName",
        };
    }

    private function cleanupPartialBackupFiles(string $outputDir, string $backupName): void
    {
        $partialFiles = [
            "$outputDir/{$backupName}_db.sql",
            "$outputDir/{$backupName}_db.sql.gz",
            "$outputDir/{$backupName}_files.tar.gz",
            "$outputDir/{$backupName}_full.tar",
            "$outputDir/{$backupName}_full.tar.gz",
        ];

        $removed = [];
        foreach ($partialFiles as $file) {
            if (is_file($file) && unlink($file)) {
                $removed[] = basename($file);
            }
        }

        if ($removed !== []) {
            $this->io()->info('Removed partial backup files: ' . implode(', ', $removed));
        }
    }

    private function createDatabaseBackup(string $outputDir, string $backupName): bool
    {
        $dbConfig = $this->config->getDatabaseConfig();

        if ($dbConfig === []) {
            $this->io()->error('Database configuration not found');
            return false;
        }

        $dumpFile = "$outputDir/{$backupName}_db.sql";
        $host = $dbConfig['host'];
        $port = $dbConfig['port'] ?? (string) Constants::DEFAULT_MYSQL_PORT;
        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        $command = [
            'mysqldump',
            '-h', $host,
            '-P', $port,
            '-u', $dbConfig['user'],
            '-p' . $dbConfig['password'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            $dbConfig['database'],
        ];

        $process = new Process($command);
        $process->setTimeout(Constants::DB_PROCESS_TIMEOUT);

        $this->io()->info('Creating database backup...');

        $process->run();

        if ($process->isSuccessful()) {
            if (!$this->writeSecureFile($dumpFile, $process->getOutput())) {
                $this->io()->error('Failed to write SQL dump file');
                return false;
            }

            $gzFile = "$dumpFile.gz";
            $fp = $this->withSecureFileUmask(
                static fn () => gzopen($gzFile, 'w9')
            );
            if ($fp === false) {
                $this->io()->error('Failed to create compressed backup file');
                return false;
            }

            $sqlContent = file_get_contents($dumpFile);
            if ($sqlContent === false) {
                gzclose($fp);
                $this->io()->error('Failed to read SQL dump file');
                return false;
            }

            gzwrite($fp, $sqlContent);
            gzclose($fp);
            if (!$this->restrictFilePermissions($gzFile)) {
                $this->io()->error('Failed to set compressed backup file permissions');
                return false;
            }
            unlink($dumpFile);

            $this->io()->info('Database backup created: ' . basename($gzFile));
            return true;
        } else {
            $this->io()->error('Database backup failed: ' . $process->getErrorOutput());
            return false;
        }
    }

    private function createFilesBackup(string $outputDir, string $backupName): bool
    {
        $kvsPath = $this->config->getKvsPath();
        $contentPath = $this->config->getContentPath();

        $tarFile = "$outputDir/{$backupName}_files.tar.gz";

        $excludes = [
            '--exclude=*.log',
            '--exclude=*/cache/*',
            '--exclude=*/tmp/*',
            '--exclude=*/temp/*',
        ];

        $command = array_merge(
            ['tar', 'czf', $tarFile],
            $excludes,
            ['-C', dirname($kvsPath), basename($kvsPath)]
        );

        if (is_dir($contentPath)) {
            $command[] = '-C';
            $command[] = dirname($contentPath);
            $command[] = basename($contentPath);
        }

        $process = new Process($command);
        $process->setTimeout(Constants::FILE_BACKUP_TIMEOUT);

        $this->io()->info('Creating files backup...');

        $this->withSecureFileUmask(
            static fn (): int => $process->run()
        );

        $tarFileSize = is_file($tarFile) ? filesize($tarFile) : false;
        $tarWarningWithArchive = $process->getExitCode() === 1 && $tarFileSize !== false && $tarFileSize > 0;

        if ($process->isSuccessful() || $tarWarningWithArchive) {
            if (!$this->restrictFilePermissions($tarFile)) {
                $this->io()->error('Failed to set files backup permissions');
                return false;
            }

            if ($tarWarningWithArchive) {
                $this->io()->warning('Some files changed during archival; continuing with the created archive.');
                $errorOutput = trim($process->getErrorOutput());
                if ($errorOutput !== '') {
                    $this->io()->text($errorOutput);
                }
            }

            $this->io()->info('Files backup created: ' . basename($tarFile));
            return true;
        } else {
            $this->io()->error('Files backup failed: ' . $process->getErrorOutput());
            return false;
        }
    }

    private function createFullArchive(string $outputDir, string $backupName): bool
    {
        $dbFile = "$outputDir/{$backupName}_db.sql.gz";
        $filesFile = "$outputDir/{$backupName}_files.tar.gz";
        $fullFile = "$outputDir/{$backupName}_full.tar";

        if (file_exists($dbFile) && file_exists($filesFile)) {
            $command = [
                'tar', 'cf', $fullFile,
                '-C', $outputDir,
                basename($dbFile),
                basename($filesFile)
            ];

            $process = new Process($command);
            $this->withSecureFileUmask(
                static fn (): int => $process->run()
            );

            if ($process->isSuccessful()) {
                if (!$this->restrictFilePermissions($fullFile)) {
                    $this->io()->error('Failed to set full backup archive permissions');
                    return false;
                }

                unlink($dbFile);
                unlink($filesFile);

                $gzCommand = ['gzip', '-9', $fullFile];
                $gzProcess = new Process($gzCommand);
                $this->withSecureFileUmask(
                    static fn (): int => $gzProcess->run()
                );
                if (!$gzProcess->isSuccessful()) {
                    $this->io()->error('Full backup compression failed: ' . $gzProcess->getErrorOutput());
                    return false;
                }
                if (!$this->restrictFilePermissions("$fullFile.gz")) {
                    $this->io()->error('Failed to set compressed full backup permissions');
                    return false;
                }

                $this->io()->info('Full backup archive created: ' . basename("$fullFile.gz"));
                return true;
            }

            $this->io()->error('Full backup archive failed: ' . $process->getErrorOutput());
            return false;
        }

        $this->io()->error('Full backup archive failed: database or files backup is missing');
        return false;
    }

    private function listBackups(?string $outputDir = null, string $format = 'table'): int
    {
        if (!in_array($format, self::LIST_FORMATS, true)) {
            $this->io()->error(sprintf(
                'Invalid value for --format "%s" (expected: %s)',
                $format,
                implode(', ', self::LIST_FORMATS)
            ));
            return self::FAILURE;
        }

        $backupDirs = $outputDir !== null
            ? [$outputDir]
            : $this->getDefaultBackupDirs();
        $backupDirs = array_values(array_filter($backupDirs, static fn (string $dir): bool => is_dir($dir)));

        if ($backupDirs === []) {
            if ($format === 'table') {
                $this->io()->warning('No backups directory found');
                return self::SUCCESS;
            }

            $this->displayBackupList([], $format);
            return self::SUCCESS;
        }

        $files = [];
        foreach ($backupDirs as $backupDir) {
            $files = array_merge($files, $this->findBackupFiles($backupDir));
        }

        if ($files === []) {
            if ($format === 'table') {
                $this->io()->info('No backups found');
                return self::SUCCESS;
            }

            $this->displayBackupList([], $format);
            return self::SUCCESS;
        }

        $backups = [];
        foreach ($files as $file) {
            $size = filesize($file);
            $mtime = filemtime($file);

            if ($size === false || $mtime === false) {
                continue;
            }

            $backups[] = [
                'file' => basename($file),
                'size' => format_bytes($size),
                'created' => date('Y-m-d H:i:s', $mtime),
            ];
        }

        $this->displayBackupList($backups, $format);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function getDefaultBackupDirs(): array
    {
        return array_values(array_unique([
            dirname($this->config->getKvsPath()) . '/backups',
            $this->config->getAdminPath() . '/data/backup',
        ]));
    }

    /**
     * @return list<string>
     */
    private function findBackupFiles(string $backupDir): array
    {
        $entries = scandir($backupDir);
        if ($entries === false) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $backupDir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }

            if (!str_ends_with($entry, '.gz') && !str_ends_with($entry, '.zip')) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * @param list<array{file: string, size: string, created: string}> $backups
     */
    private function displayBackupList(array $backups, string $format): void
    {
        switch ($format) {
            case 'table':
                if ($backups === []) {
                    $this->io()->info('No backups found');
                    return;
                }

                $rows = array_map(
                    static fn(array $backup): array => [$backup['file'], $backup['size'], $backup['created']],
                    $backups
                );
                $this->renderTable(['Backup File', 'Size', 'Created'], $rows);
                return;

            case 'count':
                $this->io()->writeln((string) count($backups));
                return;

            case 'json':
                $json = json_encode($backups, Constants::JSON_FLAGS);
                if ($json === false) {
                    throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
                }
                $this->io()->writeln($json);
                return;

            case 'csv':
                $this->io()->write($this->formatBackupsCsv($backups));
                return;

            case 'yaml':
                $this->io()->write(Yaml::dump($backups, 2, 2));
                return;
        }
    }

    /**
     * @param list<array{file: string, size: string, created: string}> $backups
     */
    private function formatBackupsCsv(array $backups): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open temporary CSV stream');
        }

        fputcsv($stream, ['file', 'size', 'created'], ',', '"', '\\');
        foreach ($backups as $backup) {
            fputcsv($stream, [$backup['file'], $backup['size'], $backup['created']], ',', '"', '\\');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('Failed to read temporary CSV stream');
        }

        return $csv;
    }
}
