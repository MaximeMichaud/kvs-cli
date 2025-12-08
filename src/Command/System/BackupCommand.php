<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
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
        if ($input->getOption('create')) {
            return $this->createBackup(
                $input->getOption('type'),
                $input->getOption('output')
            );
        }

        if ($input->getOption('restore')) {
            return $this->restoreBackup($input->getOption('restore'));
        }

        if ($input->getOption('list')) {
            return $this->listBackups();
        }

        $this->io->info('Available options:');
        $this->io->listing([
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

        if ($type === 'full' || $type === 'db') {
            $this->createDatabaseBackup($outputDir, $backupName);
        }

        if ($type === 'full' || $type === 'files') {
            $this->createFilesBackup($outputDir, $backupName);
        }

        if ($type === 'full') {
            $this->createFullArchive($outputDir, $backupName);
        }

        $this->io->success("Backup created: $outputDir/$backupName");
        return self::SUCCESS;
    }

    private function createDatabaseBackup(string $outputDir, string $backupName): void
    {
        $dbConfig = $this->config->getDatabaseConfig();

        if (empty($dbConfig)) {
            $this->io->error('Database configuration not found');
            return;
        }

        $dumpFile = "$outputDir/{$backupName}_db.sql";

        $command = [
            'mysqldump',
            '-h', $dbConfig['host'],
            '-u', $dbConfig['user'],
            '-p' . $dbConfig['password'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            $dbConfig['database'],
        ];

        $process = new Process($command);
        $process->setTimeout(3600);

        $this->io->info('Creating database backup...');

        $process->run();

        if ($process->isSuccessful()) {
            file_put_contents($dumpFile, $process->getOutput());

            $gzFile = "$dumpFile.gz";
            $fp = gzopen($gzFile, 'w9');
            gzwrite($fp, file_get_contents($dumpFile));
            gzclose($fp);
            unlink($dumpFile);

            $this->io->info('Database backup created: ' . basename($gzFile));
        } else {
            $this->io->error('Database backup failed: ' . $process->getErrorOutput());
        }
    }

    private function createFilesBackup(string $outputDir, string $backupName): void
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
        $process->setTimeout(7200);

        $this->io->info('Creating files backup...');

        $process->run();

        if ($process->isSuccessful()) {
            $this->io->info('Files backup created: ' . basename($tarFile));
        } else {
            $this->io->error('Files backup failed: ' . $process->getErrorOutput());
        }
    }

    private function createFullArchive(string $outputDir, string $backupName): void
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

                $this->io->info('Full backup archive created: ' . basename("$fullFile.gz"));
            }
        }
    }

    private function restoreBackup(string $backupFile): int
    {
        if (!file_exists($backupFile)) {
            $this->io->error("Backup file not found: $backupFile");
            return self::FAILURE;
        }

        $this->io->warning('Restore operation will overwrite existing data!');

        if (!$this->io->confirm('Do you want to continue?', false)) {
            return self::SUCCESS;
        }

        $this->io->info('Restoring from backup: ' . basename($backupFile));

        return self::SUCCESS;
    }

    private function listBackups(): int
    {
        $backupDir = dirname($this->config->getKvsPath()) . '/backups';

        if (!is_dir($backupDir)) {
            $this->io->warning('No backups directory found');
            return self::SUCCESS;
        }

        $files = glob("$backupDir/kvs_backup_*.{tar.gz,sql.gz}", GLOB_BRACE);

        if (empty($files)) {
            $this->io->info('No backups found');
            return self::SUCCESS;
        }

        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                basename($file),
                format_bytes(filesize($file)),
                date('Y-m-d H:i:s', filemtime($file)),
            ];
        }

        $this->io->table(
            ['Backup File', 'Size', 'Created'],
            $backups
        );

        return self::SUCCESS;
    }
}
