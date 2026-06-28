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

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|groups)', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'Format ID (for show action)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (disabled|required|optional|deleting|error|conditional)')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Filter by group ID')
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
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $query = "SELECT
                f.format_video_id,
                f.title,
                f.postfix,
                f.status_id,
                CASE WHEN f.status_id = 2 AND f.is_conditional = 1 THEN 9 ELSE f.status_id END as display_status_id,
                f.size,
                f.access_level_id,
                f.is_download_enabled,
                f.is_timeline_enabled,
                f.timeline_option,
                f.timeline_amount,
                f.timeline_interval,
                f.format_video_group_id,
                g.title as group_title
            FROM {$this->table('formats_videos')} f
            LEFT JOIN {$this->table('formats_videos_groups')} g
                ON f.format_video_group_id = g.format_video_group_id
            WHERE 1=1";

        $params = [];

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

        $groupId = $this->getIntOption($input, 'group');
        if ($groupId !== null) {
            $query .= " AND f.format_video_group_id = :group_id";
            $params['group_id'] = $groupId;
        }

        $query .= " ORDER BY f.format_video_group_id ASC, f.format_video_id ASC";

        try {
            $stmt = $db->prepare($query);
            $stmt->execute($params);

            /** @var list<array<string, mixed>> $formats */
            $formats = $stmt->fetchAll();

            if ($formats === []) {
                $this->io()->warning('No video formats found');
                return self::SUCCESS;
            }

            // Transform data for display
            $formats = array_map(function (array $format): array {
                $format['id'] = $format['format_video_id'];
                $statusIdValue = $format['display_status_id'] ?? $format['status_id'] ?? 0;
                $statusId = is_numeric($statusIdValue) ? (int) $statusIdValue : 0;
                $format['status'] = StatusFormatter::videoFormat($statusId, false);

                $accessId = isset($format['access_level_id']) && is_numeric($format['access_level_id']) ? (int) $format['access_level_id'] : 0;
                $format['access'] = StatusFormatter::formatAccessLevel($accessId, false);

                $isDownload = isset($format['is_download_enabled']) && (bool) $format['is_download_enabled'];
                $format['download'] = $isDownload ? 'Yes' : 'No';
                $isTimeline = isset($format['is_timeline_enabled']) && (bool) $format['is_timeline_enabled'];
                $format['timeline'] = $isTimeline ? $this->formatKvsTimelineValue($format) : 'No';

                return $format;
            }, $formats);

            $defaultFields = ['format_video_id', 'title', 'postfix', 'status', 'size', 'access', 'download', 'timeline'];

            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($formats, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch video formats: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showFormat(InputInterface $input, ?string $id): int
    {
        $format = $this->getStringOptionOrDefault($input, 'format', 'table');
        if ($format !== 'table') {
            $this->io()->error('The show action only supports table output. Use list --format=' . $format . ' for machine-readable output.');
            return self::FAILURE;
        }

        if ($id === null || $id === '') {
            $this->io()->error('Format ID is required');
            $this->io()->text('Usage: kvs video-format show <id>');
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
            $stmt->execute(['id' => $id]);
            /** @var array<string, mixed>|false $format */
            $format = $stmt->fetch();

            if ($format === false) {
                $this->io()->error("Format not found: $id");
                return self::FAILURE;
            }

            $this->io()->title("Video Format #$id");

            // Basic Info section
            $this->io()->section('Basic Info');
            $statusIdValue = $format['display_status_id'] ?? $format['status_id'] ?? 0;
            $statusId = is_numeric($statusIdValue) ? (int) $statusIdValue : 0;
            $groupId = isset($format['format_video_group_id']) && is_numeric($format['format_video_group_id'])
                ? (int) $format['format_video_group_id'] : 0;
            $groupTitle = isset($format['group_title']) && is_string($format['group_title'])
                ? $format['group_title'] : 'None';

            $title = isset($format['title']) && is_string($format['title']) ? $format['title'] : '';
            $postfix = isset($format['postfix']) && is_string($format['postfix']) ? $format['postfix'] : '';
            $size = isset($format['size']) && is_string($format['size']) ? $format['size'] : '';

            $basicInfo = [
                ['Title', $title],
                ['Postfix', $postfix],
                ['Status', StatusFormatter::videoFormat($statusId)],
                ['Size', $size],
                ['Group', $groupId > 0 ? "$groupTitle (#$groupId)" : 'None'],
            ];
            $this->renderTable(['Property', 'Value'], $basicInfo);

            // Access & Download section
            $this->io()->section('Access & Download');
            $accessId = isset($format['access_level_id']) && is_numeric($format['access_level_id'])
                ? (int) $format['access_level_id'] : 0;
            $downloadEnabled = isset($format['is_download_enabled']) && (bool) $format['is_download_enabled'];
            $hotlinkProtection = isset($format['is_hotlink_protection_enabled'])
                && is_numeric($format['is_hotlink_protection_enabled'])
                ? (int) $format['is_hotlink_protection_enabled'] : 0;

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
            $timelineEnabled = isset($format['is_timeline_enabled']) && (bool) $format['is_timeline_enabled'];

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
            $ffmpegOptions = isset($format['ffmpeg_options']) && is_string($format['ffmpeg_options']) ? trim($format['ffmpeg_options']) : '';
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
        if ($this->getStringOption($input, 'status') !== null) {
            $this->io()->error('The groups action does not support --status. Use list --status to filter video formats.');
            return self::FAILURE;
        }

        if ($this->getStringOption($input, 'group') !== null) {
            $this->io()->error('The groups action does not support --group. Use list --group to filter video formats.');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->query("
                SELECT
                    g.format_video_group_id,
                    g.title,
                    g.is_default,
                    g.is_premium,
                    COUNT(f.format_video_id) as format_count
                FROM {$this->table('formats_videos_groups')} g
                LEFT JOIN {$this->table('formats_videos')} f
                    ON g.format_video_group_id = f.format_video_group_id
                GROUP BY g.format_video_group_id, g.title, g.is_default, g.is_premium
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
        $duration = $this->getIntField($format, 'limit_total_duration');
        if ($duration <= 0) {
            return 'Source';
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
        $value = $this->getIntField($format, $valueKey);
        if ($value <= 0) {
            return $zeroLabel;
        }

        return $this->getIntField($format, $unitKey) === 1 ? "{$value}%" : "{$value}s";
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
