<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

#[AsCommand(name: 'maintenance', description: 'Enable/disable website maintenance mode', aliases: ['maint'])]
class MaintenanceCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Enable/disable website maintenance mode')
            ->setHelp(<<<'EOT'
Manage website maintenance mode.

Usage:
  <info>kvs maintenance on</info>      Enable maintenance mode
  <info>kvs maintenance off</info>     Disable maintenance mode
  <info>kvs maintenance status</info>  Check maintenance status

Examples:
  kvs maintenance on
  kvs maintenance status
EOT
            )
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform: <comment>on</comment>, <comment>off</comment>, or <comment>status</comment>'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $this->getStringArgument($input, 'action');
        if ($mode === null) {
            $this->io()->error('Action argument is required');
            return self::FAILURE;
        }

        $settingsFile = $this->config->getKvsPath() . '/admin/data/system/website_ui_params.dat';
        $settingsDir = dirname($settingsFile);

        // Create directory if it doesn't exist
        if (!is_dir($settingsDir) && !@mkdir($settingsDir, 0755, true)) {
            $this->io()->error('Failed to create maintenance settings directory: ' . $settingsDir);
            return self::FAILURE;
        }

        try {
            switch (strtolower($mode)) {
                case 'status':
                    return $this->showStatus($output, $settingsFile);

                case 'on':
                    return $this->enableMaintenance($output, $settingsFile);

                case 'off':
                    return $this->disableMaintenance($output, $settingsFile);

                default:
                    $this->io()->error('Invalid action: ' . $mode);
                    $this->io()->newLine();
                    $this->io()->text('<info>Usage:</info>');
                    $this->io()->text('  kvs maintenance <comment>on</comment>      - Enable maintenance mode');
                    $this->io()->text('  kvs maintenance <comment>off</comment>     - Disable maintenance mode');
                    $this->io()->text('  kvs maintenance <comment>status</comment>  - Check maintenance status');
                    return self::FAILURE;
            }
        } catch (\RuntimeException $e) {
            $this->io()->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function showStatus(OutputInterface $output, string $settingsFile): int
    {
        $isEnabled = $this->getMaintenanceStatus($settingsFile);

        if ($isEnabled) {
            $this->io()->warning('Maintenance mode is ENABLED');
            $this->io()->text('Website offline for visitors');
        } else {
            $this->io()->success('Maintenance mode is DISABLED');
            $this->io()->text('Website online for everyone');
        }

        return self::SUCCESS;
    }

    private function enableMaintenance(OutputInterface $output, string $settingsFile): int
    {
        $settings = $this->loadSettings($settingsFile);
        $settings['DISABLE_WEBSITE'] = 1;
        $this->saveSettings($settingsFile, $settings);

        $this->io()->success('Maintenance mode enabled');
        $this->io()->text('Website is now offline for visitors (admins can still access)');

        return self::SUCCESS;
    }

    private function disableMaintenance(OutputInterface $output, string $settingsFile): int
    {
        $settings = $this->loadSettings($settingsFile);
        $settings['DISABLE_WEBSITE'] = 0;
        $this->saveSettings($settingsFile, $settings);

        $this->io()->success('Maintenance mode disabled');
        $this->io()->text('Website is now online for everyone');

        return self::SUCCESS;
    }

    private function getMaintenanceStatus(string $settingsFile): bool
    {
        $settings = $this->loadSettings($settingsFile);
        // DISABLE_WEBSITE = 1 means maintenance ON, 0 means OFF
        if (!isset($settings['DISABLE_WEBSITE'])) {
            return false;
        }
        $value = $settings['DISABLE_WEBSITE'];
        return (is_int($value) || is_string($value)) && (int) $value === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSettings(string $settingsFile): array
    {
        if (file_exists($settingsFile)) {
            $content = @file_get_contents($settingsFile);
            if ($content === false) {
                throw new \RuntimeException('Failed to read maintenance settings: ' . $settingsFile);
            }
            $result = @unserialize($content, ['allowed_classes' => false]);
            if (!is_array($result)) {
                throw new \RuntimeException('Invalid maintenance settings: ' . $settingsFile);
            }
            /** @var array<string, mixed> $result */
            return $result;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function saveSettings(string $settingsFile, array $settings): void
    {
        $contents = serialize($settings);
        $error = 'Failed to write maintenance settings: ' . $settingsFile;
        $target = $settingsFile;
        $links = [];
        while (is_link($target)) {
            $link = readlink($target);
            $linkMetadata = lstat($target);
            if ($link === false || $linkMetadata === false) {
                throw new \RuntimeException($error);
            }
            $linkDirectory = realpath(dirname($target));
            $identity = $linkMetadata['dev'] . ':' . $linkMetadata['ino'] . ':'
                . ($linkDirectory === false ? dirname($target) : $linkDirectory);
            if (isset($links[$identity])) {
                throw new \RuntimeException($error);
            }
            $links[$identity] = true;
            $target = Path::isAbsolute($link) ? $link : dirname($target) . '/' . $link;
        }
        if (file_exists($target) && (!is_file($target) || !is_writable($target))) {
            throw new \RuntimeException($error);
        }

        $metadata = @stat($target);
        $directory = dirname($target);
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory !== false) {
            $directory = $resolvedDirectory;
        }
        $temporaryFile = (new Filesystem())->tempnam($directory, '.kvs-maintenance-');
        try {
            if (
                dirname($temporaryFile) !== $directory
                || @file_put_contents($temporaryFile, $contents) !== strlen($contents)
            ) {
                throw new \RuntimeException($error);
            }
            if ($metadata !== false) {
                if (
                    (fileowner($temporaryFile) !== $metadata['uid'] && !@chown($temporaryFile, $metadata['uid']))
                    || (filegroup($temporaryFile) !== $metadata['gid'] && !@chgrp($temporaryFile, $metadata['gid']))
                ) {
                    throw new \RuntimeException($error . '; cannot preserve file ownership');
                }
            }
            $permissions = $metadata === false ? 0666 & ~umask() : $metadata['mode'] & 0777;
            if (!@chmod($temporaryFile, $permissions) || !@rename($temporaryFile, $target)) {
                throw new \RuntimeException($error);
            }
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }
}
