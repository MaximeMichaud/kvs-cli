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
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Screenshot type (timeline|poster)', 'timeline')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields')
            ->setHelp(<<<'HELP'
Manage video screenshots (thumbnails).

<fg=yellow>ACTIONS:</>
  list <video_id>           List existing screenshots for a video
  generate <video_id>       Generate screenshots for a video
  regenerate <video_id>     Regenerate screenshots (delete + generate)

<fg=yellow>OPTIONS:</>
  --count=N                 Number of screenshots to generate (default: 10)
  --type=timeline|poster    Screenshot type (default: timeline)

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
        $action = $input->getArgument('action');

        return match ($action) {
            'list' => $this->listScreenshots($input),
            'generate' => $this->generateScreenshots($input),
            'regenerate' => $this->regenerateScreenshots($input),
            default => $this->listScreenshots($input),
        };
    }

    private function listScreenshots(InputInterface $input): int
    {
        $videoId = $input->getArgument('video_id');

        if ($videoId === null || $videoId === '') {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:screenshots list <video_id>');
            return self::FAILURE;
        }

        $screenshotsBasePath = $this->config->getVideoScreenshotsPath();
        if ($screenshotsBasePath === '') {
            $this->io()->error('Screenshots path not configured');
            return self::FAILURE;
        }

        $screenshotsPath = "$screenshotsBasePath/$videoId";

        if (!is_dir($screenshotsPath)) {
            $this->io()->warning("Screenshots directory not found: $screenshotsPath");
            $this->io()->note("The video might not have screenshots generated yet.");
            return self::SUCCESS;
        }

        // Scan for screenshot files (common extensions)
        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        $files = [];

        foreach ($extensions as $ext) {
            $matches = glob("$screenshotsPath/*.$ext");
            if ($matches !== false && $matches !== []) {
                $files = array_merge($files, $matches);
            }
        }

        if ($files === []) {
            $this->io()->warning('No screenshot files found in directory');
            $this->io()->text("Directory: $screenshotsPath");
            return self::SUCCESS;
        }

        $screenshots = [];
        foreach ($files as $file) {
            $filename = basename($file);
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

    private function generateScreenshots(InputInterface $input): int
    {
        $videoId = $input->getArgument('video_id');

        if ($videoId === null || $videoId === '') {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:screenshots generate <video_id>');
            return self::FAILURE;
        }

        // Check if ffmpeg is available
        if (!$this->checkFfmpegAvailable()) {
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

        $videoPath = "$videoSourcesPath/$videoId";
        $screenshotsPath = "$screenshotsBasePath/$videoId";

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
        $duration = $this->getVideoDuration($videoFile);
        if ($duration === null) {
            $this->io()->error("Failed to get video duration for: $videoFile");
            return self::FAILURE;
        }

        $count = (int)$input->getOption('count');
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
                'ffmpeg -ss %.2f -i %s -vframes 1 -q:v 2 %s -y 2>&1',
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

    private function regenerateScreenshots(InputInterface $input): int
    {
        $videoId = $input->getArgument('video_id');

        if ($videoId === null || $videoId === '') {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:screenshots regenerate <video_id>');
            return self::FAILURE;
        }

        $screenshotsBasePath = $this->config->getVideoScreenshotsPath();
        if ($screenshotsBasePath === '') {
            $this->io()->error('Screenshots path not configured');
            return self::FAILURE;
        }

        $screenshotsPath = "$screenshotsBasePath/$videoId";

        // Delete existing screenshots
        if (is_dir($screenshotsPath)) {
            $this->io()->text("Deleting existing screenshots...");

            $extensions = ['jpg', 'jpeg', 'png', 'webp'];
            $deleted = 0;

            foreach ($extensions as $ext) {
                $files = glob("$screenshotsPath/*.$ext");
                if ($files !== false && $files !== []) {
                    foreach ($files as $file) {
                        if (unlink($file)) {
                            $deleted++;
                        }
                    }
                }
            }

            $this->io()->text("Deleted $deleted existing screenshots");
            $this->io()->newLine();
        }

        // Generate new screenshots
        return $this->generateScreenshots($input);
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

    /**
     * Get video duration using ffprobe
     */
    private function getVideoDuration(string $file): ?float
    {
        $cmd = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
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
    private function checkFfmpegAvailable(): bool
    {
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
        exec('ffmpeg -version 2>&1', $output, $returnCode);
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
