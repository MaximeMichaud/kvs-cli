<?php

namespace KVS\CLI\Command\Migrate;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Config\Configuration;
use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'migrate:package',
    description: 'Create a portable migration package',
    aliases: ['package']
)]
class PackageCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to KVS installation')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path')
            ->addOption('no-content', null, InputOption::VALUE_NONE, 'Skip content files (DB only)')
            ->addOption('compression', 'c', InputOption::VALUE_REQUIRED, 'Compression level 1-19', '3')
            ->setHelp(<<<'EOT'
Create a portable migration package containing database and content files.

The package is a tar archive compressed with zstd containing:
  • database.sql.zst  - Database dump (compressed)
  • content/          - Content files (videos, screenshots, etc.)
  • metadata.json     - Package metadata (version, paths, checksums)

<info>Examples:</info>
  kvs migrate:package                              # Package current installation
  kvs migrate:package /var/www/site                # Package specific installation
  kvs migrate:package -o backup.tar.zst            # Custom output path
  kvs migrate:package --no-content                 # Database only (smaller)
  kvs migrate:package -c 9                         # Higher compression (slower)

<info>Compression levels:</info>
  1-3   Fast compression (default: 3)
  4-9   Balanced
  10-19 Maximum compression (slow)
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $targetPath = $this->getStringArgument($input, 'path');
        $noContent = $this->getBoolOption($input, 'no-content');
        $compressionLevel = (int) ($this->getStringOption($input, 'compression') ?? '3');

        if ($compressionLevel < 1 || $compressionLevel > 19) {
            $this->io()->error('Compression level must be between 1 and 19');
            return self::FAILURE;
        }

        // Load target config
        $targetConfig = $this->loadTargetConfig($targetPath);
        if ($targetConfig === null) {
            return self::FAILURE;
        }

        // Check required tools
        if (!$this->checkRequiredTools()) {
            return self::FAILURE;
        }

        // Determine output file
        $outputFile = $this->getStringOption($input, 'output') ?? $this->generateOutputPath($targetConfig);

        $this->io()->title('KVS Migration Package');
        $this->io()->text([
            'Source: ' . $targetConfig->getKvsPath(),
            'Output: ' . $outputFile,
            'Compression: zstd level ' . $compressionLevel,
        ]);
        $this->io()->newLine();

        // Create temp directory
        $tempDir = sys_get_temp_dir() . '/kvs-package-' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            $this->io()->error('Failed to create temp directory');
            return self::FAILURE;
        }

        try {
            // Step 1: Export database
            $this->io()->section('Step 1/3: Exporting database');
            $dbFile = $this->exportDatabase($targetConfig, $tempDir, $compressionLevel);
            if ($dbFile === null) {
                return self::FAILURE;
            }

            // Step 2: Copy content (optional)
            $contentDir = null;
            if (!$noContent) {
                $this->io()->section('Step 2/3: Copying content files');
                $contentDir = $this->copyContent($targetConfig, $tempDir);
            } else {
                $this->io()->section('Step 2/3: Skipping content files');
                $this->io()->comment('--no-content specified');
            }

            // Step 3: Create metadata
            $this->io()->section('Step 3/3: Creating package');
            $metadata = $this->createMetadata($targetConfig, $dbFile, $contentDir);
            $metadataJson = json_encode($metadata, Constants::JSON_FLAGS);
            file_put_contents($tempDir . '/metadata.json', $metadataJson !== false ? $metadataJson : '{}');

            // Create final archive
            $this->io()->text('Compressing package...');
            if (!$this->createArchive($tempDir, $outputFile, $compressionLevel)) {
                return self::FAILURE;
            }

            // Show result
            $size = filesize($outputFile);
            $this->io()->newLine();
            $this->io()->success([
                'Package created successfully!',
                '',
                'File: ' . $outputFile,
                'Size: ' . ($size !== false ? format_bytes($size) : '0 B'),
            ]);

            // Show contents summary
            $dbSize = filesize($dbFile);
            $this->io()->table(
                ['Component', 'Included'],
                [
                    ['Database', 'Yes (' . format_bytes($dbSize !== false ? $dbSize : 0) . ')'],
                    ['Content', $contentDir !== null ? 'Yes' : 'No (--no-content)'],
                    ['Metadata', 'Yes'],
                ]
            );
        } finally {
            // Cleanup temp directory
            $this->cleanup($tempDir);
        }

        return self::SUCCESS;
    }

    private function loadTargetConfig(?string $path): ?Configuration
    {
        try {
            if ($path !== null) {
                return new Configuration(['path' => $path]);
            }
            return $this->config;
        } catch (\Exception $e) {
            $this->io()->error($e->getMessage());
            return null;
        }
    }

    private function checkRequiredTools(): bool
    {
        $tools = [
            'zstd' => 'zstd --version',
            'tar' => 'tar --version',
        ];

        // Check for dump command
        $dumpCmd = $this->getDumpCommand();
        if ($dumpCmd === null) {
            $tools['mysqldump/mariadb-dump'] = 'mysqldump --version';
        }

        $missing = [];
        foreach ($tools as $name => $check) {
            $result = shell_exec("which " . explode(' ', $check)[0] . " 2>/dev/null");
            if (!is_string($result) || trim($result) === '') {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            $this->io()->error('Missing required tools: ' . implode(', ', $missing));
            $this->io()->text('Install with: <info>apt install zstd mariadb-client</info>');
            return false;
        }

        return true;
    }

    private function getDumpCommand(): ?string
    {
        // Prefer mariadb-dump over mysqldump
        foreach (['mariadb-dump', 'mysqldump'] as $cmd) {
            $result = shell_exec("which $cmd 2>/dev/null");
            if (is_string($result) && trim($result) !== '') {
                return $cmd;
            }
        }
        return null;
    }

    private function generateOutputPath(Configuration $config): string
    {
        $siteName = basename($config->getKvsPath());
        $date = date('Y-m-d_H-i-s');
        return getcwd() . "/kvs-{$siteName}-{$date}.tar.zst";
    }

    private function exportDatabase(Configuration $config, string $tempDir, int $level): ?string
    {
        $dbConfig = $config->getDatabaseConfig();
        if ($dbConfig === []) {
            $this->io()->error('Database configuration not found');
            return null;
        }

        $dumpCmd = $this->getDumpCommand();
        if ($dumpCmd === null) {
            $this->io()->error('mysqldump/mariadb-dump not found');
            return null;
        }

        $host = $dbConfig['host'];
        $port = Constants::DEFAULT_MYSQL_PORT;
        if (str_contains($host, ':')) {
            [$host, $portStr] = explode(':', $host, 2);
            $port = (int) $portStr;
        }

        $outputFile = $tempDir . '/database.sql.zst';

        // Build dump command
        $command = sprintf(
            '%s --host=%s --port=%d --user=%s --password=%s %s 2>/dev/null | zstd -%d -o %s',
            $dumpCmd,
            escapeshellarg($host),
            $port,
            escapeshellarg($dbConfig['user']),
            escapeshellarg($dbConfig['password']),
            escapeshellarg($dbConfig['database']),
            $level,
            escapeshellarg($outputFile)
        );

        $this->io()->text('Dumping database...');

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600); // 1 hour timeout
        $process->run();

        if (!$process->isSuccessful()) {
            $this->io()->error('Database export failed: ' . $process->getErrorOutput());
            return null;
        }

        $size = filesize($outputFile);
        $this->io()->text('Database exported: ' . ($size !== false ? format_bytes($size) : 'unknown'));

        return $outputFile;
    }

    private function copyContent(Configuration $config, string $tempDir): ?string
    {
        $contentPath = $config->getContentPath();
        if (!is_dir($contentPath)) {
            $this->io()->warning('Content directory not found: ' . $contentPath);
            return null;
        }

        $destDir = $tempDir . '/content';
        mkdir($destDir, 0755, true);

        // List of content subdirectories to copy
        $dirs = [
            Constants::CONTENT_VIDEOS_SOURCES,
            Constants::CONTENT_VIDEOS_SCREENSHOTS,
            Constants::CONTENT_ALBUMS_SOURCES,
            Constants::CONTENT_CATEGORIES,
            Constants::CONTENT_MODELS,
            Constants::CONTENT_DVDS,
            Constants::CONTENT_AVATARS,
        ];

        $totalFiles = 0;
        $totalSize = 0;

        foreach ($dirs as $dir) {
            $srcPath = $contentPath . '/' . $dir;
            if (!is_dir($srcPath)) {
                continue;
            }

            $destPath = $destDir . '/' . $dir;
            $this->io()->text("Copying {$dir}...");

            // Use rsync for efficient copy with progress
            $process = new Process([
                'rsync', '-a', '--info=progress2',
                $srcPath . '/',
                $destPath . '/'
            ]);
            $process->setTimeout(3600);
            $process->run(function (string $type, string $buffer): void {
                // Show rsync progress
                if ($type === Process::OUT && str_contains($buffer, '%')) {
                    $this->io()->write("\r" . trim($buffer));
                }
            });
            $this->io()->newLine();

            if (!$process->isSuccessful()) {
                // Fallback to cp if rsync fails
                $process = Process::fromShellCommandline(
                    'cp -r ' . escapeshellarg($srcPath) . ' ' . escapeshellarg($destPath)
                );
                $process->setTimeout(3600);
                $process->run();
            }

            // Count files
            $countResult = shell_exec("find " . escapeshellarg($destPath) . " -type f 2>/dev/null | wc -l");
            if (is_string($countResult)) {
                $totalFiles += (int) trim($countResult);
            }
        }

        // Get total size
        $sizeResult = shell_exec("du -sb " . escapeshellarg($destDir) . " 2>/dev/null | cut -f1");
        if (is_string($sizeResult)) {
            $totalSize = (int) trim($sizeResult);
        }

        $this->io()->text("Content copied: {$totalFiles} files, " . format_bytes($totalSize));

        return $destDir;
    }

    /**
     * @return array<string, mixed>
     */
    private function createMetadata(Configuration $config, string $dbFile, ?string $contentDir): array
    {
        $metadata = [
            'version' => '1.0',
            'created_at' => date('c'),
            'kvs_version' => $config->getKvsVersion(),
            'source_path' => $config->getKvsPath(),
            'table_prefix' => $config->getTablePrefix(),
            'database' => [
                'file' => 'database.sql.zst',
                'size' => filesize($dbFile) !== false ? filesize($dbFile) : 0,
            ],
            'content' => [
                'included' => $contentDir !== null,
            ],
        ];

        if ($contentDir !== null) {
            $sizeResult = shell_exec("du -sb " . escapeshellarg($contentDir) . " 2>/dev/null | cut -f1");
            $countResult = shell_exec("find " . escapeshellarg($contentDir) . " -type f 2>/dev/null | wc -l");

            $metadata['content']['size'] = is_string($sizeResult) ? (int) trim($sizeResult) : 0;
            $metadata['content']['files'] = is_string($countResult) ? (int) trim($countResult) : 0;
        }

        return $metadata;
    }

    private function createArchive(string $sourceDir, string $outputFile, int $level): bool
    {
        // Create tar.zst archive
        $command = sprintf(
            'tar -C %s -cf - . | zstd -%d -o %s',
            escapeshellarg($sourceDir),
            $level,
            escapeshellarg($outputFile)
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->io()->error('Archive creation failed: ' . $process->getErrorOutput());
            return false;
        }

        return true;
    }

    private function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            $process = Process::fromShellCommandline('rm -rf ' . escapeshellarg($dir));
            $process->run();
        }
    }
}
