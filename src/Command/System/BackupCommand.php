<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'system:backup',
    description: 'Create and restore KVS backups',
    aliases: ['backup']
)]
class BackupCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('create', null, InputOption::VALUE_NONE, 'Create a new backup')
            ->addOption('restore', null, InputOption::VALUE_REQUIRED, 'Restore from backup file')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available backups')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Backup type (full|db|files)', 'full')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory for backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->getBoolOption($input, 'create')) {
            return $this->createBackup(
                $this->getStringOptionOrDefault($input, 'type', 'full'),
                $this->getStringOption($input, 'output')
            );
        }

        $restoreOpt = $this->getStringOption($input, 'restore');
        if ($restoreOpt !== null) {
            return $this->restoreBackup($restoreOpt);
        }

        if ($this->getBoolOption($input, 'list')) {
            return $this->listBackups();
        }

        $this->io()->info('Available options:');
        $this->io()->listing([
            '--create : Create a new backup',
            '--create --type=db : Create database backup only',
            '--create --type=files : Create files backup only',
            '--restore=backup.tar.gz : Restore from backup',
            '--list : List available backups',
        ]);

        return self::SUCCESS;
    }

    private function createBackup(string $type, ?string $outputDir): int
    {
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
            return self::FAILURE;
        }

        $this->io()->success("Backup created: $outputDir/$backupName");
        return self::SUCCESS;
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
            if (file_put_contents($dumpFile, $process->getOutput()) === false) {
                $this->io()->error('Failed to write SQL dump file');
                return false;
            }

            $gzFile = "$dumpFile.gz";
            $fp = gzopen($gzFile, 'w9');
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

        $process->run();

        if ($process->isSuccessful()) {
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
            $process->run();

            if ($process->isSuccessful()) {
                unlink($dbFile);
                unlink($filesFile);

                $gzCommand = ['gzip', '-9', $fullFile];
                $gzProcess = new Process($gzCommand);
                $gzProcess->run();
                if (!$gzProcess->isSuccessful()) {
                    $this->io()->error('Full backup compression failed: ' . $gzProcess->getErrorOutput());
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

    private function restoreBackup(string $backupFile): int
    {
        if (!file_exists($backupFile)) {
            $this->io()->error("Backup file not found: $backupFile");
            return self::FAILURE;
        }

        $this->io()->warning('Restore operation will overwrite existing data!');

        if ($this->io()->confirm('Do you want to continue?', false) !== true) {
            return self::SUCCESS;
        }

        $this->io()->info('Restoring from backup: ' . basename($backupFile));
        $this->io()->error('Backup restore is not implemented yet.');

        return self::FAILURE;
    }

    private function listBackups(): int
    {
        $backupDir = dirname($this->config->getKvsPath()) . '/backups';

        if (!is_dir($backupDir)) {
            $this->io()->warning('No backups directory found');
            return self::SUCCESS;
        }

        $files = glob("$backupDir/kvs_backup_*.{tar.gz,sql.gz}", GLOB_BRACE);

        if ($files === false || $files === []) {
            $this->io()->info('No backups found');
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
                basename($file),
                format_bytes($size),
                date('Y-m-d H:i:s', $mtime),
            ];
        }

        $this->renderTable(
            ['Backup File', 'Size', 'Created'],
            $backups
        );

        return self::SUCCESS;
    }
}
