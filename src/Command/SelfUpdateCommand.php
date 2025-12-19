<?php

declare(strict_types=1);

namespace KVS\CLI\Command;

use KVS\CLI\Constants;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Self-update command - Updates KVS CLI to the latest version.
 *
 * Inspired by WP-CLI's cli update command.
 */
class SelfUpdateCommand extends Command
{
    private const PHAR_NAME = 'kvs.phar';

    protected function configure(): void
    {
        $this
            ->setName('self-update')
            ->setAliases(['selfupdate', 'self:update'])
            ->setDescription('Updates KVS CLI to the latest version')
            ->addOption('stable', null, InputOption::VALUE_NONE, 'Update to latest stable release')
            ->addOption('preview', null, InputOption::VALUE_NONE, 'Include pre-release versions')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Update to latest dev build from CI')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only check for updates, do not install')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->setHelp(<<<'HELP'
Updates KVS CLI to the latest release.

Default behavior is to check the GitHub releases API for the newest stable
version, and prompt if one is available.

Use <info>--stable</info> to force update to the latest stable release.
Use <info>--preview</info> to include pre-release (beta) versions.
Use <info>--dev</info> to update to the latest dev build from CI (nightly).
Use <info>--check</info> to only check for updates without installing.

Only works for PHAR installations.

<info>Examples:</info>
  <comment>kvs self-update</comment>              Check and update to latest version
  <comment>kvs self-update --check</comment>      Only check for available updates
  <comment>kvs self-update --preview</comment>    Include beta versions
  <comment>kvs self-update --dev</comment>        Update to latest dev build
  <comment>kvs self-update --yes</comment>        Update without confirmation
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if running as PHAR
        if (!$this->isRunningAsPhar()) {
            $io->error('Self-update only works for PHAR installations.');
            $io->text('If installed via git, use: git pull && composer install');
            return Command::FAILURE;
        }

        $currentPhar = (string) realpath($_SERVER['argv'][0]);
        $currentVersion = $this->getCurrentVersion();

        // Check permissions
        if (!is_writable($currentPhar)) {
            $io->error(sprintf('%s is not writable by current user.', $currentPhar));
            $io->text('Try running with sudo: sudo kvs self-update');
            return Command::FAILURE;
        }

        if (!is_writable(dirname($currentPhar))) {
            $io->error(sprintf('Directory %s is not writable by current user.', dirname($currentPhar)));
            return Command::FAILURE;
        }

        $io->text(sprintf('Current version: <info>%s</info>', $currentVersion));

        // Handle --dev option (download from nightly.link)
        if ((bool) $input->getOption('dev')) {
            return $this->updateFromDev($io, $input, $currentPhar);
        }

        $io->text('Checking for updates...');

        // Get available releases
        $includePrerelease = $input->getOption('preview');
        $releases = $this->getGitHubReleases($io, $includePrerelease);

        if ($releases === null) {
            return Command::FAILURE;
        }

        if (empty($releases)) {
            $io->success('KVS CLI is at the latest version.');
            return Command::SUCCESS;
        }

        $latest = $releases[0];
        $latestVersion = ltrim($latest['tag_name'], 'v');

        // Compare versions
        if (version_compare($currentVersion, $latestVersion, '>=')) {
            $io->success(sprintf('KVS CLI is at the latest version (%s).', $currentVersion));
            return Command::SUCCESS;
        }

        // Show available update
        $io->newLine();
        $io->text(sprintf(
            'New version available: <info>%s</info> (current: %s)',
            $latestVersion,
            $currentVersion
        ));

        if ($latest['prerelease']) {
            $io->text('<comment>This is a pre-release version.</comment>');
        }

        // Check only mode
        if ($input->getOption('check')) {
            $io->newLine();
            $io->text('Run <info>kvs self-update</info> to install the update.');
            return Command::SUCCESS;
        }

        // Confirm update
        if (!$input->getOption('yes')) {
            if (!$io->confirm(sprintf('Update to version %s?', $latestVersion), true)) {
                $io->text('Update cancelled.');
                return Command::SUCCESS;
            }
        }

        // Find PHAR asset
        $pharUrl = $this->findPharAsset($latest['assets']);
        if (!$pharUrl) {
            $io->error('Could not find PHAR file in release assets.');
            return Command::FAILURE;
        }

        // Download new version
        $io->text(sprintf('Downloading from %s...', $pharUrl));
        $tempFile = sys_get_temp_dir() . '/kvs-' . uniqid() . '.phar';

        if (!$this->downloadFile($pharUrl, $tempFile, $io)) {
            return Command::FAILURE;
        }

        // Verify the downloaded PHAR works
        $io->text('Verifying new version...');
        if (!$this->verifyPhar($tempFile, $io)) {
            @unlink($tempFile);
            return Command::FAILURE;
        }

        // Replace old PHAR with new one
        $io->text('Installing update...');

        // Preserve permissions
        $mode = fileperms($currentPhar) & 0777;

        if (!@chmod($tempFile, $mode)) {
            $io->error(sprintf('Cannot set permissions on %s', $tempFile));
            @unlink($tempFile);
            return Command::FAILURE;
        }

        if (!@rename($tempFile, $currentPhar)) {
            $io->error(sprintf('Cannot replace %s', $currentPhar));
            $io->text('Try: sudo kvs self-update');
            @unlink($tempFile);
            return Command::FAILURE;
        }

        $io->success(sprintf('Updated KVS CLI to %s.', $latestVersion));

        return Command::SUCCESS;
    }

