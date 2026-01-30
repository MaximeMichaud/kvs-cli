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

        $action = $this->getStringArgument($input, 'action');

        return match ($action) {
            'list' => $this->listFormats($input),
            'show' => $this->showFormat($this->getStringArgument($input, 'id')),
            'groups' => $this->listGroups($input),
            default => $this->listFormats($input),
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
                f.size,
                f.access_level_id,
                f.is_download_enabled,
                f.is_timeline_enabled,
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
            // Accept case-insensitive strings and numeric values
            $statusLower = strtolower($status);
            if (isset($statusMap[$statusLower])) {
                $query .= " AND f.status_id = :status";
                $params['status'] = $statusMap[$statusLower];
            } elseif (ctype_digit($status)) {
                $query .= " AND f.status_id = :status";
                $params['status'] = (int) $status;
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
                $statusId = isset($format['status_id']) && is_numeric($format['status_id']) ? (int) $format['status_id'] : 0;
                $format['status'] = StatusFormatter::videoFormat($statusId, false);

                $accessId = isset($format['access_level_id']) && is_numeric($format['access_level_id']) ? (int) $format['access_level_id'] : 0;
                $format['access'] = StatusFormatter::formatAccessLevel($accessId, false);

                $isDownload = isset($format['is_download_enabled']) && (bool) $format['is_download_enabled'];
                $format['download'] = $isDownload ? 'Yes' : 'No';
                $isTimeline = isset($format['is_timeline_enabled']) && (bool) $format['is_timeline_enabled'];
                $format['timeline'] = $isTimeline ? 'Yes' : 'No';

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

    private function showFormat(?string $id): int
    {
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
                SELECT f.*, g.title as group_title
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
            $statusId = isset($format['status_id']) && is_numeric($format['status_id'])
                ? (int) $format['status_id'] : 0;
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
            $hotlinkProtection = isset($format['hotlink_protection']) && is_numeric($format['hotlink_protection'])
                ? (int) $format['hotlink_protection'] : 0;

            $accessInfo = [
                ['Access Level', StatusFormatter::formatAccessLevel($accessId)],
                ['Download Enabled', $downloadEnabled ? '<fg=green>Yes</>' : '<fg=gray>No</>'],
                ['Hotlink Protection', $hotlinkProtection > 0 ? '<fg=green>Yes</>' : '<fg=gray>No</>'],
            ];
            $this->renderTable(['Property', 'Value'], $accessInfo);

            // Duration & Offset Limits section
            $this->io()->section('Duration & Offset Limits');
            $durationMin = isset($format['video_duration_from']) && is_numeric($format['video_duration_from'])
                ? (int) $format['video_duration_from'] : 0;
            $durationMax = isset($format['video_duration_to']) && is_numeric($format['video_duration_to'])
                ? (int) $format['video_duration_to'] : 0;
            $offsetStart = isset($format['video_start_offset']) && is_numeric($format['video_start_offset'])
                ? (int) $format['video_start_offset'] : 0;
            $offsetEnd = isset($format['video_end_offset']) && is_numeric($format['video_end_offset'])
                ? (int) $format['video_end_offset'] : 0;

            $durationInfo = [
                ['Duration Min (sec)', $durationMin > 0 ? (string) $durationMin : 'No limit'],
                ['Duration Max (sec)', $durationMax > 0 ? (string) $durationMax : 'No limit'],
                ['Start Offset (sec)', $offsetStart > 0 ? (string) $offsetStart : '0'],
                ['End Offset (sec)', $offsetEnd > 0 ? (string) $offsetEnd : '0'],
            ];
            $this->renderTable(['Property', 'Value'], $durationInfo);

            // Timeline Settings section
            $this->io()->section('Timeline Settings');
            $timelineEnabled = isset($format['is_timeline_enabled']) && (bool) $format['is_timeline_enabled'];
            $timelineInterval = isset($format['timeline_interval']) && is_numeric($format['timeline_interval'])
                ? (int) $format['timeline_interval'] : 0;

            $timelineInfo = [
                ['Timeline Enabled', $timelineEnabled ? '<fg=green>Yes</>' : '<fg=gray>No</>'],
                ['Timeline Interval', $timelineInterval > 0 ? "{$timelineInterval}s" : 'Default'],
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
}
