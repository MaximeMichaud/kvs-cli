<?php

namespace KVS\CLI\Command\Video;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
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
  This command scans the KVS video storage directory for actual video files.
  Requires read access to contents/videos/ directory or the local video storage server path.
HELP
            );
    }

    protected function execute(InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');

        return match ($action) {
            'list' => $this->listFormats($input),
            'check' => $this->checkFormats($input),
            'available' => $this->showAvailableFormats($input),
            default => $this->listFormats($input),
        };
    }

    private function listFormats(InputInterface $input): int
    {
        $videoId = $this->getStringArgument($input, 'video_id');

        if ($videoId === null) {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:formats list <video_id>');
            return self::FAILURE;
        }

        $videoPath = $this->getVideoStorageDir($videoId);
        if ($videoPath === null) {
            $this->io()->error('Video storage path not configured');
            return self::FAILURE;
        }

        if (!is_dir($videoPath)) {
            $this->io()->error("Video directory not found: $videoPath");
            $this->io()->note("The video might not exist or formats haven't been generated yet.");
            return self::FAILURE;
        }

        // Scan for video files (common extensions)
        $extensions = ['mp4', 'webm', 'mkv', 'avi', 'flv', 'm4v'];
        $files = [];

        foreach ($extensions as $ext) {
            $matches = glob("$videoPath/*.$ext");
            if (is_array($matches) && $matches !== []) {
                $files = array_merge($files, $matches);
            }
        }

        if ($files === []) {
            $this->io()->warning('No video files found in directory');
            $this->io()->text("Directory: $videoPath");
            return self::SUCCESS;
        }

        $configuredFormats = $this->getFormatsFromDatabase(true);
        $formatTitlesByPostfix = [];
        foreach ($configuredFormats as $configuredFormat) {
            $postfix = $configuredFormat['postfix'] ?? null;
            $title = $configuredFormat['title'] ?? null;
            if (is_string($postfix) && is_string($title)) {
                $formatTitlesByPostfix[$postfix] = $title;
            }
        }

        $formats = [];
        foreach ($files as $file) {
            $filename = basename($file);
            $filesize = filesize($file);
            if ($filesize === false) {
                $filesize = 0;
            }
            $postfix = $this->getFormatPostfixFromFilename($videoId, $filename);
            $format = $postfix !== null && isset($formatTitlesByPostfix[$postfix])
                ? $formatTitlesByPostfix[$postfix]
                : ($postfix ?? pathinfo($filename, PATHINFO_FILENAME));

            // Try to get dimensions with ffprobe
            $dimensions = $this->getVideoDimensions($file);

            $formats[] = [
                'format' => $format,
                'postfix' => $postfix,
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
        $formatter->display($formats, $this->io());

        return self::SUCCESS;
    }

    private function checkFormats(InputInterface $input): int
    {
        $videoId = $this->getStringArgument($input, 'video_id');
        $outputFormat = $this->getStringOptionOrDefault($input, 'format', 'table');

        if ($videoId === null) {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:formats check <video_id>');
            return self::FAILURE;
        }

        $videoPath = $this->getVideoStorageDir($videoId);
        if ($videoPath === null) {
            $this->io()->error('Video storage path not configured');
            return self::FAILURE;
        }

        if (!is_dir($videoPath)) {
            $this->io()->error("Video directory not found for video ID: $videoId");
            return self::FAILURE;
        }

        if ($outputFormat === 'table') {
            $this->io()->title("Format Status for Video $videoId");
        }

        // Get configured formats from database
        $configuredFormats = $this->getFormatsFromDatabase();

        if ($configuredFormats === []) {
            $this->io()->warning('No formats configured in database');
            $this->io()->text('Cannot check formats without KVS format configuration.');
            $this->io()->text('Use "kvs video:formats list <video_id>" to see actual files.');
            return self::FAILURE;
        }

        $available = [];
        $missing = [];
        $formatRows = [];

        // Check each configured format
        foreach ($configuredFormats as $format) {
            $formatName = isset($format['title']) && is_string($format['title']) ? $format['title'] : '';
            $postfix = isset($format['postfix']) && is_string($format['postfix']) ? $format['postfix'] : '';

            // KVS stores converted video files as {video_id}{postfix}, e.g. 123_720p.mp4.
            $filename = $videoId . $postfix;
            $fullPath = "$videoPath/$filename";

            if (file_exists($fullPath)) {
                $filesize = filesize($fullPath);
                if ($filesize === false) {
                    $filesize = 0;
                }
                $size = format_bytes($filesize);
                $dimensions = $this->getVideoDimensions($fullPath);
                $available[] = sprintf('%s (%s, %s, %s)', $formatName, $postfix, $size, $dimensions);
                $formatRows[] = [
                    'format' => $formatName,
                    'postfix' => $postfix,
                    'status' => 'available',
                    'file' => $filename,
                    'size' => $size,
                    'dimensions' => $dimensions,
                    'path' => $fullPath,
                ];
            } else {
                $missing[] = sprintf('%s (%s)', $formatName, $postfix);
                $formatRows[] = [
                    'format' => $formatName,
                    'postfix' => $postfix,
                    'status' => 'missing',
                    'file' => $filename,
                    'size' => '',
                    'dimensions' => '',
                    'path' => $fullPath,
                ];
            }
        }

        if ($outputFormat !== 'table') {
            $formatter = new Formatter($input->getOptions(), ['format', 'postfix', 'status', 'file', 'size', 'dimensions']);
            $formatter->display($formatRows, $this->io());
            return self::SUCCESS;
        }

        if ($available !== []) {
            $this->io()->section('✓ Available Formats');
            $this->io()->listing($available);
        }

        if ($missing !== []) {
            $this->io()->section('✗ Missing Formats');
            $this->io()->listing($missing);
            $this->io()->note('These formats can be generated via video conversion process');
        }

        if ($available === [] && $missing === []) {
            $this->io()->warning('No standard formats found in directory');
        }

        return self::SUCCESS;
    }

    private function showAvailableFormats(InputInterface $input): int
    {
        $format = $this->getStringOption($input, 'format') ?? 'table';
        if ($format === 'table') {
            $this->io()->title('Available Format Configurations');
        }

        // Try to read from KVS database configuration
        $formats = $this->getFormatsFromDatabase();

        if ($formats === []) {
            // Fallback: scan filesystem to see what formats actually exist
            $this->io()->warning('No formats configured in database');
            $this->io()->text('Reading format configuration from KVS database failed.');
            $this->io()->text('This might be a test environment without format configuration.');
            return self::FAILURE;
        }

        $defaultFields = ['format_id', 'title', 'postfix', 'status', 'group_id'];

        $formatter = new Formatter($input->getOptions(), $defaultFields);
        /** @var list<array<string, mixed>> $formats */
        $formatter->display($formats, $this->io());

        if ($format === 'table') {
            $this->io()->newLine();
            $this->io()->note('These formats are configured in KVS (table: ' . $this->table('formats_videos') . ')');
        }

        return self::SUCCESS;
    }

    /**
     * Get video formats from KVS database
     * @return list<array<string, mixed>>
     */
    private function getFormatsFromDatabase(bool $quiet = false): array
    {
        $db = $this->getDatabaseConnection($quiet);
        if ($db === null) {
            return [];
        }

        try {
            $stmt = $db->query("
                SELECT
                    format_video_id as format_id,
                    title,
                    postfix,
                    CASE
                        WHEN status_id = " . StatusFormatter::FORMAT_DISABLED . " THEN 'Disabled'
                        WHEN status_id = " . StatusFormatter::FORMAT_REQUIRED . " THEN 'Required'
                        WHEN status_id = " . StatusFormatter::FORMAT_OPTIONAL . " THEN 'Optional'
                        WHEN status_id = " . StatusFormatter::FORMAT_DELETING . " THEN 'Deleting'
                        WHEN status_id = " . StatusFormatter::FORMAT_ERROR . " THEN 'Error'
                        WHEN status_id = " . StatusFormatter::FORMAT_CONDITIONAL . " THEN 'Conditional'
                        ELSE 'Unknown'
                    END as status,
                    format_video_group_id as group_id,
                    access_level_id
                FROM " . $this->table('formats_videos') . "
                ORDER BY format_video_group_id ASC, title ASC
            ");

            if ($stmt === false) {
                return [];
            }

            $result = $stmt->fetchAll();

            // PHPStan knows fetchAll returns array, but we need to ensure it's a list
            /** @var list<array<string, mixed>> $formats */
            $formats = [];
            foreach ($result as $row) {
                if (is_array($row)) {
                    /** @var array<string, mixed> $row */
                    $formats[] = $row;
                }
            }

            return $formats;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getVideoStorageDir(string $videoId): ?string
    {
        $basePath = $this->getVideoStorageBasePath($videoId);
        if ($basePath === null || $basePath === '') {
            return null;
        }

        return rtrim($basePath, '/') . '/' . $this->getDirById($videoId) . '/' . $videoId;
    }

    private function getVideoStorageBasePath(string $videoId): ?string
    {
        $db = $this->getDatabaseConnection(true);
        if ($db !== null) {
            $serverPath = $this->getLocalVideoServerPath($db, $videoId);
            if ($serverPath !== null) {
                return $serverPath;
            }
        }

        $videosPath = $this->config->getContentPath() . '/' . Constants::CONTENT_VIDEOS;
        if (is_dir($videosPath)) {
            return $videosPath;
        }

        $sourcesPath = $this->config->getVideoSourcesPath();
        if ($sourcesPath !== '') {
            return dirname($sourcesPath) . '/' . Constants::CONTENT_VIDEOS;
        }

        return null;
    }

    private function getLocalVideoServerPath(\PDO $db, string $videoId): ?string
    {
        try {
            $videoStmt = $db->prepare(
                'SELECT server_group_id FROM ' . $this->table('videos') . ' WHERE video_id = :video_id'
            );
            if ($videoStmt === false) {
                return null;
            }
            $videoStmt->execute(['video_id' => (int) $videoId]);
            $video = $videoStmt->fetch();
            if (!is_array($video)) {
                return null;
            }

            $serverGroupId = $video['server_group_id'] ?? 0;
            $serverGroupId = is_numeric($serverGroupId) ? (int) $serverGroupId : 0;

            $query = 'SELECT path FROM ' . $this->table('admin_servers')
                . ' WHERE content_type_id = 1 AND status_id = 1 AND is_remote = 0 AND path <> \'\'';
            $params = [];
            if ($serverGroupId > 0) {
                $query .= ' AND group_id = :group_id';
                $params['group_id'] = $serverGroupId;
            }
            $query .= ' ORDER BY server_id ASC LIMIT 1';

            $serverStmt = $db->prepare($query);
            if ($serverStmt === false) {
                return null;
            }
            $serverStmt->execute($params);
            $server = $serverStmt->fetch();
            if (!is_array($server)) {
                return null;
            }

            $path = $server['path'] ?? null;
            return is_string($path) && $path !== '' && is_dir($path) ? $path : null;
        } catch (\Exception) {
            return null;
        }
    }

    private function getDirById(string $id): int
    {
        return (int) floor((int) $id / 1000) * 1000;
    }

    private function getFormatPostfixFromFilename(string $videoId, string $filename): ?string
    {
        if (!str_starts_with($filename, $videoId)) {
            return null;
        }

        $postfix = substr($filename, strlen($videoId));
        return $postfix !== '' ? $postfix : null;
    }

    /**
     * Get video dimensions using ffprobe
     */
    private function getVideoDimensions(string $file): string
    {
        $ffprobe = $this->config->getFfprobePath();

        $cmd = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($ffprobe),
            escapeshellarg($file)
        );

        $outputResult = shell_exec($cmd);
        if ($outputResult === null || $outputResult === false) {
            return 'Unknown';
        }
        $output = trim($outputResult);
        if (str_contains($output, ',')) {
            [$width, $height] = explode(',', $output);
            return "{$width}x{$height}";
        }

        return 'Unknown';
    }
}
