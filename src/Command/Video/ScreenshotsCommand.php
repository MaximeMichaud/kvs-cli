<?php

namespace KVS\CLI\Command\Video;

use KVS\CLI\Command\BaseCommand;
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
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
    private const FILE_TYPE_MASK = 0170000;
    private const FILE_TYPE_DIRECTORY = 0040000;
    private const FILE_TYPE_REGULAR = 0100000;
    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml', 'count'];
    private const GENERATE_UNSUPPORTED_OPTIONS = ['fields', 'format', 'no-truncate'];
    private const LOGICAL_LIST_DEFAULT_FIELDS = ['index', 'filename', 'formats', 'dimensions'];
    private const LOGICAL_LIST_FIELDS = ['index', 'filename', 'formats', 'size', 'dimensions', 'path', 'is_main'];
    private const FILE_LIST_FIELDS = ['filename', 'size', 'dimensions', 'path'];

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
  --fields=FIELDS           List fields to display
  --format=FORMAT           List output format: table, csv, json, yaml, count

<fg=yellow>AVAILABLE LIST FIELDS:</>
  index, filename, formats, size, dimensions, path, is_main

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs screenshots list 123</>
  <fg=green>kvs screenshots generate 123 --count=20</>
  <fg=green>kvs screenshots regenerate 123</>
  <fg=green>kvs screenshots list 123 --format=json</>
  <fg=green>kvs video:screenshots list 123 --format=count</>

