<?php

namespace KVS\CLI\Command\Settings;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ExperimentalCommandTrait;
use KVS\CLI\Constants;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'settings:video-format',
    description: '[EXPERIMENTAL] Manage KVS video formats',
    aliases: ['video-format', 'vformat']
)]
class VideoFormatCommand extends BaseCommand
{
    use ExperimentalCommandTrait;

    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml', 'count'];
    private const LIST_COMPUTED_FIELDS = [
        'id',
        'status',
        'access',
        'download',
        'timeline',
        'limit_total_duration',
        'limit_offset_start',
        'limit_offset_end',
        'limit_speed_value',
        'is_timeline_enabled',
        'pc_complete',
        'is_error',
        'source_text',
        'watermark_position_offset',
        'watermark_position_scrolling',
        'watermark2_position_offset',
        'watermark2_position_scrolling',
        'watermark_image',
        'watermark_image_url',
        'watermark2_image',
        'watermark2_image_url',
        'preroll_video',
        'preroll_video_url',
        'postroll_video',
        'postroll_video_url',
        'videos_count',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|groups)', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'Format ID (for show action)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (disabled|required|optional|deleting|error|conditional)')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Filter by group ID')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search in title, postfix, and FFmpeg options')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields')
            ->setHelp(<<<'HELP'
Manage KVS video format configurations.

<fg=yellow>ACTIONS:</>
  list              List all configured video formats
  show <id>         Show detailed format information
  groups            List format groups

<fg=yellow>STATUS VALUES:</>
  disabled (0)      Format is disabled
  required (1)      Always converted for every video
  optional (2)      Converted if source quality allows
  deleting (3)      Format is being deleted
  error (4)         Conversion error occurred
  conditional (9)   Optional with specific conditions

<fg=yellow>ACCESS LEVELS:</>
  any (0)           Available to guests
  member (1)        Requires membership
  premium (2)       Premium members only

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs video-format list</>
  <fg=green>kvs video-format list --status=required</>
  <fg=green>kvs video-format list --group=1</>
  <fg=green>kvs video-format show 1</>
  <fg=green>kvs video-format groups</>
  <fg=green>kvs video-format list --format=json</>

<fg=yellow>NOTE:</>
  This manages format configuration (admin/formats_videos.php).
  Use "kvs video:formats" to check actual video files.
HELP
            );
        $this->configureExperimentalOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $abort = $this->confirmExperimental($input, $output);
        if ($abort !== null) {
            return $abort;
        }

        $action = $this->getStringArgument($input, 'action') ?? 'list';

        return match ($action) {
            'list' => $this->listFormats($input),
            'show' => $this->showFormat($input, $this->getStringArgument($input, 'id')),
            'groups' => $this->listGroups($input),
            default => $this->failUnknownAction(
                'video-format',
                $action,
                ['list', 'show', 'groups']
            ),
        };
    }

    private function listFormats(InputInterface $input): int
    {
        if ($this->getStringArgument($input, 'id') !== null) {
            $this->io()->error(
                'The list action does not support a format ID. Use show for a specific format.'
            );
            return self::FAILURE;
        }

        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $query = "SELECT
                f.*,
                CASE WHEN f.status_id = 2 AND f.is_conditional = 1 THEN 9 ELSE f.status_id END as display_status_id,
                CASE WHEN f.is_hotlink_protection_disabled = 1 THEN 0 ELSE 1 END as is_hotlink_protection_enabled,
                g.title as group_title
            FROM {$this->table('formats_videos')} f
            LEFT JOIN {$this->table('formats_videos_groups')} g
                ON f.format_video_group_id = g.format_video_group_id
            WHERE 1=1";

        $params = [];

        $search = $this->getStringOption($input, 'search');
        if ($search !== null) {
            $searchEscape = $this->likeEscapeSql();
            $query .= ' AND (f.title LIKE :search' . $searchEscape
                . ' OR f.postfix LIKE :search' . $searchEscape
                . ' OR f.ffmpeg_options LIKE :search' . $searchEscape . ')';
            $params['search'] = $this->containsLikePattern($search);
        }

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusMap = [
                'disabled' => StatusFormatter::FORMAT_DISABLED,
                'required' => StatusFormatter::FORMAT_REQUIRED,
                'optional' => StatusFormatter::FORMAT_OPTIONAL,
                'deleting' => StatusFormatter::FORMAT_DELETING,
                'error' => StatusFormatter::FORMAT_ERROR,
                'conditional' => StatusFormatter::FORMAT_CONDITIONAL,
            ];
            $validStatusIds = array_values($statusMap);
            // Accept case-insensitive strings and numeric values
            $statusLower = strtolower($status);
            $statusId = null;
            if (isset($statusMap[$statusLower])) {
                $statusId = $statusMap[$statusLower];
            } elseif (ctype_digit($status) && in_array((int) $status, $validStatusIds, true)) {
                $statusId = (int) $status;
            } else {
                $this->io()->error(sprintf(
                    'Invalid value for --status: %s. Expected one of: %s.',
                    $status,
                    implode(', ', array_merge(array_keys($statusMap), array_map('strval', $validStatusIds)))
                ));
                return self::FAILURE;
            }

            if ($statusId === StatusFormatter::FORMAT_CONDITIONAL) {
                $query .= " AND f.status_id = :status AND f.is_conditional = 1";
                $params['status'] = StatusFormatter::FORMAT_OPTIONAL;
            } else {
                $query .= " AND f.status_id = :status";
                $params['status'] = $statusId;
                if ($statusId === StatusFormatter::FORMAT_OPTIONAL) {
                    $query .= " AND COALESCE(f.is_conditional, 0) = 0";
                }
            }
        }

        $groupId = $this->getOptionalNonNegativeIntOption($input, 'group');
        if ($groupId === false) {
            return self::FAILURE;
        }
        if ($groupId !== null) {
            $query .= " AND f.format_video_group_id = :group_id";
            $params['group_id'] = $groupId;
        }

        $query .= " ORDER BY f.format_video_id DESC";

        try {
            $stmt = $db->prepare($query);
            $stmt->execute($params);

            /** @var list<array<string, mixed>> $formats */
            $formats = $stmt->fetchAll();
            $formats = $this->addVideoCounts($db, $formats);
            $deletingProgressByPostfix = $this->getDeletingFormatProgressByPostfix($db);
            $defaultFields = ['format_video_id', 'title', 'postfix', 'status', 'size', 'access', 'download', 'timeline'];
            $knownFields = $this->getVideoFormatListKnownFields($stmt);

            if ($formats === []) {
                if ($this->isTableFormat($input) && !$this->hasFieldSelection($input)) {
                    $this->io()->warning('No video formats found');
                } else {
                    $formatter = new Formatter($input->getOptions(), $defaultFields, $knownFields);
                    $formatter->display([], $this->io());
                }
                return self::SUCCESS;
            }

            // Transform data for display
            $formats = array_map(function (array $format) use ($deletingProgressByPostfix): array {
                $format = $this->addKvsAdminListComputedFields($format, $deletingProgressByPostfix);
                $format = $this->addKvsFileBackedFields($format);
                $format['id'] = $format['format_video_id'];
                $statusIdValue = $format['display_status_id'] ?? $format['status_id'] ?? 0;
                $statusId = is_numeric($statusIdValue) ? (int) $statusIdValue : 0;
                $format['status_id'] = $statusId;
                $format['status'] = StatusFormatter::videoFormat($statusId, false);

                $accessId = isset($format['access_level_id']) && is_numeric($format['access_level_id']) ? (int) $format['access_level_id'] : 0;
                $format['access'] = StatusFormatter::formatAccessLevel($accessId, false);

                $isDownload = isset($format['is_download_enabled']) && (bool) $format['is_download_enabled'];
                $format['download'] = $isDownload ? 'Yes' : 'No';
                $isTimeline = isset($format['is_timeline_enabled']) && (bool) $format['is_timeline_enabled'];
                $format['timeline'] = $isTimeline ? $this->formatKvsTimelineValue($format) : 'No';
                $format['size'] = $this->formatKvsVideoSize($format);
                $format['limit_total_duration'] = $this->formatKvsDurationLimit($format);
                $format['limit_offset_start'] = $this->formatKvsLimitValue(
                    $format,
                    'limit_offset_start',
                    'limit_offset_start_unit_id',
                    ''
                );
                $format['limit_offset_end'] = $this->formatKvsLimitValue(
                    $format,
                    'limit_offset_end',
                    'limit_offset_end_unit_id',
                    ''
                );
                $format['limit_speed_value'] = $this->formatKvsSpeedLimit($format);
                $format['is_timeline_enabled'] = $isTimeline ? $this->formatKvsTimelineValue($format) : '';

                return $format;
            }, $formats);

            $formatter = new Formatter($input->getOptions(), $defaultFields, $knownFields);
            $formatter->display($formats, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch video formats: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function getVideoFormatListKnownFields(\PDOStatement $stmt): array
    {
        return array_values(array_unique(array_merge(
            $this->getStatementColumnNames($stmt),
            self::LIST_COMPUTED_FIELDS
        )));
    }

    /**
     * @param array<string, string> $deletingProgressByPostfix
     * @param array<string, mixed> $format
     * @return array<string, mixed>
     */
    private function addKvsAdminListComputedFields(array $format, array $deletingProgressByPostfix): array
    {
        $format['pc_complete'] = '';
        $format['is_error'] = 0;
        $format['source_text'] = '';
        $format['watermark_position_offset'] = '';
        $format['watermark_position_scrolling'] = '';
        $format['watermark2_position_offset'] = '';
        $format['watermark2_position_scrolling'] = '';

        $rawStatusId = $this->getIntField($format, 'status_id');
        if ($rawStatusId === StatusFormatter::FORMAT_DELETING) {
            $postfix = isset($format['postfix']) && is_scalar($format['postfix']) ? (string) $format['postfix'] : '';
            if ($postfix !== '' && array_key_exists($postfix, $deletingProgressByPostfix)) {
                $format['pc_complete'] = $deletingProgressByPostfix[$postfix];
            } else {
                $format['display_status_id'] = StatusFormatter::FORMAT_ERROR;
                $format['is_error'] = 1;
            }
        } elseif ($rawStatusId === StatusFormatter::FORMAT_ERROR) {
            $format['is_error'] = 1;
        }

        if ($this->getIntField($format, 'is_use_as_source') === 1) {
            $format['source_text'] = '(Use as source)';
        }

        $format = $this->addKvsWatermarkAppendFields(
            $format,
            'watermark_position_id',
            'watermark_offset_random',
            'watermark_scrolling_times',
            'watermark_scrolling_duration',
            'watermark_position_offset',
            'watermark_position_scrolling'
        );

        return $this->addKvsWatermarkAppendFields(
            $format,
            'watermark2_position_id',
            'watermark2_offset_random',
            'watermark2_scrolling_times',
            'watermark2_scrolling_duration',
            'watermark2_position_offset',
            'watermark2_position_scrolling'
        );
    }

    /**
     * @param array<string, mixed> $format
     * @return array<string, mixed>
     */
    private function addKvsWatermarkAppendFields(
        array $format,
        string $positionKey,
        string $randomOffsetKey,
        string $scrollingTimesKey,
        string $scrollingDurationKey,
        string $offsetOutputKey,
        string $scrollingOutputKey
    ): array {
        $randomOffset = $format[$randomOffsetKey] ?? '';
        $randomOffset = is_scalar($randomOffset) ? (string) $randomOffset : '';

        if (in_array($this->getIntField($format, $positionKey), [5, 6, 7], true)) {
            $format[$scrollingOutputKey] = $this->getIntField($format, $scrollingTimesKey)
                . ' x ' . $this->getIntField($format, $scrollingDurationKey) . 's';
            if ($randomOffset !== '') {
                $format[$scrollingOutputKey] .= " ±{$randomOffset}";
            }
            return $format;
        }

        if ($randomOffset !== '') {
            $format[$offsetOutputKey] = "±{$randomOffset}";
        }

        return $format;
    }

    /**
     * @return array<string, string>
     */
    private function getDeletingFormatProgressByPostfix(\PDO $db): array
    {
        try {
            $stmt = $db->query("
                SELECT task_id, data
                FROM {$this->table('background_tasks')}
                WHERE type_id = 6
            ");
        } catch (\Throwable) {
            return [];
        }

        if ($stmt === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $tasks */
        $tasks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $progressByPostfix = [];
        foreach ($tasks as $task) {
            $taskDataValue = $task['data'] ?? null;
            if (!is_string($taskDataValue) || $taskDataValue === '') {
                continue;
            }
            $taskData = @unserialize($taskDataValue, ['allowed_classes' => false]);
            if (!is_array($taskData) || !isset($taskData['format_postfix']) || !is_scalar($taskData['format_postfix'])) {
                continue;
            }

            $taskId = $this->getIntField($task, 'task_id');
            $progressByPostfix[(string) $taskData['format_postfix']] = $this->readFormatDeletionProgress($taskId);
        }

        return $progressByPostfix;
    }

    private function readFormatDeletionProgress(int $taskId): string
    {
        $progressFile = $this->config->getKvsPath() . '/admin/data/engine/tasks/' . $taskId . '.dat';
        $progress = @file_get_contents($progressFile);

        return ((int) ($progress !== false ? $progress : 0)) . '%';
    }

    /**
     * @param list<array<string, mixed>> $formats
     * @return list<array<string, mixed>>
     */
    private function addVideoCounts(\PDO $db, array $formats): array
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
                FROM {$this->table('videos')}
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
     * @param array<string, mixed> $format
     * @return array<string, mixed>
     */
    private function addKvsFileBackedFields(array $format): array
    {
        $formatId = $this->getIntField($format, 'format_video_id');
        $otherDataPath = $this->config->getKvsPath() . '/admin/data/other';

        $format['watermark_image'] = '';
        $format['watermark2_image'] = '';
        $format['preroll_video'] = '';
        $format['postroll_video'] = '';

        if ($formatId <= 0) {
            return $format;
        }

        if (is_file($otherDataPath . "/watermark_video_{$formatId}.png")) {
            $format['watermark_image'] = "{$formatId}.png";
            $format['watermark_image_url'] = "formats_videos.php?action=download_watermark&id={$formatId}";
        } else {
            $format['watermark_position_id'] = '';
            $format['watermark_max_width'] = '';
        }

        if (is_file($otherDataPath . "/watermark2_video_{$formatId}.png")) {
            $format['watermark2_image'] = "{$formatId}.png";
            $format['watermark2_image_url'] = "formats_videos.php?action=download_watermark2&id={$formatId}";
        } else {
            $format['watermark2_position_id'] = '';
            $format['watermark2_max_height'] = '';
        }

        if (is_file($otherDataPath . "/preroll_video_{$formatId}.mp4")) {
            $format['preroll_video'] = "{$formatId}.mp4";
            $format['preroll_video_url'] = "formats_videos.php?action=download_preroll&id={$formatId}";
        }

        if (is_file($otherDataPath . "/postroll_video_{$formatId}.mp4")) {
            $format['postroll_video'] = "{$formatId}.mp4";
            $format['postroll_video_url'] = "formats_videos.php?action=download_postroll&id={$formatId}";
        }

        return $format;
    }

    private function showFormat(InputInterface $input, ?string $id): int
    {
        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        if ($this->getStringOption($input, 'status') !== null) {
            $this->io()->error('The show action does not support --status. Use list --status to filter video formats.');
            return self::FAILURE;
        }

        if ($this->getStringOption($input, 'group') !== null) {
            $this->io()->error('The show action does not support --group. Use list --group to filter video formats.');
            return self::FAILURE;
        }

        if ($this->getStringOption($input, 'search') !== null) {
            $this->io()->error('The show action does not support --search. Use list --search to filter video formats.');
            return self::FAILURE;
        }

        $formatId = $this->getRequiredPositiveId($id, 'Format');
        if ($formatId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("
                SELECT f.*,
                       CASE WHEN f.status_id = 2 AND f.is_conditional = 1 THEN 9 ELSE f.status_id END as display_status_id,
                       CASE WHEN f.is_hotlink_protection_disabled = 1 THEN 0 ELSE 1 END as is_hotlink_protection_enabled,
                       g.title as group_title
                FROM {$this->table('formats_videos')} f
                LEFT JOIN {$this->table('formats_videos_groups')} g
                    ON f.format_video_group_id = g.format_video_group_id
                WHERE f.format_video_id = :id
            ");
            $stmt->execute(['id' => $formatId]);
            /** @var array<string, mixed>|false $format */
            $format = $stmt->fetch();

            if ($format === false) {
                $this->io()->error("Format not found: $formatId");
                return self::FAILURE;
            }

            $format = $this->addKvsFileBackedFields($format);

            $statusIdValue = $format['display_status_id'] ?? $format['status_id'] ?? 0;
            $statusId = is_numeric($statusIdValue) ? (int) $statusIdValue : 0;
            $groupId = isset($format['format_video_group_id']) && is_numeric($format['format_video_group_id'])
                ? (int) $format['format_video_group_id'] : 0;
            $groupTitle = isset($format['group_title']) && is_string($format['group_title'])
                ? $format['group_title'] : 'None';

            $title = isset($format['title']) && is_string($format['title']) ? $format['title'] : '';
            $postfix = isset($format['postfix']) && is_string($format['postfix']) ? $format['postfix'] : '';
            $size = isset($format['size']) && is_string($format['size']) ? $format['size'] : '';
            $formattedSize = $this->formatKvsVideoSize($format);
            $accessId = isset($format['access_level_id']) && is_numeric($format['access_level_id'])
                ? (int) $format['access_level_id'] : 0;
            $downloadEnabled = isset($format['is_download_enabled']) && (bool) $format['is_download_enabled'];
            $hotlinkProtection = isset($format['is_hotlink_protection_enabled'])
                && is_numeric($format['is_hotlink_protection_enabled'])
                ? (int) $format['is_hotlink_protection_enabled'] : 0;
            $timelineEnabled = isset($format['is_timeline_enabled']) && (bool) $format['is_timeline_enabled'];
            $ffmpegOptions = isset($format['ffmpeg_options']) && is_string($format['ffmpeg_options'])
                ? trim($format['ffmpeg_options'])
                : '';

            $formatRow = [
                ...$format,
                'format_video_id' => $format['format_video_id'] ?? $id,
                'id' => $format['format_video_id'] ?? $id,
                'title' => $title,
                'postfix' => $postfix,
                'status_id' => $statusId,
                'status' => StatusFormatter::videoFormat($statusId, false),
                'size' => $formattedSize,
                'group' => $groupId > 0 ? "$groupTitle (#$groupId)" : 'None',
                'access' => StatusFormatter::formatAccessLevel($accessId, false),
                'download' => $downloadEnabled ? 'Yes' : 'No',
                'hotlink_protection' => $hotlinkProtection > 0 ? 'Yes' : 'No',
                'limit_total_duration' => $this->formatKvsDurationLimit($format),
                'limit_offset_start' => $this->formatKvsLimitValue($format, 'limit_offset_start', 'limit_offset_start_unit_id', '0'),
                'limit_offset_end' => $this->formatKvsLimitValue($format, 'limit_offset_end', 'limit_offset_end_unit_id', '0'),
                'timeline' => $timelineEnabled ? $this->formatKvsTimelineValue($format) : 'Default',
                'ffmpeg_options' => $ffmpegOptions,
            ];

            if ($this->shouldUseFormattedRows($input)) {
                return $this->displayFormattedRows($input, [$formatRow], array_keys($formatRow));
            }

            $this->io()->title("Video Format #$id");

            // Basic Info section
            $this->io()->section('Basic Info');

            $basicInfo = [
                ['Title', $title],
                ['Postfix', $postfix],
                ['Status', StatusFormatter::videoFormat($statusId)],
                ['Size', $formattedSize],
                ['Group', $groupId > 0 ? "$groupTitle (#$groupId)" : 'None'],
            ];
            $this->renderTable(['Property', 'Value'], $basicInfo);

            // Access & Download section
            $this->io()->section('Access & Download');

            $accessInfo = [
                ['Access Level', StatusFormatter::formatAccessLevel($accessId)],
                ['Download Enabled', $downloadEnabled ? '<fg=green>Yes</>' : '<fg=gray>No</>'],
                ['Hotlink Protection', $hotlinkProtection > 0 ? '<fg=green>Yes</>' : '<fg=gray>No</>'],
            ];
            $this->renderTable(['Property', 'Value'], $accessInfo);

            // Duration & Offset Limits section
            $this->io()->section('Duration & Offset Limits');
            $durationInfo = [
                ['Total Duration', $this->formatKvsDurationLimit($format)],
                [
                    'Start Offset',
                    $this->formatKvsLimitValue($format, 'limit_offset_start', 'limit_offset_start_unit_id', '0'),
                ],
                [
                    'End Offset',
                    $this->formatKvsLimitValue($format, 'limit_offset_end', 'limit_offset_end_unit_id', '0'),
                ],
            ];
            $this->renderTable(['Property', 'Value'], $durationInfo);

            // Timeline Settings section
            $this->io()->section('Timeline Settings');

            $timelineInfo = [
                ['Timeline Enabled', $timelineEnabled ? '<fg=green>Yes</>' : '<fg=gray>No</>'],
                ['Timeline Interval', $timelineEnabled ? $this->formatKvsTimelineValue($format) : 'Default'],
            ];
            $this->renderTable(['Property', 'Value'], $timelineInfo);

            // Watermark Settings section
            $watermarkId = isset($format['watermark_id']) && is_numeric($format['watermark_id']) ? (int) $format['watermark_id'] : 0;
            if ($watermarkId > 0) {
                $this->io()->section('Watermark Settings');
                $watermarkInfo = [
                    ['Watermark ID', (string) $watermarkId],
                ];
                $this->renderTable(['Property', 'Value'], $watermarkInfo);
            }

            // FFmpeg Options section
            if ($ffmpegOptions !== '') {
                $this->io()->section('FFmpeg Options');
                $this->io()->text($ffmpegOptions);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch format: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function listGroups(InputInterface $input): int
    {
        if ($this->getStringArgument($input, 'id') !== null) {
            $this->io()->error('The groups action does not support a group ID.');
            return self::FAILURE;
        }

        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        if ($this->getStringOption($input, 'status') !== null) {
            $this->io()->error('The groups action does not support --status. Use list --status to filter video formats.');
            return self::FAILURE;
        }

        if ($this->getStringOption($input, 'group') !== null) {
            $this->io()->error('The groups action does not support --group. Use list --group to filter video formats.');
            return self::FAILURE;
        }

        if ($this->getStringOption($input, 'search') !== null) {
            $this->io()->error('The groups action does not support --search. Use list --search to filter video formats.');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->query("
                SELECT g.*,
                    (
                        SELECT COUNT(*)
                        FROM {$this->table('formats_videos')} f
                        WHERE f.format_video_group_id = g.format_video_group_id
                    ) as format_count,
                    (
                        SELECT COUNT(*)
                        FROM {$this->table('videos')} v
                        WHERE v.format_video_group_id = g.format_video_group_id
                            AND v.load_type_id = 1
                            AND v.status_id IN (0, 1)
                    ) as videos_count
                FROM {$this->table('formats_videos_groups')} g
                ORDER BY g.format_video_group_id ASC
            ");

            if ($stmt === false) {
                $this->io()->error('Failed to fetch format groups');
                return self::FAILURE;
            }

            /** @var list<array<string, mixed>> $groups */
            $groups = $stmt->fetchAll();

            if ($groups === []) {
                $this->io()->warning('No format groups found');
                return self::SUCCESS;
            }

            // Transform data for display
            $groups = array_map(function (array $group): array {
                $group['id'] = $group['format_video_group_id'];
                $isDefault = isset($group['is_default']) && (bool) $group['is_default'];
                $group['default'] = $isDefault ? 'Yes' : 'No';
                $isPremium = isset($group['is_premium']) && (bool) $group['is_premium'];
                $group['premium'] = $isPremium ? 'Yes' : 'No';
                $group['formats'] = $group['format_count'] ?? 0;

                return $group;
            }, $groups);

            $defaultFields = ['format_video_group_id', 'title', 'default', 'premium', 'formats'];

            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($groups, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch format groups: ' . $e->getMessage());
            return self::FAILURE;
        }
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
}
