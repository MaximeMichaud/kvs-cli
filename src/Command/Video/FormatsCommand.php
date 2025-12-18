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
    name: 'video:formats',
    description: 'Manage video formats',
    aliases: ['formats']
)]
class FormatsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|check|available)', 'list')
            ->addArgument('video_id', InputArgument::OPTIONAL, 'Video ID')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml', 'table')
            ->setHelp(<<<'HELP'
Manage video formats and check format availability.

<fg=yellow>ACTIONS:</>
  list <video_id>     List available formats for a video
  check <video_id>    Check which formats exist/missing
  available           Show all configured format options

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs video:formats list 123</>
  <fg=green>kvs video:formats check 123</>
  <fg=green>kvs video:formats available</>
  <fg=green>kvs video:formats list 123 --format=json</>

<fg=yellow>NOTE:</>
  This command scans the content directory for actual video files.
  Requires read access to content/videos/ directory.
HELP
            );
    }

    protected function execute(InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        return match ($action) {
            'list' => $this->listFormats($input),
            'check' => $this->checkFormats($input),
            'available' => $this->showAvailableFormats($input),
            default => $this->listFormats($input),
        };
    }

    private function listFormats(InputInterface $input): int
    {
        $videoId = $input->getArgument('video_id');

        if (!$videoId) {
            $this->io->error('Video ID is required');
            $this->io->text('Usage: kvs video:formats list <video_id>');
            return self::FAILURE;
        }

        $contentPath = $this->config->getContentPath();
        if (!$contentPath) {
            $this->io->error('Content path not configured');
            return self::FAILURE;
        }

        $videoPath = "$contentPath/videos/$videoId";

        if (!is_dir($videoPath)) {
            $this->io->error("Video directory not found: $videoPath");
            $this->io->note("The video might not exist or formats haven't been generated yet.");
            return self::FAILURE;
        }

        // Scan for video files (common extensions)
        $extensions = ['mp4', 'webm', 'mkv', 'avi', 'flv', 'm4v'];
        $files = [];

        foreach ($extensions as $ext) {
            $matches = glob("$videoPath/*.$ext");
            if ($matches) {
                $files = array_merge($files, $matches);
            }
        }

        if (empty($files)) {
            $this->io->warning('No video files found in directory');
            $this->io->text("Directory: $videoPath");
            return self::SUCCESS;
        }

        $formats = [];
        foreach ($files as $file) {
            $filename = basename($file);
            $filesize = filesize($file);
            $format = pathinfo($filename, PATHINFO_FILENAME);

            // Try to get dimensions with ffprobe
            $dimensions = $this->getVideoDimensions($file);

            $formats[] = [
                'format' => $format,
                'file' => $filename,
                'size' => format_bytes($filesize),
                'dimensions' => $dimensions,
                'path' => $file,
            ];
        }

        // Sort by format name
        usort($formats, fn($a, $b) => strcmp($a['format'], $b['format']));

        // Default fields for display
        $defaultFields = ['format', 'file', 'size', 'dimensions'];

        $formatter = new Formatter($input->getOptions(), $defaultFields);
        $formatter->display($formats, $this->io);

        return self::SUCCESS;
    }

    private function checkFormats(InputInterface $input): int
    {
        $videoId = $input->getArgument('video_id');

        if (!$videoId) {
            $this->io->error('Video ID is required');
            $this->io->text('Usage: kvs video:formats check <video_id>');
            return self::FAILURE;
        }

        $contentPath = $this->config->getContentPath();
        if (!$contentPath) {
            $this->io->error('Content path not configured');
            return self::FAILURE;
        }

        $videoPath = "$contentPath/videos/$videoId";

        if (!is_dir($videoPath)) {
            $this->io->error("Video directory not found for video ID: $videoId");
            return self::FAILURE;
        }

        $this->io->title("Format Status for Video $videoId");

        // Get configured formats from database
        $configuredFormats = $this->getFormatsFromDatabase();

        if (empty($configuredFormats)) {
            $this->io->warning('No formats configured in database');
            $this->io->text('Cannot check formats without KVS format configuration.');
            $this->io->text('Use "kvs video:formats list <video_id>" to see actual files.');
            return self::FAILURE;
        }

        $available = [];
        $missing = [];

        // Check each configured format
        foreach ($configuredFormats as $format) {
            $formatName = $format['title'];
            $postfix = $format['postfix']; // e.g. ".mp4", ".webm"

            // Build expected filename: formatName + postfix
            // e.g. "720p" + ".mp4" = "720p.mp4"
            $filename = $formatName . $postfix;
            $fullPath = "$videoPath/$filename";

            if (file_exists($fullPath)) {
                $size = format_bytes(filesize($fullPath));
                $dimensions = $this->getVideoDimensions($fullPath);
                $available[] = sprintf('%s (%s, %s, %s)', $formatName, $postfix, $size, $dimensions);
            } else {
                $missing[] = sprintf('%s (%s)', $formatName, $postfix);
            }
        }

        if (!empty($available)) {
            $this->io->section('✓ Available Formats');
            $this->io->listing($available);
        }

        if (!empty($missing)) {
            $this->io->section('✗ Missing Formats');
            $this->io->listing($missing);
            $this->io->note('These formats can be generated via video conversion process');
        }

        if (empty($available) && empty($missing)) {
            $this->io->warning('No standard formats found in directory');
        }

        return self::SUCCESS;
    }

    private function showAvailableFormats(InputInterface $input): int
    {
        $this->io->title('Available Format Configurations');

        // Try to read from KVS database configuration
        $formats = $this->getFormatsFromDatabase();

        if (empty($formats)) {
            // Fallback: scan filesystem to see what formats actually exist
            $this->io->warning('No formats configured in database');
            $this->io->text('Reading format configuration from KVS database failed.');
            $this->io->text('This might be a test environment without format configuration.');
            return self::FAILURE;
        }

        $defaultFields = ['format_id', 'title', 'postfix', 'status', 'group_id'];

        $formatter = new Formatter($input->getOptions(), $defaultFields);
        $formatter->display($formats, $this->io);

        $this->io->newLine();
        $this->io->note('These formats are configured in KVS (table: ktvs_formats_videos)');

        return self::SUCCESS;
    }

    /**
     * Get video formats from KVS database
     */
    private function getFormatsFromDatabase(): array
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            return [];
        }

        try {
            // Check if table exists
            $stmt = $db->query("SHOW TABLES LIKE '" . $this->config->getTablePrefix() . "formats_videos'");
            if (!$stmt || !$stmt->fetch()) {
                return [];
            }

            // Read formats from database
            $stmt = $db->query("
                SELECT
                    format_video_id as format_id,
                    title,
                    postfix,
                    CASE
                        WHEN status_id = 0 THEN 'Disabled'
                        WHEN status_id = 1 THEN 'Active'
                        WHEN status_id = 2 THEN 'Processing'
                        ELSE 'Unknown'
                    END as status,
                    format_video_group_id as group_id,
                    access_level_id
                FROM " . $this->table('formats_videos') . "
                ORDER BY format_video_group_id ASC, title ASC
            ");

            $formats = $stmt->fetchAll();

            return $formats ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get video dimensions using ffprobe
     */
    private function getVideoDimensions(string $file): string
    {
        // Check if ffprobe is available
        $ffprobe = shell_exec('which ffprobe 2>/dev/null');
        if (empty($ffprobe)) {
            return 'Unknown';
        }

        $cmd = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($file)
        );

        $output = shell_exec($cmd);
        if ($output && strpos($output, ',') !== false) {
            [$width, $height] = explode(',', trim($output));
            return "{$width}x{$height}";
        }

        return 'Unknown';
    }
}
