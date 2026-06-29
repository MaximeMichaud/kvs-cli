<?php

namespace KVS\CLI\Command\Video;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Output\Formatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'video:screenshots',
    description: 'Manage video screenshots',
    aliases: ['screenshots']
)]
class ScreenshotsCommand extends BaseCommand
{
    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml', 'count'];
    private const GENERATE_UNSUPPORTED_OPTIONS = ['fields', 'format', 'no-truncate'];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|generate|regenerate)', 'list')
            ->addArgument('video_id', InputArgument::OPTIONAL, 'Video ID')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Number of screenshots to generate', 10)
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: table, csv, json, yaml, count',
                'table'
            )
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields')
            ->setHelp(<<<'HELP'
Manage video screenshots (thumbnails).

<fg=yellow>ACTIONS:</>
  list <video_id>           List existing screenshots for a video
  generate <video_id>       Generate screenshots for a video
  regenerate <video_id>     Regenerate screenshots (delete + generate)

<fg=yellow>OPTIONS:</>
  --count=N                 Number of screenshots to generate (default: 10)

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs screenshots list 123</>
  <fg=green>kvs screenshots generate 123 --count=20</>
  <fg=green>kvs screenshots regenerate 123</>
  <fg=green>kvs screenshots list 123 --format=json</>
  <fg=green>kvs video:screenshots list 123 --format=count</>

<fg=yellow>NOTE:</>
  This command scans the content directory for screenshot files.
  Generate/regenerate require ffmpeg to be installed.
HELP
            );
    }

    protected function execute(InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        [$action, $videoId] = $this->resolveActionAndVideoId($input);

        return match ($action) {
            'list' => $this->listScreenshots($input, $videoId),
            'generate' => $this->generateScreenshots($input, $videoId),
            'regenerate' => $this->regenerateScreenshots($input, $videoId),
            default => $this->failUnknownAction('screenshots', $action, ['list', 'generate', 'regenerate']),
        };
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function resolveActionAndVideoId(InputInterface $input): array
    {
        $action = $this->getStringArgument($input, 'action') ?? 'list';
        $videoId = $this->getStringArgument($input, 'video_id');

        if ($videoId === null && ctype_digit($action)) {
            return ['list', $action];
        }

        return [$action, $videoId];
    }

    private function listScreenshots(InputInterface $input, ?string $videoId): int
    {
        if ($this->rejectUnsupportedOptions($input, 'list', ['count'])) {
            return self::FAILURE;
        }

        if ($videoId === null) {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:screenshots list <video_id>');
            return self::FAILURE;
        }

        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $videoId = $this->normalizeVideoId($videoId);
        if ($videoId === null) {
            return self::FAILURE;
        }

        $screenshotsBasePath = $this->config->getVideoScreenshotsPath();
        if ($screenshotsBasePath === '') {
            $this->io()->error('Screenshots path not configured');
            return self::FAILURE;
        }

        $screenshotsPath = $this->getVideoContentDir($screenshotsBasePath, $videoId);
        $screenshots = $this->buildLogicalScreenshotRows($videoId, $screenshotsPath);
        if ($screenshots !== []) {
            $formatter = new Formatter($input->getOptions(), ['index', 'filename', 'formats', 'dimensions']);
            $formatter->display($screenshots, $this->io());

            return self::SUCCESS;
        }

        if (!is_dir($screenshotsPath)) {
            if (!$this->ensureVideoIsManageableInKvs($videoId)) {
                return self::FAILURE;
            }

            if (!$this->isTableFormat($input)) {
                return $this->displayFormattedRows($input, [], ['filename', 'size', 'dimensions', 'path']);
            }

            $this->io()->warning("Screenshots directory not found: $screenshotsPath");
            $this->io()->note("The video might not have screenshots generated yet.");
            return self::SUCCESS;
        }

        // Scan for screenshot files (common extensions)
        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        $files = [];

        $files = $this->findImageFiles($screenshotsPath, $extensions);

        if ($files === []) {
            if (!$this->ensureVideoIsManageableInKvs($videoId)) {
                return self::FAILURE;
            }

            if (!$this->isTableFormat($input)) {
                return $this->displayFormattedRows($input, [], ['filename', 'size', 'dimensions', 'path']);
            }

            $this->io()->warning('No screenshot files found in directory');
            $this->io()->text("Directory: $screenshotsPath");
            return self::SUCCESS;
        }

        $screenshots = [];
        foreach ($files as $file) {
            $filename = $this->getRelativePath($screenshotsPath, $file);
            $filesize = filesize($file);

            // Try to get image dimensions
            $dimensions = $this->getImageDimensions($file);

            $screenshots[] = [
                'filename' => $filename,
                'size' => $filesize !== false ? format_bytes($filesize) : 'Unknown',
                'dimensions' => $dimensions,
                'path' => $file,
            ];
        }

        // Sort by filename
        usort($screenshots, fn($a, $b) => strcmp($a['filename'], $b['filename']));

        // Default fields for display
        $defaultFields = ['filename', 'size', 'dimensions'];

        $formatter = new Formatter($input->getOptions(), $defaultFields);
        $formatter->display($screenshots, $this->io());

        return self::SUCCESS;
    }

    private function ensureVideoIsManageableInKvs(string $videoId): bool
    {
        $db = $this->getDatabaseConnection(true);
        if ($db === null) {
            return true;
        }

        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM {$this->table('videos')} WHERE video_id = :id AND status_id IN (0, 1)"
            );
            $stmt->bindValue('id', (int) $videoId, \PDO::PARAM_INT);
            $stmt->execute();
            if ((int) $stmt->fetchColumn() > 0) {
                return true;
            }
        } catch (\Throwable) {
            return true;
        }

        $this->io()->error("Video not found or screenshots are not manageable in KVS admin: $videoId");
        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLogicalScreenshotRows(string $videoId, string $screenshotsPath): array
    {
        $rows = [];
        $sourceScreenshotsPath = $this->getVideoContentDir(
            $this->config->getVideoSourcesPath(),
            $videoId
        ) . '/screenshots';

        foreach ($this->discoverLogicalScreenshotIndexes($sourceScreenshotsPath, $screenshotsPath) as $index) {
            $files = $this->findLogicalScreenshotFiles($sourceScreenshotsPath, $screenshotsPath, $index);
            $representative = $files[0] ?? null;
            $filesize = $representative !== null ? filesize($representative) : false;

            $rows[] = [
                'index' => $index,
                'filename' => $index . '.jpg',
                'formats' => count($files),
                'size' => $filesize !== false ? format_bytes($filesize) : 'Unknown',
                'dimensions' => $representative !== null ? $this->getImageDimensions($representative) : '',
                'path' => $representative ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function findLogicalScreenshotFiles(
        string $sourceScreenshotsPath,
        string $screenshotsPath,
        int $index
    ): array {
        $files = [];
        foreach ($this->findScreenshotFilesForIndex($sourceScreenshotsPath, $screenshotsPath, $index) as $file) {
            $files[] = $file;
        }

        return array_values(array_unique($files));
    }

    /**
     * @return list<int>
     */
    private function discoverLogicalScreenshotIndexes(string $sourceScreenshotsPath, string $screenshotsPath): array
    {
        $indexes = [];
        foreach ($this->listImmediateImageFiles($sourceScreenshotsPath) as $file) {
            $index = $this->parseScreenshotIndex($file);
            if ($index !== null) {
                $indexes[] = $index;
            }
        }

        foreach ($this->listDirectFormatImageFiles($screenshotsPath) as $file) {
            $index = $this->parseScreenshotIndex($file);
            if ($index !== null) {
                $indexes[] = $index;
            }
        }

        $indexes = array_values(array_unique($indexes));
        sort($indexes);

        return $indexes;
    }

    /**
     * @return list<string>
     */
    private function findScreenshotFilesForIndex(string $sourceScreenshotsPath, string $screenshotsPath, int $index): array
    {
        $files = [];
        foreach ($this->getScreenshotFilenameCandidates($index) as $filename) {
            $sourceFile = $sourceScreenshotsPath . '/' . $filename;
            if (is_file($sourceFile)) {
                $files[] = $sourceFile;
            }
        }

        if (is_dir($screenshotsPath)) {
            foreach ($this->getScreenshotFilenameCandidates($index) as $filename) {
                $matches = glob($screenshotsPath . '/*/' . $filename);
                if ($matches !== false) {
                    foreach ($matches as $match) {
                        $parent = basename(dirname($match));
                        if (is_file($match) && !in_array($parent, ['posters', 'timelines'], true)) {
                            $files[] = $match;
                        }
                    }
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function listImmediateImageFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.{jpg,jpeg,png,webp,avif}', GLOB_BRACE);
        if ($files === false) {
            return [];
        }

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * @return list<string>
     */
    private function listDirectFormatImageFiles(string $screenshotsPath): array
    {
        if (!is_dir($screenshotsPath)) {
            return [];
        }

        $files = [];
        $dirs = glob($screenshotsPath . '/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }

        foreach ($dirs as $dir) {
            $dirname = basename($dir);
            if (in_array($dirname, ['posters', 'timelines'], true)) {
                continue;
            }
            foreach ($this->listImmediateImageFiles($dir) as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function parseScreenshotIndex(string $file): ?int
    {
        $filename = pathinfo($file, PATHINFO_FILENAME);
        if (preg_match('/^\d+$/', $filename) !== 1) {
            return null;
        }

        $index = (int) $filename;
        return $index > 0 ? $index : null;
    }

    /**
     * @return list<string>
     */
    private function getScreenshotFilenameCandidates(int $index): array
    {
        $names = [];
        foreach (['jpg', 'jpeg', 'png', 'webp', 'avif'] as $extension) {
            $names[] = $index . '.' . $extension;
            $names[] = sprintf('%03d.%s', $index, $extension);
        }

        return array_values(array_unique($names));
    }

    private function generateScreenshots(InputInterface $input, ?string $videoId): int
    {
        if ($videoId === null) {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:screenshots generate <video_id>');
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptions($input, 'generate', self::GENERATE_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }

        $videoId = $this->normalizeVideoId($videoId);
        if ($videoId === null) {
            return self::FAILURE;
        }

        $plan = $this->prepareScreenshotGeneration($input, $videoId);
        if ($plan === null) {
            return self::FAILURE;
        }

        $screenshotsPath = $plan['screenshots_path'];
        if (!$this->ensureDirectoryExists($screenshotsPath)) {
            return self::FAILURE;
        }

        return $this->generateScreenshotsToDirectory($plan, $screenshotsPath);
    }

    private function regenerateScreenshots(InputInterface $input, ?string $videoId): int
    {
        if ($videoId === null) {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:screenshots regenerate <video_id>');
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptions($input, 'regenerate', self::GENERATE_UNSUPPORTED_OPTIONS)) {
            return self::FAILURE;
        }

        $videoId = $this->normalizeVideoId($videoId);
        if ($videoId === null) {
            return self::FAILURE;
        }

        $plan = $this->prepareScreenshotGeneration($input, $videoId);
        if ($plan === null) {
            return self::FAILURE;
        }

        $screenshotsPath = $plan['screenshots_path'];
        $stagingPath = $this->createTemporarySiblingDirectory($screenshotsPath, 'regenerate');
        if ($stagingPath === null) {
            return self::FAILURE;
        }

        try {
            $result = $this->generateScreenshotsToDirectory($plan, $stagingPath);
            if ($result !== self::SUCCESS) {
                $this->io()->warning('Existing screenshots were not changed.');
                return $result;
            }

            $this->io()->text('Replacing existing screenshots...');
            $deleted = $this->replaceScreenshotsWithStaging($stagingPath, $screenshotsPath);
            if ($deleted === null) {
                return self::FAILURE;
            }

            $this->io()->text("Deleted $deleted existing screenshots");
            return self::SUCCESS;
        } finally {
            $this->removeDirectoryTree($stagingPath);
        }
    }

    /**
     * @return array{ffmpeg_path: string, video_file: string, screenshots_path: string, duration: float, count: int}|null
     */
    private function prepareScreenshotGeneration(InputInterface $input, string $videoId): ?array
    {
        $ffmpegPath = $this->config->getFfmpegPath();
        $ffprobePath = $this->config->getFfprobePath();

        // Check if ffmpeg is available
        if (!$this->checkFfmpegAvailable($ffmpegPath)) {
            $this->io()->error('ffmpeg is not installed or not accessible');
            $this->io()->text('Screenshot generation requires ffmpeg.');
            $this->io()->newLine();
            $this->io()->text('Installation:');
            $this->io()->text('  • Debian/Ubuntu: apt-get install ffmpeg');
            $this->io()->text('  • Arch/CachyOS:  pacman -S ffmpeg');
            $this->io()->text('  • RHEL/CentOS:   yum install ffmpeg');
            $this->io()->text('  • macOS:         brew install ffmpeg');
            return null;
        }

        $videoSourcesPath = $this->config->getVideoSourcesPath();
        $screenshotsBasePath = $this->config->getVideoScreenshotsPath();
        if ($videoSourcesPath === '' || $screenshotsBasePath === '') {
            $this->io()->error('Content paths not configured');
            return null;
        }

        $videoPath = $this->getVideoContentDir($videoSourcesPath, $videoId);
        $screenshotsPath = $this->getVideoContentDir($screenshotsBasePath, $videoId);

        // Find video source file
        $videoFile = $this->findVideoFile($videoPath);
        if ($videoFile === null) {
            $this->io()->error("No video file found in: $videoPath");
            $this->io()->note("Make sure video files exist before generating screenshots.");
            return null;
        }

        // Get video duration
        $duration = $this->getVideoDuration($videoFile, $ffprobePath);
        if ($duration === null) {
            $this->io()->error("Failed to get video duration for: $videoFile");
            return null;
        }

        $count = $this->getIntOptionOrDefault($input, 'count', 10);

        return [
            'ffmpeg_path' => $ffmpegPath,
            'video_file' => $videoFile,
            'screenshots_path' => $screenshotsPath,
            'duration' => $duration,
            'count' => $count,
        ];
    }

    /**
     * @param array{ffmpeg_path: string, video_file: string, screenshots_path: string, duration: float, count: int} $plan
     */
    private function generateScreenshotsToDirectory(array $plan, string $screenshotsPath): int
    {
        $ffmpegPath = $plan['ffmpeg_path'];
        $videoFile = $plan['video_file'];
        $duration = $plan['duration'];
        $count = $plan['count'];

        $this->io()->text("Generating $count screenshots from video (duration: {$duration}s)...");

        // Generate screenshots
        $interval = $duration / ($count + 1); // +1 to avoid first/last frame
        $success = 0;
        $failed = 0;

        for ($i = 1; $i <= $count; $i++) {
            $timestamp = $interval * $i;
            $filename = sprintf('%03d.jpg', $i);
            $outputFile = "$screenshotsPath/$filename";

            $cmd = sprintf(
                '%s -ss %.2f -i %s -vframes 1 -q:v 2 %s -y 2>&1',
                escapeshellarg($ffmpegPath),
                $timestamp,
                escapeshellarg($videoFile),
                escapeshellarg($outputFile)
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($outputFile)) {
                $success++;
                $this->io()->text("  ✓ Generated $filename");
            } else {
                $failed++;
                $this->io()->text("  ✗ Failed to generate $filename");
            }
        }

        $this->io()->newLine();
        if ($success > 0) {
            $this->io()->success("Generated $success screenshots successfully!");
        }
        if ($failed > 0) {
            $this->io()->warning("Failed to generate $failed screenshots");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Find a video file in the video directory
     */
    private function findVideoFile(string $videoPath): ?string
    {
        $extensions = ['mp4', 'webm', 'mkv', 'avi', 'flv', 'm4v'];

        // Prefer source file
        foreach ($extensions as $ext) {
            if (file_exists("$videoPath/source.$ext")) {
                return "$videoPath/source.$ext";
            }
        }

        // Fallback to any video file
        foreach ($extensions as $ext) {
            $files = glob("$videoPath/*.$ext");
            if ($files !== false && $files !== []) {
                return $files[0];
            }
        }

        return null;
    }

    private function getVideoContentDir(string $basePath, string $videoId): string
    {
        return $basePath . '/' . $this->getDirById($videoId) . '/' . $videoId;
    }

    private function normalizeVideoId(string $videoId): ?string
    {
        if (preg_match('/^[1-9]\d*$/', $videoId) !== 1) {
            $this->io()->error('Invalid video ID (use: integer >= 1)');
            return null;
        }

        return $videoId;
    }

    private function getDirById(string $id): int
    {
        return (int) floor((int) $id / 1000) * 1000;
    }

    /**
     * @param list<string> $extensions
     * @return list<string>
     */
    private function findImageFiles(string $path, array $extensions): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $allowed = array_flip(array_map('strtolower', $extensions));
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!isset($allowed[$extension])) {
                continue;
            }

            $realPath = $file->getRealPath();
            if (is_string($realPath)) {
                $files[] = $realPath;
            }
        }

        return $files;
    }

    private function ensureDirectoryExists(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        if (!mkdir($path, 0755, true)) {
            $this->io()->error("Failed to create screenshots directory: $path");
            return false;
        }

        $this->io()->text("Created screenshots directory: $path");
        return true;
    }

    private function createTemporarySiblingDirectory(string $path, string $purpose): ?string
    {
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
            $this->io()->error("Failed to create temporary screenshots parent directory: $parent");
            return null;
        }

        for ($i = 0; $i < 10; $i++) {
            $suffix = str_replace('.', '', uniqid('', true));
            $candidate = $parent . '/.' . basename($path) . '-' . $purpose . '-' . $suffix;
            if (@mkdir($candidate, 0700)) {
                return $candidate;
            }
        }

        $this->io()->error("Failed to create temporary screenshots directory below: $parent");
        return null;
    }

    private function replaceScreenshotsWithStaging(string $stagingPath, string $screenshotsPath): ?int
    {
        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        $existingFiles = $this->findImageFiles($screenshotsPath, $extensions);
        $backupPath = null;
        $generatedMoved = [];

        try {
            if ($existingFiles !== []) {
                $backupPath = $this->createTemporarySiblingDirectory($screenshotsPath, 'backup');
                if ($backupPath === null) {
                    return null;
                }

                foreach ($existingFiles as $file) {
                    $this->moveFilePreservingRelativePath($file, $screenshotsPath, $backupPath);
                }

                $this->removeEmptyDirectories($screenshotsPath);
            }

            if (!is_dir($screenshotsPath) && !mkdir($screenshotsPath, 0755, true)) {
                throw new \RuntimeException("Failed to create screenshots directory: $screenshotsPath");
            }

            foreach ($this->findAllFiles($stagingPath) as $file) {
                $generatedMoved[] = $this->moveFilePreservingRelativePath($file, $stagingPath, $screenshotsPath);
            }

            if ($backupPath !== null) {
                $this->removeDirectoryTree($backupPath);
            }

            return count($existingFiles);
        } catch (\RuntimeException $exception) {
            foreach ($generatedMoved as $relativePath) {
                @unlink($screenshotsPath . '/' . $relativePath);
            }

            if ($backupPath !== null) {
                $this->restoreFilesFromDirectory($backupPath, $screenshotsPath);
                $this->removeDirectoryTree($backupPath);
            }

            $this->io()->error($exception->getMessage());
            return null;
        }
    }

    private function moveFilePreservingRelativePath(string $file, string $sourceBasePath, string $targetBasePath): string
    {
        $relativePath = $this->getRelativePath($sourceBasePath, $file);
        $target = $targetBasePath . '/' . $relativePath;
        $targetDir = dirname($target);

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new \RuntimeException("Failed to create screenshots directory: $targetDir");
        }

        if (file_exists($target) && !unlink($target)) {
            throw new \RuntimeException("Failed to replace screenshot: $target");
        }

        if (!rename($file, $target)) {
            throw new \RuntimeException("Failed to move screenshot into place: $target");
        }

        return $relativePath;
    }

    private function restoreFilesFromDirectory(string $sourceBasePath, string $targetBasePath): void
    {
        foreach ($this->findAllFiles($sourceBasePath) as $file) {
            try {
                $this->moveFilePreservingRelativePath($file, $sourceBasePath, $targetBasePath);
            } catch (\RuntimeException) {
            }
        }
    }

    /**
     * @return list<string>
     */
    private function findAllFiles(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $realPath = $file->getRealPath();
            if (is_string($realPath)) {
                $files[] = $realPath;
            }
        }

        return $files;
    }

    private function removeEmptyDirectories(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isDir()) {
                continue;
            }

            @rmdir($file->getPathname());
        }
    }

    private function removeDirectoryTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                @rmdir($file->getPathname());
                continue;
            }

            @unlink($file->getPathname());
        }

        @rmdir($path);
    }

    private function getRelativePath(string $basePath, string $file): string
    {
        $relative = substr($file, strlen(rtrim($basePath, '/')) + 1);
        return $relative !== '' ? $relative : basename($file);
    }

    /**
     * Get video duration using ffprobe
     */
    private function getVideoDuration(string $file, string $ffprobePath): ?float
    {
        $cmd = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($ffprobePath),
            escapeshellarg($file)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && $output !== [] && is_numeric(trim($output[0]))) {
            return (float)trim($output[0]);
        }

        return null;
    }

    /**
     * Check if ffmpeg is available
     */
    private function checkFfmpegAvailable(string $ffmpegPath): bool
    {
        if ($ffmpegPath !== 'ffmpeg') {
            return is_file($ffmpegPath) && is_executable($ffmpegPath);
        }

        // Try common paths
        $paths = [
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/bin/ffmpeg',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return true;
            }
        }

        // Try exec to check if it's in PATH
        exec(escapeshellarg($ffmpegPath) . ' -version 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Get image dimensions
     */
    private function getImageDimensions(string $file): string
    {
        $imageInfo = @getimagesize($file);
        if ($imageInfo !== false) {
            return "{$imageInfo[0]}x{$imageInfo[1]}";
        }

        return 'Unknown';
    }
}
