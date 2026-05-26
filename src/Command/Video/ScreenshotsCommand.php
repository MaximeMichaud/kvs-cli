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
            default => $this->listScreenshots($input, null),
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
        if ($videoId === null) {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:screenshots list <video_id>');
            return self::FAILURE;
        }

        $screenshotsBasePath = $this->config->getVideoScreenshotsPath();
        if ($screenshotsBasePath === '') {
            $this->io()->error('Screenshots path not configured');
            return self::FAILURE;
        }

        $screenshotsPath = $this->getVideoContentDir($screenshotsBasePath, $videoId);

        if (!is_dir($screenshotsPath)) {
            $this->io()->warning("Screenshots directory not found: $screenshotsPath");
            $this->io()->note("The video might not have screenshots generated yet.");
            return self::SUCCESS;
        }

        // Scan for screenshot files (common extensions)
        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        $files = [];

        $files = $this->findImageFiles($screenshotsPath, $extensions);

        if ($files === []) {
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

    private function generateScreenshots(InputInterface $input, ?string $videoId): int
    {
        if ($videoId === null) {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:screenshots generate <video_id>');
            return self::FAILURE;
        }

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
            return self::FAILURE;
        }

        $videoSourcesPath = $this->config->getVideoSourcesPath();
        $screenshotsBasePath = $this->config->getVideoScreenshotsPath();
        if ($videoSourcesPath === '' || $screenshotsBasePath === '') {
            $this->io()->error('Content paths not configured');
            return self::FAILURE;
        }

        $videoPath = $this->getVideoContentDir($videoSourcesPath, $videoId);
        $screenshotsPath = $this->getVideoContentDir($screenshotsBasePath, $videoId);

        // Find video source file
        $videoFile = $this->findVideoFile($videoPath);
        if ($videoFile === null) {
            $this->io()->error("No video file found in: $videoPath");
            $this->io()->note("Make sure video files exist before generating screenshots.");
            return self::FAILURE;
        }

        // Create screenshots directory if it doesn't exist
        if (!is_dir($screenshotsPath)) {
            if (!mkdir($screenshotsPath, 0755, true)) {
                $this->io()->error("Failed to create screenshots directory: $screenshotsPath");
                return self::FAILURE;
            }
            $this->io()->text("Created screenshots directory: $screenshotsPath");
        }

        // Get video duration
        $duration = $this->getVideoDuration($videoFile, $ffprobePath);
        if ($duration === null) {
            $this->io()->error("Failed to get video duration for: $videoFile");
            return self::FAILURE;
        }

        $count = $this->getIntOptionOrDefault($input, 'count', 10);
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

    private function regenerateScreenshots(InputInterface $input, ?string $videoId): int
    {
        if ($videoId === null) {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:screenshots regenerate <video_id>');
            return self::FAILURE;
        }

        $screenshotsBasePath = $this->config->getVideoScreenshotsPath();
        if ($screenshotsBasePath === '') {
            $this->io()->error('Screenshots path not configured');
            return self::FAILURE;
        }

        $screenshotsPath = $this->getVideoContentDir($screenshotsBasePath, $videoId);

        // Delete existing screenshots
        if (is_dir($screenshotsPath)) {
            $this->io()->text("Deleting existing screenshots...");

            $extensions = ['jpg', 'jpeg', 'png', 'webp'];
            $deleted = 0;

            $files = $this->findImageFiles($screenshotsPath, $extensions);
            foreach ($files as $file) {
                if (unlink($file)) {
                    $deleted++;
                }
            }

            $this->io()->text("Deleted $deleted existing screenshots");
            $this->io()->newLine();
        }

        // Generate new screenshots
        return $this->generateScreenshots($input, $videoId);
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