<fg=yellow>NOTE:</>
  list reports KVS overview screenshot metadata when available, then falls back
  to scanning overview screenshot files. It does not select timeline screenshots
  or posters.
  generate/regenerate write KVS source overview screenshots and require ffmpeg to be installed.
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

        $videoMetadata = $this->loadManageableVideoScreenshotMetadata($videoId);
        if ($videoMetadata === false) {
            return self::FAILURE;
        }
        if (is_array($videoMetadata)) {
            $screenshots = $this->buildKvsOverviewScreenshotRows(
                $videoId,
                $screenshotsPath,
                $videoMetadata['screen_amount'],
                $videoMetadata['screen_main']
            );

            return $this->displayFormattedRows(
                $input,
                $screenshots,
                self::LOGICAL_LIST_DEFAULT_FIELDS,
                self::LOGICAL_LIST_FIELDS
            );
        }

        $screenshots = $this->buildLogicalScreenshotRows($videoId, $screenshotsPath);
        if ($screenshots !== []) {
            return $this->displayFormattedRows(
                $input,
                $screenshots,
                self::LOGICAL_LIST_DEFAULT_FIELDS,
                self::LOGICAL_LIST_FIELDS
            );
        }

        if (!is_dir($screenshotsPath)) {
            if (!$this->ensureVideoIsManageableInKvs($videoId)) {
                return self::FAILURE;
            }

            if (!$this->isTableFormat($input)) {
                return $this->displayFormattedRows($input, [], self::FILE_LIST_FIELDS, self::FILE_LIST_FIELDS);
            }

            $this->io()->warning("Screenshots directory not found: $screenshotsPath");
            $this->io()->note("The video might not have screenshots generated yet.");
            return self::SUCCESS;
        }

        // Scan for screenshot files (common extensions)
        $files = [];

        $files = $this->findImageFiles($screenshotsPath, self::IMAGE_EXTENSIONS);

        if ($files === []) {
            if (!$this->ensureVideoIsManageableInKvs($videoId)) {
                return self::FAILURE;
            }

            if (!$this->isTableFormat($input)) {
                return $this->displayFormattedRows($input, [], self::FILE_LIST_FIELDS, self::FILE_LIST_FIELDS);
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

        return $this->displayFormattedRows($input, $screenshots, ['filename', 'size', 'dimensions'], self::FILE_LIST_FIELDS);
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
     * @return array{screen_amount: int, screen_main: int}|false|null false when DB proves the video is not manageable,
     *                                                             null when metadata cannot be loaded.
     */
    private function loadManageableVideoScreenshotMetadata(string $videoId): array|false|null
    {
        $db = $this->getDatabaseConnection(true);
        if ($db === null) {
            return null;
        }

        try {
            $stmt = $db->prepare(
                "SELECT status_id, screen_amount, screen_main FROM {$this->table('videos')} WHERE video_id = :id"
            );
            $stmt->bindValue('id', (int) $videoId, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($row)) {
            $this->io()->error("Video not found or screenshots are not manageable in KVS admin: $videoId");
            return false;
        }

        $statusId = $this->normalizeDatabaseInteger($row['status_id'] ?? null);
        if ($statusId === null || !in_array($statusId, [0, 1], true)) {
            $this->io()->error("Video not found or screenshots are not manageable in KVS admin: $videoId");
            return false;
        }

        return [
            'screen_amount' => max(0, $this->normalizeDatabaseInteger($row['screen_amount'] ?? null) ?? 0),
            'screen_main' => max(0, $this->normalizeDatabaseInteger($row['screen_main'] ?? null) ?? 0),
        ];
    }

    private function normalizeDatabaseInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
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
     * @return list<array<string, mixed>>
     */
    private function buildKvsOverviewScreenshotRows(
        string $videoId,
        string $screenshotsPath,
        int $screenAmount,
        int $screenMain
    ): array {
        $rows = [];
        $sourceScreenshotsPath = $this->getVideoContentDir(
            $this->config->getVideoSourcesPath(),
            $videoId
        ) . '/screenshots';

        for ($index = 1; $index <= $screenAmount; $index++) {
            $files = $this->findLogicalScreenshotFiles($sourceScreenshotsPath, $screenshotsPath, $index);
            $representative = $files[0] ?? null;
            $filesize = $representative !== null ? filesize($representative) : false;

            $rows[] = [
                'index' => $index,
                'filename' => $index . '.jpg',
                'formats' => count($files),
                'size' => $filesize !== false ? format_bytes($filesize) : '',
                'dimensions' => $representative !== null ? $this->getImageDimensions($representative) : '',
                'path' => $representative ?? '',
                'is_main' => $screenMain === $index ? 1 : 0,
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

        $count = $this->getPositiveIntOptionOrDefault($input, 'count', 10);
        if ($count === null) {
            return self::FAILURE;
        }

        $plan = $this->prepareScreenshotGeneration($videoId, $count);
        if ($plan === null) {
            return self::FAILURE;
        }

        $screenshotsPath = $plan['screenshots_path'];
        if (!$this->validateScreenshotMutationPath($plan['sources_path'], $screenshotsPath)) {
            return self::FAILURE;
        }

        $stagingPath = $this->createTemporarySiblingDirectory($screenshotsPath, 'generate');
        if ($stagingPath === null) {
            return self::FAILURE;
        }

        try {
            $result = $this->generateScreenshotsToDirectory($plan, $stagingPath);
            if ($result !== self::SUCCESS) {
                return $result;
            }

            if (!$this->validateScreenshotMutationPath($plan['sources_path'], $screenshotsPath)) {
                return self::FAILURE;
            }

            return $this->publishScreenshotsFromStaging($stagingPath, $screenshotsPath, false) !== null
                ? self::SUCCESS
                : self::FAILURE;
        } finally {
            $this->removeDirectoryTree($stagingPath);
        }
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

        $count = $this->getPositiveIntOptionOrDefault($input, 'count', 10);
        if ($count === null) {
            return self::FAILURE;
        }

        $plan = $this->prepareScreenshotGeneration($videoId, $count);
        if ($plan === null) {
            return self::FAILURE;
        }

        $screenshotsPath = $plan['screenshots_path'];
        if (!$this->validateScreenshotMutationPath($plan['sources_path'], $screenshotsPath)) {
            return self::FAILURE;
        }

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
            if (!$this->validateScreenshotMutationPath($plan['sources_path'], $screenshotsPath)) {
                return self::FAILURE;
            }

            $deleted = $this->publishScreenshotsFromStaging($stagingPath, $screenshotsPath, true);
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
     * @return array{
     *     ffmpeg_path: string,
     *     video_file: string,
     *     sources_path: string,
     *     screenshots_path: string,
     *     duration: float,
     *     count: int
     * }|null
     */
    private function prepareScreenshotGeneration(string $videoId, int $count): ?array
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
        if ($videoSourcesPath === '') {
            $this->io()->error('Content paths not configured');
            return null;
        }

        $videoPath = $this->getVideoContentDir($videoSourcesPath, $videoId);
        $screenshotsPath = $videoPath . '/screenshots';
        if (!$this->validateScreenshotMutationPath($videoSourcesPath, $screenshotsPath)) {
            return null;
        }

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

        return [
            'ffmpeg_path' => $ffmpegPath,
            'video_file' => $videoFile,
            'sources_path' => $videoSourcesPath,
            'screenshots_path' => $screenshotsPath,
            'duration' => $duration,
            'count' => $count,
        ];
    }

    /**
     * @param array{
     *     ffmpeg_path: string,
     *     video_file: string,
     *     sources_path: string,
     *     screenshots_path: string,
     *     duration: float,
     *     count: int
     * } $plan
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
            $filename = "$i.jpg";
            $outputFile = "$screenshotsPath/$filename";

            if ($this->getPathStat($outputFile) !== false) {
                $failed++;
                $this->io()->text("  ✗ Refused pre-existing output $filename");
                continue;
            }

            $cmd = sprintf(
                '%s -ss %.2f -i %s -vframes 1 -q:v 2 %s -y 2>&1',
                escapeshellarg($ffmpegPath),
                $timestamp,
                escapeshellarg($videoFile),
                escapeshellarg($outputFile)
            );

            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);
            clearstatcache(true, $outputFile);
            $outputStat = $this->getPathStat($outputFile);
            $isRegularOutput = $this->isSafeRegularFileStat($outputStat, false);
            $hasOutputContent = is_array($outputStat)
                && $outputStat['size'] > 0;

            if ($returnCode === 0 && $isRegularOutput && $hasOutputContent) {
                $success++;
                $this->io()->text("  ✓ Generated $filename");
            } else {
                $nonRegularOutput = $outputStat !== false && !$isRegularOutput;
                if ($outputStat !== false && !$this->isDirectoryStat($outputStat)) {
                    @unlink($outputFile);
                }
                $failed++;
                $suffix = $nonRegularOutput ? ' (non-regular output)' : '';
                $this->io()->text("  ✗ Failed to generate $filename$suffix");
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

        $videoId = basename(rtrim($videoPath, '/'));
        foreach (["$videoId.tmp", "$videoId.tmp2"] as $filename) {
            $sourceFile = "$videoPath/$filename";
            if (is_file($sourceFile)) {
                return $sourceFile;
            }
        }

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
        $rootStat = $this->getPathStat($path);
        if (!$this->isDirectoryStat($rootStat)) {
            return [];
        }

        $allowed = array_flip(array_map('strtolower', $extensions));
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $filePath = $file->getPathname();
            $fileStat = $this->getPathStat($filePath);
            if (!$this->isRegularFileStat($fileStat)) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!isset($allowed[$extension])) {
                continue;
            }

            $files[] = $filePath;
        }

        return $files;
    }

    /** @phpstan-impure */
    private function validateScreenshotMutationPath(string $sourcesPath, string $screenshotsPath): bool
    {
        $root = rtrim($sourcesPath, '/');
        $prefix = $root . '/';
        if ($root === '' || !str_starts_with($screenshotsPath, $prefix)) {
            $this->io()->error('Screenshots path is outside the configured video sources directory');
            return false;
        }

        if (!is_dir($root)) {
            $this->io()->error("Video sources directory not found: $root");
            return false;
        }

        $relative = substr($screenshotsPath, strlen($prefix));
        $current = $root;
        foreach (explode('/', $relative) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                $this->io()->error('Screenshots path contains an unsafe component');
                return false;
            }

            $current .= '/' . $component;
            $stat = $this->getPathStat($current);
            if ($stat === false) {
                continue;
            }
            if (is_link($current)) {
                $this->io()->error("Screenshots path contains a symbolic link: $current");
                return false;
            }
            if (!$this->isDirectoryStat($stat)) {
                $this->io()->error("Screenshots path component is not a directory: $current");
                return false;
            }
        }

        return true;
    }

    private function ensureDirectoryExists(string $path): bool
    {
        $stat = $this->getPathStat($path);
        if ($stat !== false) {
            if ($this->isDirectoryStat($stat) && !is_link($path)) {
                return true;
            }

            $kind = is_link($path) ? 'symbolic link' : 'non-directory entry';
            $this->io()->error("Refusing screenshots directory $kind: $path");
            return false;
        }

        $parentStat = $this->getPathStat(dirname($path));
        if (!$this->isDirectoryStat($parentStat) || is_link(dirname($path)) || !@mkdir($path, 0755)) {
            $this->io()->error("Failed to create screenshots directory: $path");
            return false;
        }

        $createdStat = $this->getPathStat($path);
        if (!$this->isDirectoryStat($createdStat) || is_link($path)) {
            $this->io()->error("Created screenshots path is unsafe: $path");
            return false;
        }

        $this->io()->text("Created screenshots directory: $path");
        return true;
    }

    private function createTemporarySiblingDirectory(string $path, string $purpose): ?string
    {
        $parent = dirname($path);
        $parentStat = $this->getPathStat($parent);
        if (!$this->isDirectoryStat($parentStat) || is_link($parent)) {
            $this->io()->error("Failed to create temporary screenshots parent directory: $parent");
            return null;
        }

        for ($i = 0; $i < 10; $i++) {
            try {
                $suffix = bin2hex(random_bytes(12));
            } catch (\Throwable) {
                $this->io()->error('Failed to generate a private screenshots directory name');
                return null;
            }
            $candidate = $parent . '/.' . basename($path) . '-' . $purpose . '-' . $suffix;
            if (
                @mkdir($candidate, 0700)
                && $this->isDirectoryStat($this->getPathStat($candidate))
                && !is_link($candidate)
            ) {
                return $candidate;
            }
        }

        $this->io()->error("Failed to create temporary screenshots directory below: $parent");
        return null;
    }

    private function publishScreenshotsFromStaging(
        string $stagingPath,
        string $screenshotsPath,
        bool $replaceAll
    ): ?int {
        $backupPath = null;
        $existingMoved = [];
        $generatedMoved = [];

        try {
            $generatedFiles = $this->inspectScreenshotTree($stagingPath, true);
            if ($generatedFiles === []) {
                throw new \RuntimeException('No safe generated screenshots were found for publication');
            }

            $existingFiles = $this->inspectScreenshotTree($screenshotsPath, false);
            if (!$replaceAll) {
                $generatedRelativePaths = [];
                foreach ($generatedFiles as $file) {
                    $generatedRelativePaths[$this->getRelativePath($stagingPath, $file)] = true;
                }
                $existingFiles = array_values(array_filter(
                    $existingFiles,
                    fn (string $file): bool => isset(
                        $generatedRelativePaths[$this->getRelativePath($screenshotsPath, $file)]
                    )
                ));
            }

            if ($existingFiles !== []) {
                $backupPath = $this->createTemporarySiblingDirectory($screenshotsPath, 'backup');
                if ($backupPath === null) {
                    return null;
                }

                foreach ($existingFiles as $file) {
                    $existingMoved[] = $this->moveFilePreservingRelativePath(
                        $file,
                        $screenshotsPath,
                        $backupPath
                    );
                }

                $this->removeEmptyDirectories($screenshotsPath);
            }

            if (!$this->ensureDirectoryExists($screenshotsPath)) {
                throw new \RuntimeException("Failed to prepare screenshots directory: $screenshotsPath");
            }

            foreach ($generatedFiles as $file) {
                $generatedMoved[] = $this->moveFilePreservingRelativePath($file, $stagingPath, $screenshotsPath);
            }

            if ($backupPath !== null) {
                if (!$this->removeDirectoryTree($backupPath)) {
                    $this->io()->warning("Could not remove screenshots backup directory: $backupPath");
                }
            }

            return count($existingFiles);
        } catch (\RuntimeException $exception) {
            $rollbackOk = true;
            foreach ($generatedMoved as $relativePath) {
                $publishedPath = $screenshotsPath . '/' . $relativePath;
                $publishedStat = $this->getPathStat($publishedPath);
                if (
                    $publishedStat !== false
                    && (!$this->isRegularFileStat($publishedStat) || !@unlink($publishedPath))
                ) {
                    $rollbackOk = false;
                }
            }

            if ($backupPath !== null) {
                $restored = $this->restoreFilesFromDirectory($backupPath, $screenshotsPath, $existingMoved);
                $rollbackOk = $restored && $rollbackOk;
                if ($restored) {
                    $this->removeDirectoryTree($backupPath);
                } else {
                    $this->io()->error("Screenshot rollback is incomplete; backup retained at: $backupPath");
                }
            }

            $this->io()->error($exception->getMessage());
            if (!$rollbackOk) {
                $this->io()->error('Existing screenshots could not be restored completely');
            }
            return null;
        }
    }

    private function moveFilePreservingRelativePath(
        string $file,
        string $sourceBasePath,
        string $targetBasePath
    ): string {
        $sourceStat = $this->getPathStat($file);
        if (!$this->isSafeRegularFileStat($sourceStat, false)) {
            throw new \RuntimeException("Refusing non-regular screenshot source: $file");
        }

        $relativePath = $this->getRelativePath($sourceBasePath, $file);
        $target = $targetBasePath . '/' . $relativePath;
        $targetDir = dirname($target);

        if (!$this->ensureSafeRelativeDirectory($targetBasePath, dirname($relativePath))) {
            throw new \RuntimeException("Failed to create screenshots directory: $targetDir");
        }

        $targetStat = $this->getPathStat($target);
        if ($targetStat !== false) {
            if ($this->isDirectoryStat($targetStat) || !@unlink($target)) {
                throw new \RuntimeException("Failed to replace screenshot: $target");
            }
        }

        if (!@rename($file, $target)) {
            throw new \RuntimeException("Failed to move screenshot into place: $target");
        }

        return $relativePath;
    }

    /**
     * @param list<string> $relativePaths
     */
    private function restoreFilesFromDirectory(
        string $sourceBasePath,
        string $targetBasePath,
        array $relativePaths
    ): bool {
        $success = true;
        foreach ($relativePaths as $relativePath) {
            $file = $sourceBasePath . '/' . $relativePath;
            try {
                $this->moveFilePreservingRelativePath($file, $sourceBasePath, $targetBasePath);
            } catch (\RuntimeException $exception) {
                $this->io()->error($exception->getMessage());
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @return list<string>
     */
    private function inspectScreenshotTree(string $path, bool $imagesOnly): array
    {
        $rootStat = $this->getPathStat($path);
        if ($rootStat === false) {
            return [];
        }
        if (is_link($path)) {
            throw new \RuntimeException("Refusing screenshots symbolic link: $path");
        }
        if (!$this->isDirectoryStat($rootStat)) {
            throw new \RuntimeException("Refusing non-directory screenshots path: $path");
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $filePath = $file->getPathname();
            $stat = $this->getPathStat($filePath);
            if ($stat === false) {
                throw new \RuntimeException("Screenshot entry disappeared during validation: $filePath");
            }
            if ($this->isDirectoryStat($stat) && !is_link($filePath)) {
                continue;
            }
            if (!$this->isSafeRegularFileStat($stat, false)) {
                $kind = is_link($filePath) ? 'symbolic link' : 'non-regular entry';
                throw new \RuntimeException("Refusing screenshots $kind: $filePath");
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                if ($imagesOnly) {
                    throw new \RuntimeException("Refusing unexpected generated screenshot entry: $filePath");
                }
                continue;
            }

            $files[] = $filePath;
        }

        return $files;
    }

    private function ensureSafeRelativeDirectory(string $basePath, string $relativeDirectory): bool
    {
        if (!$this->ensureDirectoryExists($basePath)) {
            return false;
        }
        if ($relativeDirectory === '.' || $relativeDirectory === '') {
            return true;
        }

        $current = rtrim($basePath, '/');
        foreach (explode('/', $relativeDirectory) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                return false;
            }
            $current .= '/' . $component;
            $stat = $this->getPathStat($current);
            if ($stat === false) {
                if (!@mkdir($current, 0755)) {
                    return false;
                }
                $stat = $this->getPathStat($current);
            }
            if (!$this->isDirectoryStat($stat) || is_link($current)) {
                return false;
            }
        }

        return true;
    }

    private function removeEmptyDirectories(string $path): void
    {
        if (!$this->isDirectoryStat($this->getPathStat($path)) || is_link($path)) {
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

            $entryPath = $file->getPathname();
            if ($this->isDirectoryStat($this->getPathStat($entryPath)) && !is_link($entryPath)) {
                @rmdir($entryPath);
            }
        }
    }

    private function removeDirectoryTree(string $path): bool
    {
        $rootStat = $this->getPathStat($path);
        if ($rootStat === false) {
            return true;
        }
        if (!$this->isDirectoryStat($rootStat) || is_link($path)) {
            return @unlink($path);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $entryPath = $file->getPathname();
            $entryStat = $this->getPathStat($entryPath);
            if ($entryStat === false) {
                continue;
            }
            if ($this->isDirectoryStat($entryStat) && !is_link($entryPath)) {
                @rmdir($entryPath);
            } else {
                @unlink($entryPath);
            }
        }

        return @rmdir($path) || $this->getPathStat($path) === false;
    }

    private function getRelativePath(string $basePath, string $file): string
    {
        $prefix = rtrim($basePath, '/') . '/';
        if (!str_starts_with($file, $prefix)) {
            throw new \RuntimeException("Screenshot path is outside its expected directory: $file");
        }

        $relative = substr($file, strlen($prefix));
        $components = explode('/', $relative);
        if (
            $relative === ''
            || in_array('', $components, true)
            || in_array('.', $components, true)
            || in_array('..', $components, true)
        ) {
            throw new \RuntimeException("Screenshot has an unsafe relative path: $file");
        }

        return $relative;
    }

    /**
     * @return array{mode: int, nlink: int, size: int}|false
     * @phpstan-impure
     */
    private function getPathStat(string $path): array|false
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return false;
        }

        return ['mode' => $stat['mode'], 'nlink' => $stat['nlink'], 'size' => $stat['size']];
    }

    /**
     * @param array{mode: int, nlink?: int, size?: int}|false $stat
     */
    private function isRegularFileStat(array|false $stat): bool
    {
        return is_array($stat)
            && (($stat['mode'] & self::FILE_TYPE_MASK) === self::FILE_TYPE_REGULAR);
    }

    /**
     * @param array{mode: int, nlink?: int, size?: int}|false $stat
     */
    private function isSafeRegularFileStat(array|false $stat, bool $requireContent = true): bool
    {
        return $this->isRegularFileStat($stat)
            && isset($stat['nlink'], $stat['size'])
            && $stat['nlink'] === 1
            && (!$requireContent || $stat['size'] > 0);
    }

    /**
     * @param array{mode: int, nlink?: int, size?: int}|false $stat
     */
    private function isDirectoryStat(array|false $stat): bool
    {
        return is_array($stat)
            && (($stat['mode'] & self::FILE_TYPE_MASK) === self::FILE_TYPE_DIRECTORY);
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
