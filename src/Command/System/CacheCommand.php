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
            ->setHelp(<<<'HELP'
Manage KVS file and database cache.

<info>EXAMPLES:</info>
  <comment>kvs system:cache --stats</comment>
  <comment>kvs system:cache --clear</comment>
  <comment>kvs system:cache --clear --type=file</comment>
  <comment>kvs system:cache --clear --type=db</comment>
HELP
            )
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all cache')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Cache type to clear (file|db)')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show cache statistics');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasConflictingBoolOptions($input, ['stats', 'clear'])) {
            return self::FAILURE;
        }

        if ($this->getBoolOption($input, 'stats')) {
            if ($this->rejectUnsupportedOptions($input, 'stats', ['type'])) {
                return self::FAILURE;
            }

            return $this->showStats();
        }

        if ($this->getBoolOption($input, 'clear')) {
            return $this->clearCache($this->getStringOption($input, 'type'));
        }

        if ($this->rejectUnsupportedOptions($input, 'default', ['type'])) {
            return self::FAILURE;
        }

        $this->io()->info('Available options:');
        $this->io()->listing([
            '--clear : Clear all cache',
            '--clear --type=file : Clear file cache',
            '--clear --type=db : Clear database cache',
            '--stats : Show cache statistics',
        ]);

        return self::SUCCESS;
    }

    private function clearCache(?string $type): int
    {
        $type = $type !== null ? strtolower($type) : null;
        if ($type !== null && $type !== '' && !in_array($type, ['file', 'db'], true)) {
            $this->io()->error('Invalid value for --type (use: file or db)');
            return self::FAILURE;
        }

        $success = true;
        if ($type === null || $type === '' || $type === 'file') {
            $success = $this->clearFileCache();
        }

        if ($type === null || $type === '' || $type === 'db') {
            $success = $this->clearDatabaseCache() && $success;
        }

        if (!$success) {
            return self::FAILURE;
        }

        $this->io()->success('Cache cleared successfully');
        return self::SUCCESS;
    }

    private function clearFileCache(): bool
    {
        $success = true;
        $cacheDirs = [
            $this->config->getAdminPath() . '/data/engine',
            $this->config->getAdminPath() . '/smarty/cache',
            $this->config->getAdminPath() . '/smarty/template-c',
            $this->config->getAdminPath() . '/smarty/template-c-site',
            $this->config->getKvsPath() . '/blocks/cache',
        ];

        foreach ($cacheDirs as $dir) {
            try {
                $finder = $this->findCacheFiles($dir);
                if ($finder === null) {
                    continue;
                }

                $count = 0;
                foreach ($finder as $file) {
                    if (!$this->removeCacheFile($file->getPathname())) {
                        $this->io()->error('Failed to delete cache file: ' . $file->getPathname());
                        $success = false;
                        continue;
                    }
                    $count++;
                }

                if ($count > 0) {
                    $this->io()->info("Cleared $count files from " . basename($dir));
                }
            } catch (\RuntimeException $e) {
                $this->io()->error('Could not clear cache directory ' . $dir . ': ' . $e->getMessage());
                $success = false;
            }
        }

        return $success;
    }

    protected function removeCacheFile(string $path): bool
    {
        return @unlink($path);
    }

    private function findCacheFiles(string $dir): ?Finder
    {
        if (!is_dir($dir)) {
            $parent = $dir;
            while (!is_dir($parent)) {
                if (file_exists($parent) || is_link($parent) || dirname($parent) === $parent) {
                    throw new \RuntimeException('Cache path is not an accessible directory.');
                }
                $parent = dirname($parent);
            }
            if (!is_executable($parent)) {
                throw new \RuntimeException('Cannot verify cache path through an inaccessible parent.');
            }

            return null;
        }

        if (!is_readable($dir) || !is_executable($dir)) {
            throw new \RuntimeException('Cache directory is not readable or searchable.');
        }

        return (new Finder())->files()->in($dir);
    }

    private function clearDatabaseCache(): bool
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return false;
        }

        try {
            $tables = [
                $this->table('stats_cache'),
                $this->table('admin_system_cache'),
            ];

            $clearedTables = [];
            foreach ($tables as $table) {
                if ($this->databaseCacheTableExists($db, $table)) {
                    $this->truncateDatabaseCacheTable($db, $table);
                    $clearedTables[] = $table;
                    $this->io()->info("Cleared database cache table: $table");
                }
            }

            if ($clearedTables === []) {
                $this->io()->warning(
                    'No database cache tables found (' . implode(', ', $tables) . '). '
                    . 'KVS may use Memcached or Dragonfly instead.'
                );
            }
        } catch (\Exception $e) {
            $this->io()->error('Could not clear database cache: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    protected function databaseCacheTableExists(\PDO $db, string $table): bool
    {
        $quotedTable = $db->quote($table);
        if ($quotedTable === false) {
            $quotedTable = "'" . str_replace("'", "''", $table) . "'";
        }

        $result = $db->query("SHOW TABLES LIKE $quotedTable");
        return $result !== false && $result->rowCount() > 0;
    }

    protected function truncateDatabaseCacheTable(\PDO $db, string $table): void
    {
        $db->exec("TRUNCATE TABLE $table");
    }

    private function showStats(): int
    {
        $stats = [];
        $success = true;

        $cacheDirs = [
            'Engine cache' => $this->config->getAdminPath() . '/data/engine',
            'Smarty cache' => $this->config->getAdminPath() . '/smarty/cache',
            'Template cache' => $this->config->getAdminPath() . '/smarty/template-c',
            'Site template cache' => $this->config->getAdminPath() . '/smarty/template-c-site',
            'Blocks cache' => $this->config->getKvsPath() . '/blocks/cache',
        ];

        foreach ($cacheDirs as $name => $dir) {
            try {
                $finder = $this->findCacheFiles($dir);
                if ($finder === null) {
                    continue;
                }

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
            } catch (\RuntimeException $e) {
                $this->io()->error('Could not inspect cache directory ' . $dir . ': ' . $e->getMessage());
                $success = false;
            }
        }

        if ($stats === []) {
            if (!$success) {
                return self::FAILURE;
            }
            $this->io()->warning('No cache directories found');
            $this->io()->text('Cache directories will be created when KVS starts generating cache.');
            return self::SUCCESS;
        }

        $this->renderTable(
            ['Cache Type', 'Files', 'Size'],
            $stats
        );

        if (!$success) {
            $this->io()->warning('Cache statistics are incomplete.');
            return self::FAILURE;
        }

        $totalFiles = array_sum(array_column($stats, 1));
        if ($totalFiles === 0) {
            $this->io()->note('Cache directories exist but are empty');
        }

        return self::SUCCESS;
    }
}
