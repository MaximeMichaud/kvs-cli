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
    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml'];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|check|available)', 'available')
            ->addArgument('video_id', InputArgument::OPTIONAL, 'Video ID')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml', 'table')
            ->setHelp(<<<'HELP'
Manage video formats and check format availability.

<fg=yellow>ACTIONS:</>
  list <video_id>     List actual video files found on disk
  check <video_id>    Compare disk files against configured formats
  available           Show all configured format options from KVS

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
        $action = $this->getStringArgument($input, 'action') ?? 'available';
        $videoId = $this->getStringArgument($input, 'video_id');
        if ($videoId === null && ctype_digit($action)) {
            $input->setArgument('video_id', $action);
            $action = 'list';
        }

        return match ($action) {
            'list' => $this->listFormats($input),
            'check' => $this->checkFormats($input),
            'available' => $this->showAvailableFormats($input),
            default => $this->failUnknownAction(
                'formats',
                $action,
                ['available', 'list', 'check']
            ),
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

        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $videoId = $this->normalizeVideoId($videoId);
        if ($videoId === null) {
            return self::FAILURE;
        }

        $videoPaths = $this->getVideoStorageDirs($videoId);
        if ($videoPaths === []) {
            $this->io()->error('Video storage path not configured');
            return self::FAILURE;
        }

        $existingVideoPaths = $this->filterExistingDirs($videoPaths);
        if ($existingVideoPaths === []) {
            if (!$this->isTableFormat($input)) {
                $this->displayFormattedRows($input, [[
                    'video_id' => $videoId,
                    'path' => $videoPaths[0],
                    'exists' => false,
                    'message' => 'Video directory not found',
                ]], ['video_id', 'path', 'exists', 'message']);
                return self::FAILURE;
            }

            $this->io()->error("Video directory not found: {$videoPaths[0]}");
            $this->io()->note("The video might not exist or formats haven't been generated yet.");
            return self::FAILURE;
        }

        // Scan for video files (common extensions)
        $extensions = ['mp4', 'webm', 'mkv', 'avi', 'flv', 'm4v'];
        $files = [];

        foreach ($existingVideoPaths as $videoPath) {
            foreach ($extensions as $ext) {
                $matches = glob("$videoPath/*.$ext");
                if (is_array($matches) && $matches !== []) {
                    $files = array_merge($files, $matches);
                }
            }
        }

        if ($files === []) {
            if (!$this->isTableFormat($input)) {
                return $this->displayFormattedRows($input, [], ['format', 'file', 'size', 'dimensions']);
            }

            $this->io()->warning('No video files found in directory');
            $this->io()->text('Directory: ' . implode(', ', $existingVideoPaths));
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
        $outputFormat = $this->validateOutputFormat($input, self::OUTPUT_FORMATS);
        if ($outputFormat === null) {
            return self::FAILURE;
        }

        if ($videoId === null) {
            $this->io()->error('Video ID is required');
            $this->io()->text('Usage: kvs video:formats check <video_id>');
            return self::FAILURE;
        }

        $videoId = $this->normalizeVideoId($videoId);
        if ($videoId === null) {
            return self::FAILURE;
        }

        $videoPaths = $this->getVideoStorageDirs($videoId);
        if ($videoPaths === []) {
            $this->io()->error('Video storage path not configured');
            return self::FAILURE;
        }

        $existingVideoPaths = $this->filterExistingDirs($videoPaths);
        if ($existingVideoPaths === []) {
            if (!$this->isTableFormat($input)) {
                $this->displayFormattedRows($input, [[
                    'video_id' => $videoId,
                    'path' => $videoPaths[0],
                    'exists' => false,
                    'message' => 'Video directory not found',
                ]], ['video_id', 'path', 'exists', 'message']);
                return self::FAILURE;
            }

            $this->io()->error("Video directory not found for video ID: $videoId");
            $this->io()->note("The video might not exist or formats haven't been generated yet.");
            return self::FAILURE;
        }

        if ($outputFormat === 'table') {
            $this->io()->title("Format Status for Video $videoId");
        }

        // Get configured formats from database
        $configuredFormats = $this->getFormatsFromDatabase();

        if ($configuredFormats === []) {
            if ($outputFormat !== 'table') {
                $this->displayFormattedRows($input, [], ['format', 'postfix', 'status', 'file', 'size', 'dimensions']);
                return self::FAILURE;
            }

            $this->io()->warning('No formats configured in database');
            $this->io()->text('Cannot check formats without KVS format configuration.');
            $this->io()->text('Use "kvs video:formats list <video_id>" to see actual files.');
            return self::FAILURE;
        }

        $available = [];
        $missing = [];
        $missingRequired = [];
        $formatRows = [];

        // Check each configured format
        foreach ($configuredFormats as $format) {
            $formatName = isset($format['title']) && is_string($format['title']) ? $format['title'] : '';
            $postfix = isset($format['postfix']) && is_string($format['postfix']) ? $format['postfix'] : '';
            $statusId = isset($format['raw_status_id']) && is_numeric($format['raw_status_id'])
                ? (int) $format['raw_status_id']
                : (isset($format['status_id']) && is_numeric($format['status_id'])
                ? (int) $format['status_id']
                : StatusFormatter::FORMAT_DISABLED);

            if (!in_array($statusId, [StatusFormatter::FORMAT_REQUIRED, StatusFormatter::FORMAT_OPTIONAL], true)) {
                continue;
            }

            // KVS stores converted video files as {video_id}{postfix}, e.g. 123_720p.mp4.
            $filename = $videoId . $postfix;
            $fullPath = $this->findExistingFile($existingVideoPaths, $filename)
                ?? $videoPaths[0] . '/' . $filename;

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
                if ($statusId === StatusFormatter::FORMAT_REQUIRED) {
                    $missingRequired[] = sprintf('%s (%s)', $formatName, $postfix);
                }
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
            $formatter = new Formatter(
                $input->getOptions(),
                ['format', 'postfix', 'status', 'file', 'size', 'dimensions']
            );
            $formatter->display($formatRows, $this->io());
            return $missingRequired === [] ? self::SUCCESS : self::FAILURE;
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

        return $missingRequired === [] ? self::SUCCESS : self::FAILURE;
    }

    private function showAvailableFormats(InputInterface $input): int
    {
        $videoId = $this->getStringArgument($input, 'video_id');
        if ($videoId !== null && $videoId !== '') {
            $this->io()->error(
                'The available action does not support a video ID. Use list or check for video-specific files.'
            );
            return self::FAILURE;
        }

        $format = $this->validateOutputFormat($input, self::OUTPUT_FORMATS);
        if ($format === null) {
            return self::FAILURE;
        }

        if ($format === 'table') {
            $this->io()->title('Available Format Configurations');
        }

        // Try to read from KVS database configuration
        $formats = $this->getFormatsFromDatabase();

        if ($formats === []) {
            if ($format !== 'table') {
                $this->displayFormattedRows($input, [], ['format_id', 'title', 'postfix', 'status', 'group_id', 'access']);
                return self::FAILURE;
            }

            // Fallback: scan filesystem to see what formats actually exist
            $this->io()->warning('No formats configured in database');
            $this->io()->text('Reading format configuration from KVS database failed.');
            $this->io()->text('This might be a test environment without format configuration.');
            return self::FAILURE;
        }

        $formats = array_map(static function (array $format): array {
            $accessId = isset($format['access_level_id']) && is_numeric($format['access_level_id'])
                ? (int) $format['access_level_id']
                : 0;
            $format['access'] = StatusFormatter::formatAccessLevel($accessId, false);

            return $format;
        }, $formats);

        $defaultFields = ['format_id', 'title', 'postfix', 'status', 'group_id', 'access'];

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
                SELECT *
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
                    $formatId = isset($row['format_video_id']) && is_numeric($row['format_video_id'])
                        ? (int) $row['format_video_id'] : 0;
                    $groupId = isset($row['format_video_group_id']) && is_numeric($row['format_video_group_id'])
                        ? (int) $row['format_video_group_id'] : 0;
                    $statusId = isset($row['status_id']) && is_numeric($row['status_id'])
                        ? (int) $row['status_id'] : 0;
                    $isConditional = isset($row['is_conditional'])
                        && is_numeric($row['is_conditional'])
                        && (int) $row['is_conditional'] === 1;
                    $hotlinkDisabled = isset($row['is_hotlink_protection_disabled'])
                        && is_numeric($row['is_hotlink_protection_disabled'])
                        && (int) $row['is_hotlink_protection_disabled'] === 1;

                    $displayStatusId = $statusId === StatusFormatter::FORMAT_OPTIONAL && $isConditional
                        ? StatusFormatter::FORMAT_CONDITIONAL
                        : $statusId;

                    $row['format_id'] = $formatId;
                    $row['group_id'] = $groupId;
                    $row['raw_status_id'] = $statusId;
                    $row['status_id'] = $displayStatusId;
                    $row['status'] = StatusFormatter::videoFormat($displayStatusId, false);
                    $row['is_hotlink_protection_enabled'] = $hotlinkDisabled ? 0 : 1;
                    $row['size'] = $this->formatKvsVideoSize($row);
                    $row['limit_total_duration'] = $this->formatKvsDurationLimit($row);
                    $row['limit_offset_start'] = $this->formatKvsLimitValue(
                        $row,
                        'limit_offset_start',
                        'limit_offset_start_unit_id',
                        ''
                    );
                    $row['limit_offset_end'] = $this->formatKvsLimitValue(
                        $row,
                        'limit_offset_end',
                        'limit_offset_end_unit_id',
                        ''
                    );
                    $row['limit_speed_value'] = $this->formatKvsSpeedLimit($row);
                    $row['is_timeline_enabled'] = $this->getIntField($row, 'is_timeline_enabled') === 1
                        ? $this->formatKvsTimelineValue($row)
                        : '';
                    $formats[] = $row;
                }
            }

            return $this->addConfiguredFormatVideoCounts($db, $formats);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $format
     */
    private function formatKvsVideoSize(array $format): string
    {
        $size = isset($format['size']) && is_scalar($format['size']) ? (string) $format['size'] : '';
        if ($size === '') {
            return 'As source';
        }

        return match ($this->getIntField($format, 'resize_option2')) {
            0 => "{$size} (dynamic height)",
            1 => "{$size} (fixed size)",
            2 => "{$size} (dynamic width)",
            default => $size,
        };
    }

    /**
     * @param array<string, mixed> $format
     */
    private function formatKvsDurationLimit(array $format): string
    {
        if ($this->getIntField($format, 'customize_duration_id') > 0) {
            return 'Custom';
        }

        $duration = $this->getIntField($format, 'limit_total_duration');
        if ($duration <= 0) {
            if (
                $this->getIntField($format, 'customize_offset_start_id') > 0
                || $this->getIntField($format, 'customize_offset_end_id') > 0
            ) {
                return 'Custom';
            }

            $durationLabel = 'As source';
            if ($this->getIntField($format, 'limit_offset_start') > 0 || $this->getIntField($format, 'limit_offset_end') > 0) {
                $durationLabel = '______';
            }

            $startOffset = $this->formatKvsSignedOffset(
                $format,
                'limit_offset_start',
                'limit_offset_start_unit_id'
            );
            $endOffset = $this->formatKvsSignedOffset(
                $format,
                'limit_offset_end',
                'limit_offset_end_unit_id'
            );

            if ($startOffset !== '') {
                $durationLabel = "-{$startOffset} {$durationLabel}";
            }
            if ($endOffset !== '') {
                $durationLabel = "{$durationLabel} -{$endOffset}";
            }

            return $durationLabel;
        }

        $unitId = $this->getIntField($format, 'limit_total_duration_unit_id');
        if ($unitId === 1) {
            $parts = [];
            $minDuration = $this->getIntField($format, 'limit_total_min_duration_sec');
            if ($minDuration > 0) {
                $parts[] = "{$minDuration}s <=";
            }
            $parts[] = "{$duration}%";
            $maxDuration = $this->getIntField($format, 'limit_total_max_duration_sec');
            if ($maxDuration > 0) {
                $parts[] = "<= {$maxDuration}s";
            }
            $durationLabel = implode(' ', $parts);
        } else {
            $durationLabel = "{$duration}s";
        }

        $numberParts = $this->getIntField($format, 'limit_number_parts');
        if ($numberParts > 1) {
            $durationLabel .= " / {$numberParts}";
        }

        return $durationLabel;
    }

    /**
     * @param array<string, mixed> $format
     */
    private function formatKvsLimitValue(array $format, string $valueKey, string $unitKey, string $zeroLabel): string
    {
        if ($valueKey === 'limit_offset_start' && $this->getIntField($format, 'customize_offset_start_id') > 0) {
            return 'Custom';
        }
        if ($valueKey === 'limit_offset_end' && $this->getIntField($format, 'customize_offset_end_id') > 0) {
            return 'Custom';
        }

        $value = $this->getIntField($format, $valueKey);
        if ($value <= 0) {
            return $zeroLabel;
        }

        return $this->getIntField($format, $unitKey) === 1 ? "{$value}%" : "{$value}s";
    }

    /**
     * @param array<string, mixed> $format
     */
    private function formatKvsSignedOffset(array $format, string $valueKey, string $unitKey): string
    {
        $value = $this->getIntField($format, $valueKey);
        if ($value <= 0) {
            return '';
        }

        return $this->getIntField($format, $unitKey) === 1 ? "{$value}%" : "{$value}s";
    }

    /**
     * @param array<string, mixed> $format
     */
    private function formatKvsSpeedLimit(array $format): string
    {
        $value = $this->formatKvsSpeedLimitOption(
            $this->getIntField($format, 'limit_speed_option'),
            $this->getIntField($format, 'limit_speed_value')
        );

        $overrides = [];
        foreach (
            [
            ['limit_speed_guests_option', 'limit_speed_guests_value'],
            ['limit_speed_standard_option', 'limit_speed_standard_value'],
            ['limit_speed_premium_option', 'limit_speed_premium_value'],
            ['limit_speed_embed_option', 'limit_speed_embed_value'],
            ] as [$optionKey, $valueKey]
        ) {
            if (
                $this->getIntField($format, $optionKey) !== $this->getIntField($format, 'limit_speed_option')
                || $this->getIntField($format, $valueKey) !== $this->getIntField($format, 'limit_speed_value')
            ) {
                $overrides[] = $this->formatKvsSpeedLimitOption(
                    $this->getIntField($format, $optionKey),
                    $this->getIntField($format, $valueKey)
                );
            }
        }

        if ($this->getIntField($format, 'limit_speed_countries_option') > 0) {
            $overrides[] = $this->formatKvsSpeedLimitOption(
                $this->getIntField($format, 'limit_speed_countries_option'),
                $this->getIntField($format, 'limit_speed_countries_value')
            );
        }

        if ($overrides !== []) {
            $value .= ' (' . implode(', ', $overrides) . ')';
        }

        return $value;
    }

    private function formatKvsSpeedLimitOption(int $option, int $value): string
    {
        return match ($option) {
            1 => "{$value} kbit/s",
            2 => "x{$value}",
            default => 'N/A',
        };
    }

    /**
     * @param array<string, mixed> $format
     */
    private function formatKvsTimelineValue(array $format): string
    {
        if ($this->getIntField($format, 'timeline_option') === 1) {
            $amount = $this->getIntField($format, 'timeline_amount');

            return $amount > 0 ? "x{$amount}" : 'Default';
        }

        $interval = $this->getIntField($format, 'timeline_interval');

        return $interval > 0 ? "{$interval}s" : 'Default';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getIntField(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<array<string, mixed>> $formats
     * @return list<array<string, mixed>>
     */
    private function addConfiguredFormatVideoCounts(\PDO $db, array $formats): array
    {
        $postfixesByIndex = [];
        foreach ($formats as $index => $format) {
            $postfix = $format['postfix'] ?? '';
            if (is_string($postfix) && $postfix !== '') {
                $postfixesByIndex[$index] = $postfix;
            }
            $formats[$index]['videos_count'] = 0;
        }

        if ($postfixesByIndex === []) {
            return $formats;
        }

        try {
            $stmt = $db->query("
                SELECT file_formats
                FROM " . $this->table('videos') . "
                WHERE load_type_id = 1 AND status_id IN (0, 1)
            ");
        } catch (\Throwable) {
            return $formats;
        }

        if ($stmt === false) {
            return $formats;
        }

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $video) {
            if (!is_array($video)) {
                continue;
            }
            $fileFormats = $video['file_formats'] ?? '';
            if (!is_string($fileFormats)) {
                continue;
            }

            foreach ($postfixesByIndex as $index => $postfix) {
                if (str_contains($fileFormats, "||{$postfix}|")) {
                    $formats[$index]['videos_count']++;
                }
            }
        }

        return $formats;
    }

    /**
     * @return list<string>
     */
    private function getVideoStorageDirs(string $videoId): array
    {
        $basePaths = $this->getVideoStorageBasePaths($videoId);
        $dirs = [];

        foreach ($basePaths as $basePath) {
            $dirs[] = rtrim($basePath, '/') . '/' . $this->getDirById($videoId) . '/' . $videoId;
        }

        return array_values(array_unique($dirs));
    }

    /**
     * @return list<string>
     */
    private function getVideoStorageBasePaths(string $videoId): array
    {
        $db = $this->getDatabaseConnection(true);
        if ($db !== null) {
            $serverPaths = $this->getLocalVideoServerPaths($db, $videoId);
            if ($serverPaths['configured'] !== []) {
                if ($serverPaths['existing'] !== []) {
                    return $serverPaths['existing'];
                }

                $fallbackPaths = $this->getFallbackVideoStorageBasePaths();
                return $fallbackPaths !== [] ? $fallbackPaths : $serverPaths['configured'];
            }
        }

        return $this->getFallbackVideoStorageBasePaths();
    }

    /**
     * @return list<string>
     */
    private function getFallbackVideoStorageBasePaths(): array
    {
        $videosPath = $this->config->getContentPath() . '/' . Constants::CONTENT_VIDEOS;
        if (is_dir($videosPath)) {
            return [$videosPath];
        }

        $sourcesPath = $this->config->getVideoSourcesPath();
        if ($sourcesPath !== '') {
            return [dirname($sourcesPath) . '/' . Constants::CONTENT_VIDEOS];
        }

        return [];
    }

    /**
     * @return array{configured: list<string>, existing: list<string>}
     */
    private function getLocalVideoServerPaths(\PDO $db, string $videoId): array
    {
        $result = [
            'configured' => [],
            'existing' => [],
        ];

        try {
            $videoStmt = $db->prepare(
                'SELECT server_group_id FROM ' . $this->table('videos') . ' WHERE video_id = :video_id'
            );
            if ($videoStmt === false) {
                return $result;
            }
            $videoStmt->execute(['video_id' => (int) $videoId]);
            $video = $videoStmt->fetch();
            if (!is_array($video)) {
                return $result;
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
            $query .= ' ORDER BY server_id ASC';

            $serverStmt = $db->prepare($query);
            if ($serverStmt === false) {
                return $result;
            }
            $serverStmt->execute($params);

            while (($server = $serverStmt->fetch()) !== false) {
                if (!is_array($server)) {
                    continue;
                }

                $path = $server['path'] ?? null;
                if (is_string($path) && $path !== '') {
                    $result['configured'][] = $path;
                    if (is_dir($path)) {
                        $result['existing'][] = $path;
                    }
                }
            }

            return [
                'configured' => array_values(array_unique($result['configured'])),
                'existing' => array_values(array_unique($result['existing'])),
            ];
        } catch (\Exception) {
            return $result;
        }
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function filterExistingDirs(array $paths): array
    {
        return array_values(array_filter($paths, static fn(string $path): bool => is_dir($path)));
    }

    /**
     * @param list<string> $dirs
     */
    private function findExistingFile(array $dirs, string $filename): ?string
    {
        foreach ($dirs as $dir) {
            $path = $dir . '/' . $filename;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function getDirById(string $id): int
    {
        return (int) floor((int) $id / 1000) * 1000;
    }

    private function normalizeVideoId(string $videoId): ?string
    {
        if (preg_match('/^[1-9]\d*$/', $videoId) !== 1) {
            $this->io()->error('Invalid video ID (use: integer >= 1)');
            return null;
        }

        return $videoId;
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
