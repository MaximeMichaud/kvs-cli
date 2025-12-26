<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'system:cache',
    description: 'Manage KVS cache',
    aliases: ['cache']
)]
class CacheCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all cache')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Cache type to clear (file|db)')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show cache statistics');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('stats') !== null && $input->getOption('stats') !== false) {
            return $this->showStats();
        }

        if ($input->getOption('clear') !== null && $input->getOption('clear') !== false) {
            return $this->clearCache($input->getOption('type'));
        }

        $this->io->info('Available options:');
        $this->io->listing([
            '--clear : Clear all cache',
            '--clear --type=file : Clear file cache',
            '--clear --type=db : Clear database cache',
            '--stats : Show cache statistics',
        ]);

        return self::SUCCESS;
    }

    private function clearCache(?string $type): int
    {
        if ($type === null || $type === '' || $type === 'file') {
            $this->clearFileCache();
        }

        if ($type === null || $type === '' || $type === 'db') {
            $this->clearDatabaseCache();
        }

        $this->io->success('Cache cleared successfully');
        return self::SUCCESS;
    }

    private function clearFileCache(): void
    {
        $cacheDirs = [
            $this->config->getAdminPath() . '/data/engine',
            $this->config->getAdminPath() . '/smarty/cache',
            $this->config->getAdminPath() . '/smarty/template-c',
            $this->config->getKvsPath() . '/blocks/cache',
        ];

        foreach ($cacheDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($dir);

            $count = 0;
            foreach ($finder as $file) {
                unlink($file->getRealPath());
                $count++;
            }

            if ($count > 0) {
                $this->io->info("Cleared $count files from " . basename($dir));
            }
        }
    }

    private function clearDatabaseCache(): void
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return;
        }

        try {
            $tables = [
                $this->table('stats_cache'),
                $this->table('admin_system_cache'),
            ];

            foreach ($tables as $table) {
                $db->exec("TRUNCATE TABLE IF EXISTS $table");
                $this->io->info("Cleared database cache table: $table");
            }
        } catch (\Exception $e) {
            $this->io->warning('Could not clear database cache: ' . $e->getMessage());
        }
    }

    private function showStats(): int
    {
        $stats = [];

        $cacheDirs = [
            'Engine cache' => $this->config->getAdminPath() . '/data/engine',
            'Smarty cache' => $this->config->getAdminPath() . '/smarty/cache',
            'Template cache' => $this->config->getAdminPath() . '/smarty/template-c',
            'Blocks cache' => $this->config->getKvsPath() . '/blocks/cache',
        ];

        foreach ($cacheDirs as $name => $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($dir);

            $size = 0;
            $count = 0;
            foreach ($finder as $file) {
                $size += $file->getSize();
                $count++;
            }

            $stats[] = [
                $name,
                $count,
                format_bytes($size),
            ];
        }

        if ($stats === []) {
            $this->io->warning('No cache directories found');
            $this->io->text('Cache directories will be created when KVS starts generating cache.');
            return self::SUCCESS;
        }

        $this->renderTable(
            ['Cache Type', 'Files', 'Size'],
            $stats
        );

        $totalFiles = array_sum(array_column($stats, 1));
        if ($totalFiles === 0) {
            $this->io->note('Cache directories exist but are empty');
        }

        return self::SUCCESS;
    }
}