    private function isRunningAsPhar(): bool
    {
        return strlen(\Phar::running()) > 0;
    }

    private function getCurrentVersion(): string
    {
        // Try to get version from Application
        if (defined('KVS_CLI_VERSION')) {
            return KVS_CLI_VERSION;
        }

        // Fallback
        return '0.0.0';
    }

    /**
     * @return array<array{tag_name: string, prerelease: bool, assets: array}>|null
     */
    private function getGitHubReleases(SymfonyStyle $io, bool $includePrerelease): ?array
    {
        $url = sprintf('https://api.github.com/repos/%s/releases', Constants::GITHUB_REPO);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: KVS-CLI',
                    'Accept: application/vnd.github.v3+json',
                ],
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $io->error('Failed to fetch releases from GitHub.');
            return null;
        }

        $releases = json_decode($response, true);

        if (!is_array($releases)) {
            $io->error('Invalid response from GitHub API.');
            return null;
        }

        // Filter releases
        $filtered = [];
        foreach ($releases as $release) {
            // Skip drafts
            if ($release['draft'] ?? false) {
                continue;
            }

            // Skip prereleases unless requested
            if (!$includePrerelease && ($release['prerelease'] ?? false)) {
                continue;
            }

            $filtered[] = $release;
        }

        return $filtered;
    }

    /**
     * @param array<array{name: string, browser_download_url: string}> $assets
     */
    private function findPharAsset(array $assets): ?string
    {
        foreach ($assets as $asset) {
            if (($asset['name'] ?? '') === self::PHAR_NAME) {
                return $asset['browser_download_url'];
            }
        }

        return null;
    }

    private function downloadFile(string $url, string $destination, SymfonyStyle $io): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: KVS-CLI',
                    'Accept: application/octet-stream',
                ],
                'timeout' => 300,
                'follow_location' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $io->error('Failed to download update.');
            return false;
        }

        if (file_put_contents($destination, $content) === false) {
            $io->error('Failed to save downloaded file.');
            return false;
        }

        return true;
    }

    private function verifyPhar(string $pharPath, SymfonyStyle $io): bool
    {
        // Try to run the new PHAR to verify it works
        $php = PHP_BINARY;
        $output = [];
        $returnCode = 0;

        exec(sprintf('%s %s --version 2>&1', escapeshellarg($php), escapeshellarg($pharPath)), $output, $returnCode);

        if ($returnCode !== 0) {
            $io->error('Downloaded PHAR appears to be broken.');
            $io->text(implode("\n", $output));
            return false;
        }

        $io->text('New version verified.');
        return true;
    }

    private function updateFromDev(SymfonyStyle $io, InputInterface $input, string $currentPhar): int
    {
        $io->text('Fetching latest dev build from CI...');

        // Download ZIP from nightly.link
        $tempZip = sys_get_temp_dir() . '/kvs-dev-' . uniqid() . '.zip';
        $nightlyUrl = sprintf(Constants::NIGHTLY_URL, Constants::GITHUB_REPO);

        if (!$this->downloadFile($nightlyUrl, $tempZip, $io)) {
            return Command::FAILURE;
        }

        // Extract PHAR from ZIP
        $io->text('Extracting...');
        $tempDir = sys_get_temp_dir() . '/kvs-dev-' . uniqid();

        $zip = new \ZipArchive();
        if ($zip->open($tempZip) !== true) {
            $io->error('Failed to open downloaded ZIP file.');
            @unlink($tempZip);
            return Command::FAILURE;
        }

        $zip->extractTo($tempDir);
        $zip->close();
        @unlink($tempZip);

        // Find the PHAR file in extracted contents
        $pharFile = $tempDir . '/' . self::PHAR_NAME;
        if (!file_exists($pharFile)) {
            $io->error('PHAR file not found in downloaded archive.');
            $this->cleanupDir($tempDir);
            return Command::FAILURE;
        }

        // Confirm update
        if (!(bool) $input->getOption('yes')) {
            $io->warning('Dev builds may be unstable.');
            if (!$io->confirm('Update to latest dev build?', false)) {
                $io->text('Update cancelled.');
                $this->cleanupDir($tempDir);
                return Command::SUCCESS;
            }
        }

        // Verify the PHAR works
        $io->text('Verifying...');
        if (!$this->verifyPhar($pharFile, $io)) {
            $this->cleanupDir($tempDir);
            return Command::FAILURE;
        }

        // Install
        $io->text('Installing...');
        $mode = fileperms($currentPhar) & 0777;

        if (!@chmod($pharFile, $mode)) {
            $io->error('Cannot set permissions.');
            $this->cleanupDir($tempDir);
            return Command::FAILURE;
        }

        if (!@rename($pharFile, $currentPhar)) {
            $io->error(sprintf('Cannot replace %s', $currentPhar));
            $io->text('Try: sudo kvs self-update --dev');
            $this->cleanupDir($tempDir);
            return Command::FAILURE;
        }

        $this->cleanupDir($tempDir);

        // Get new version
        $output = [];
        exec(sprintf('%s %s --version 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($currentPhar)), $output);
        $newVersion = trim(implode('', $output));

        $io->success(sprintf('Updated to dev build: %s', $newVersion));

        return Command::SUCCESS;
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }

        @rmdir($dir);
    }
}
