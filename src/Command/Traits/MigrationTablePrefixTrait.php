<?php

namespace KVS\CLI\Command\Traits;

trait MigrationTablePrefixTrait
{
    protected function getTargetSitePath(string $domain): string
    {
        return "/var/www/{$domain}";
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function validateTargetTablePrefix(array $metadata, string $sitePath): bool
    {
        $sourcePrefix = $metadata['table_prefix'] ?? null;
        if (!is_string($sourcePrefix) || $sourcePrefix === '') {
            $this->io()->error('Source table prefix is missing. Source database and content were not imported.');
            return false;
        }

        try {
            $target = new \KVS\CLI\Config\Configuration([
                'path' => $sitePath,
                'disable_db_env_overrides' => true,
            ]);
            $targetPrefix = $target->getProjectConfig()['tables_prefix'] ?? null;
        } catch (\Throwable $error) {
            $this->io()->error('Cannot read the target table prefix: ' . $error->getMessage());
            return false;
        }

        if (!is_string($targetPrefix) || $targetPrefix === '') {
            $this->io()->error('Target table prefix is missing. Source database and content were not imported.');
            return false;
        }
        if ($sourcePrefix !== $targetPrefix) {
            $this->io()->error([
                sprintf('Table prefix mismatch: source "%s", target "%s".', $sourcePrefix, $targetPrefix),
                'Source database and content were not imported.',
                'Use source database tables and configuration matching the target prefix.',
                'KVS CLI does not change KVS-Install table prefixes.',
            ]);
            return false;
        }
        return true;
    }
}
