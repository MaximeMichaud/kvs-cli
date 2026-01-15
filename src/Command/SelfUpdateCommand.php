<?php

declare(strict_types=1);

namespace KVS\CLI\Command;

use KVS\CLI\Constants;
use KVS\CLI\Service\TempFileManager;
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

        $argv = $_SERVER['argv'] ?? [];
        $argvZero = is_array($argv) && isset($argv[0]) ? $argv[0] : null;
        $argvPath = is_string($argvZero) ? $argvZero : '';
        $realPhar = realpath($argvPath);
        $currentPhar = $realPhar !== false ? $realPhar : $argvPath;
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
        /** @var bool $devOption */
        $devOption = $input->getOption('dev');
        if ($devOption) {
            return $this->updateFromDev($io, $input, $currentPhar);
        }

        $io->text('Checking for updates...');

        // Get available releases
        $includePrerelease = $input->getOption('preview');
        $releases = $this->getGitHubReleases($io, $includePrerelease);

        if ($releases === null) {
            return Command::FAILURE;
        }

        if ($releases === []) {
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
        /** @var bool $checkOption */
        $checkOption = $input->getOption('check');
        if ($checkOption) {
            $io->newLine();
            $io->text('Run <info>kvs self-update</info> to install the update.');
            return Command::SUCCESS;
        }

        // Confirm update
        /** @var bool $yesOption */
        $yesOption = $input->getOption('yes');
        if (!$yesOption) {
            if (!$io->confirm(sprintf('Update to version %s?', $latestVersion), true)) {
                $io->text('Update cancelled.');
                return Command::SUCCESS;
            }
        }

        // Find PHAR asset
        $pharUrl = $this->findPharAsset($latest['assets']);
        if ($pharUrl === null) {
            $io->error('Could not find PHAR file in release assets.');
            return Command::FAILURE;
        }

        // Download new version (auto-cleanup on error via TempFileManager)
        $io->text(sprintf('Downloading from %s...', $pharUrl));
        $tempFile = TempFileManager::create('kvs-update-', '.phar');

        if (!$this->downloadFile($pharUrl, $tempFile, $io)) {
            return Command::FAILURE;
        }

        // Verify the downloaded PHAR works
        $io->text('Verifying new version...');
        if (!$this->verifyPhar($tempFile, $io)) {
            // Cleanup handled by TempFileManager shutdown handler
            return Command::FAILURE;
        }

        // Replace old PHAR with new one
        $io->text('Installing update...');

        // Preserve permissions
        $mode = fileperms($currentPhar) & 0777;

        if (!@chmod($tempFile, $mode)) {
            $io->error(sprintf('Cannot set permissions on %s', $tempFile));
            // Cleanup handled by TempFileManager shutdown handler
            return Command::FAILURE;
        }

        if (!@rename($tempFile, $currentPhar)) {
            $io->error(sprintf('Cannot replace %s', $currentPhar));
            $io->text('Try: sudo kvs self-update');
            // Cleanup handled by TempFileManager shutdown handler
            return Command::FAILURE;
        }

        // IMPORTANT: After replacing the PHAR, we cannot use Symfony Console output methods
        // like $io->success() because PHP still has old class definitions in memory that
        // may conflict with the new PHAR's classes, causing fatal errors.
        // Use plain echo instead.
        echo "\n";
        echo " [OK] Updated KVS CLI to {$latestVersion}.\n";
        echo "\n";

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
            /** @var string $version */
            $version = KVS_CLI_VERSION;
            return $version;
        }

        // Fallback
        return '0.0.0';
    }

    /**
     * @return array<array{tag_name: string, prerelease: bool, assets: array<array{name: string, browser_download_url: string}>}>|null
     */
    private function getGitHubReleases(SymfonyStyle $io, bool $includePrerelease): ?array
    {
        $url = sprintf('%s/repos/%s/releases', Constants::GITHUB_API_URL, Constants::GITHUB_REPO);

        $headers = [
            'User-Agent: ' . \KVS\CLI\Application::NAME,
            'Accept: application/vnd.github.v3+json',
        ];

        // Support GITHUB_TOKEN for higher rate limits
        $githubToken = getenv('GITHUB_TOKEN');
        if ($githubToken !== false && $githubToken !== '') {
            $headers[] = 'Authorization: token ' . $githubToken;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => Constants::HTTP_REQUEST_TIMEOUT,
                'ignore_errors' => true, // Get response body even on HTTP errors
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            $io->error('Failed to fetch releases from GitHub.');
            if ($error !== null) {
                $io->text('Error: ' . $error['message']);
            }
            return null;
        }

        // Check HTTP status from response headers
        // $http_response_header is a magic variable populated by file_get_contents()
        // @phpstan-ignore isset.variable, function.alreadyNarrowedType, booleanAnd.alwaysTrue
        $responseHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        $httpCode = $this->getHttpStatusCode($responseHeaders);
        if ($httpCode !== 200) {
            $io->error(sprintf('GitHub API returned HTTP %d.', $httpCode));
            if ($httpCode === 403) {
                $io->text('This may be due to rate limiting. Set GITHUB_TOKEN environment variable to increase limits.');
            }
            return null;
        }

        $releases = json_decode($response, true);

        if (!is_array($releases)) {
            $io->error('Invalid response from GitHub API.');
            return null;
        }

        // Check if GitHub returned an error object instead of releases array
        if (isset($releases['message']) && is_string($releases['message'])) {
            $io->error('GitHub API error: ' . $releases['message']);
            if (isset($releases['documentation_url']) && is_string($releases['documentation_url'])) {
                $io->text('See: ' . $releases['documentation_url']);
            }
            return null;
        }

        // Filter releases
        /** @var array<array{tag_name: string, prerelease: bool, assets: array<array{name: string, browser_download_url: string}>}> $filtered */
        $filtered = [];
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }
            // Skip drafts
            $isDraft = isset($release['draft']) && $release['draft'] === true;
            if ($isDraft) {
                continue;
            }

            // Skip prereleases unless requested
            $isPrerelease = isset($release['prerelease']) && $release['prerelease'] === true;
            if (!$includePrerelease && $isPrerelease) {
                continue;
            }

            // Validate required fields exist
            if (!isset($release['tag_name']) || !is_string($release['tag_name'])) {
                continue;
            }
            if (!isset($release['assets']) || !is_array($release['assets'])) {
                continue;
            }

            /** @var array{tag_name: string, prerelease: bool, assets: array<array{name: string, browser_download_url: string}>} $release */
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
            if ($asset['name'] === Constants::PHAR_NAME) {
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
                    'User-Agent: ' . \KVS\CLI\Application::NAME,
                    'Accept: application/octet-stream',
                ],
                'timeout' => Constants::DOWNLOAD_TIMEOUT,
                'follow_location' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $io->error('Failed to download update.');
            return false;
        }

        // Detect HTML error pages (common with nightly.link rate limiting)
        $prefix = substr($content, 0, 100);
        if (
            stripos($prefix, '<!DOCTYPE') !== false ||
            stripos($prefix, '<html') !== false ||
            stripos($prefix, '<!doctype') !== false
        ) {
            $io->error('Download returned an HTML error page instead of the file.');
            $io->text('This usually means the download service is rate-limited or temporarily unavailable.');
            $io->text('Try again in a few minutes, or download manually from GitHub.');
            return false;
        }

        // Validate file signature based on expected type
        $extension = pathinfo($destination, PATHINFO_EXTENSION);
        if ($extension === 'zip') {
            // ZIP files should start with PK (0x504B)
            if (substr($content, 0, 2) !== 'PK') {
                $io->error('Downloaded file is not a valid ZIP archive.');
                $io->text('The download may have been corrupted or interrupted.');
                return false;
            }
        } elseif ($extension === 'phar') {
            // PHAR files should start with shebang or <?php
            if (substr($content, 0, 2) !== '#!' && substr($content, 0, 5) !== '<?php') {
                $io->error('Downloaded file is not a valid PHAR archive.');
                $io->text('The download may have been corrupted or interrupted.');
                return false;
            }
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

        // Get latest successful workflow run ID to avoid nightly.link cache issues
        $runId = $this->getLatestWorkflowRunId($io);
        if ($runId === null) {
            return Command::FAILURE;
        }

        // Download ZIP from nightly.link (auto-cleanup via TempFileManager)
        $tempZip = TempFileManager::create('kvs-dev-', '.zip');
        $nightlyUrl = sprintf(
            Constants::NIGHTLY_LINK_URL,
            Constants::GITHUB_REPO,
            $runId,
            Constants::GITHUB_ARTIFACT_NAME
        );

        if (!$this->downloadFile($nightlyUrl, $tempZip, $io)) {
            return Command::FAILURE;
        }

        // Extract PHAR from ZIP
        $io->text('Extracting...');

        if (!class_exists('ZipArchive')) {
            $io->error('Extracting a ZIP file requires the ZipArchive PHP extension.');
            // Cleanup handled by TempFileManager shutdown handler
            return Command::FAILURE;
        }

        $tempDir = TempFileManager::createDirectory('kvs-dev-');

        $zip = new \ZipArchive();
        if ($zip->open($tempZip) !== true) {
            $io->error('Failed to open downloaded ZIP file.');
            // Cleanup handled by TempFileManager shutdown handler
            return Command::FAILURE;
        }

        $zip->extractTo($tempDir);
        $zip->close();
        // Note: tempZip cleanup handled by TempFileManager shutdown handler

        // Find the PHAR file in extracted contents
        $pharFile = $tempDir . '/' . Constants::PHAR_NAME;
        if (!file_exists($pharFile)) {
            $io->error('PHAR file not found in downloaded archive.');
            // Cleanup handled by TempFileManager shutdown handler
            return Command::FAILURE;
        }

        // Confirm update
        /** @var bool $yesOptDev */
        $yesOptDev = $input->getOption('yes');
        if (!$yesOptDev) {
            $io->warning('Dev builds may be unstable.');
            if (!$io->confirm('Update to latest dev build?', false)) {
                $io->text('Update cancelled.');
                // Cleanup handled by TempFileManager shutdown handler
                return Command::SUCCESS;
            }
        }

        // Verify the PHAR works
        $io->text('Verifying...');
        if (!$this->verifyPhar($pharFile, $io)) {
            // Cleanup handled by TempFileManager shutdown handler
            return Command::FAILURE;
        }

        // Install
        $io->text('Installing...');

        $mode = fileperms($currentPhar) & 0777;

        if (!@chmod($pharFile, $mode)) {
            $io->error('Cannot set permissions.');
            // Cleanup handled by TempFileManager shutdown handler
            return Command::FAILURE;
        }

        if (!@rename($pharFile, $currentPhar)) {
            $io->error(sprintf('Cannot replace %s', $currentPhar));
            $io->text('Try: sudo kvs self-update --dev');
            // Cleanup handled by TempFileManager shutdown handler
            return Command::FAILURE;
        }

        // Note: tempDir cleanup handled by TempFileManager shutdown handler

        // IMPORTANT: After replacing the PHAR, we cannot use Symfony Console output methods
        // like $io->success() because PHP still has old class definitions in memory that
        // may conflict with the new PHAR's classes, causing fatal errors.
        // Use plain echo instead.
        $output = [];
        exec(sprintf('%s %s --version 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($currentPhar)), $output);
        $newVersion = trim(implode('', $output));

        // Use echo instead of $io->success() - avoids class conflicts after PHAR replacement
        echo "\n";
        echo " [OK] Updated to dev build: {$newVersion}\n";
        echo "\n";

        return Command::SUCCESS;
    }

    private function getLatestWorkflowRunId(SymfonyStyle $io): ?string
    {
        $url = sprintf(
            '%s/repos/%s/actions/workflows/ci.yml/runs?branch=dev&status=success&per_page=1',
            Constants::GITHUB_API_URL,
            Constants::GITHUB_REPO
        );

        $headers = [
            'User-Agent: ' . \KVS\CLI\Application::NAME,
            'Accept: application/vnd.github.v3+json',
        ];

        // Support GITHUB_TOKEN for higher rate limits
        $githubToken = getenv('GITHUB_TOKEN');
        if ($githubToken !== false && $githubToken !== '') {
            $headers[] = 'Authorization: token ' . $githubToken;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => Constants::HTTP_REQUEST_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            $io->error('Failed to fetch workflow runs from GitHub.');
            if ($error !== null) {
                $io->text('Error: ' . $error['message']);
            }
            return null;
        }

        // Check HTTP status from response headers
        // $http_response_header is a magic variable populated by file_get_contents()
        // @phpstan-ignore isset.variable, function.alreadyNarrowedType, booleanAnd.alwaysTrue
        $responseHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        $httpCode = $this->getHttpStatusCode($responseHeaders);
        if ($httpCode !== 200) {
            $io->error(sprintf('GitHub API returned HTTP %d.', $httpCode));
            if ($httpCode === 403) {
                $io->text('This may be due to rate limiting. Set GITHUB_TOKEN environment variable to increase limits.');
            } elseif ($httpCode === 404) {
                $io->text('Workflow not found. The CI workflow may not exist or may be private.');
            }
            return null;
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            $io->error('Invalid response from GitHub API.');
            return null;
        }

        // Check if GitHub returned an error object
        if (isset($data['message']) && is_string($data['message'])) {
            $io->error('GitHub API error: ' . $data['message']);
            if (isset($data['documentation_url']) && is_string($data['documentation_url'])) {
                $io->text('See: ' . $data['documentation_url']);
            }
            return null;
        }

        if (!isset($data['workflow_runs']) || !is_array($data['workflow_runs']) || count($data['workflow_runs']) === 0) {
            $io->error('No successful workflow runs found on the dev branch.');
            return null;
        }

        $firstRun = $data['workflow_runs'][0] ?? null;
        if (!is_array($firstRun)) {
            $io->error('Invalid workflow run data structure.');
            return null;
        }

        $runId = $firstRun['id'] ?? null;
        if (!is_int($runId) && !is_string($runId)) {
            $io->error('Workflow run data is missing the run ID.');
            return null;
        }

        return (string) $runId;
    }

    /**
     * Extract HTTP status code from response headers.
     *
     * @param array<string> $headers Response headers from $http_response_header
     */
    private function getHttpStatusCode(array $headers): int
    {
        if (count($headers) === 0) {
            return 0;
        }

        // First header line contains the status, e.g., "HTTP/1.1 200 OK"
        // Handle redirects by finding the last HTTP status line
        $statusLine = '';
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\d+\.\d+ \d+#', $header) === 1) {
                $statusLine = $header;
            }
        }

        if (preg_match('#^HTTP/\d+\.\d+ (\d+)#', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
