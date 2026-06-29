<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\pluralize;

#[AsCommand(
    name: 'system:queue',
    description: 'Manage KVS background tasks queue',
    aliases: ['queue']
)]
class QueueCommand extends BaseCommand
{
    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml', 'count'];

    private const LIST_ONLY_OPTIONS = ['status', 'type', 'error-code', 'video', 'album', 'server', 'limit'];

    /**
     * Task type definitions - maps type_id to human-readable names
     * @var array<int, string>
     */
    private const TASK_TYPES = [
        1 => 'New video',
        2 => 'Video deletion',
        3 => 'Video file upload',
        4 => 'Video files creation',
        5 => 'Video files deletion',
        6 => 'Video format deletion',
        7 => 'Screenshot format creation',
        8 => 'Timeline screenshots creation',
        9 => 'Screenshot format deletion',
        10 => 'New album',
        11 => 'Album deletion',
        12 => 'Album format creation',
        13 => 'Album format deletion',
        14 => 'Album images upload',
        15 => 'Change video storage group',
        16 => 'Screenshot format ZIP creation',
        17 => 'Screenshot format ZIP deletion',
        18 => 'Album format ZIP creation',
        19 => 'Album format ZIP deletion',
        20 => 'Timeline screenshots deletion',
        22 => 'Album images manipulation',
        23 => 'Change album storage group',
        24 => 'Overview screenshots creation',
        26 => 'Quality factor update',
        27 => 'Sync storage server',
        28 => 'Overview screenshots deletion',
        29 => 'Screenshot formats re-creation',
        30 => 'Album formats re-creation',
        31 => 'Player preview re-creation',
        50 => 'Videos import',
        51 => 'Albums import',
        52 => 'Videos mass edit',
        53 => 'Albums mass edit',
    ];

    /**
     * Error code definitions
     * @var array<int, string>
     */
    private const ERROR_CODES = [
        1 => '01 - Database consistency error',
        2 => '02 - Conversion server connection error',
        3 => '03 - Unexpected error',
        4 => '04 - Storage server connection error',
        5 => '05 - Filesystem error',
        6 => '06 - Unexpected error',
        7 => '07 - Conversion error',
        8 => '08 - Screenshots error',
        9 => '09 - Source file error',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|stats|history)', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'Task ID')
            ->addOption(
                'status',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by status (scheduled|pending|in-process|processing|error|failed|completed|cancelled)'
            )
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by task type ID')
            ->addOption('error-code', null, InputOption::VALUE_REQUIRED, 'Filter by KVS task error code')
            ->addOption('video', null, InputOption::VALUE_REQUIRED, 'Filter by video ID')
            ->addOption('album', null, InputOption::VALUE_REQUIRED, 'Filter by album ID')
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Filter by conversion server ID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count', 'table')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation')
            ->setHelp(<<<'HELP'
Manage KVS background tasks queue (video/album conversion, processing, etc.).

<fg=yellow>ACTIONS:</>
  list     List active tasks in queue (default)
  show     Show details for a specific task
  stats    Show queue statistics
  history  Show completed/cancelled/failed tasks history

<fg=yellow>STATUS VALUES:</>
  pending     Scheduled tasks waiting to be processed (status_id=0)
  processing  Tasks currently in process (status_id=1)
  failed      Tasks finished with error (status_id=2)

<fg=yellow>COMMON TASK TYPES:</>
  1   New video
  2   Video deletion
  4   Video files creation
  8   Timeline screenshots creation
  10  New album
  11  Album deletion
  14  Album images upload

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs queue list</>                         List all active tasks
  <fg=green>kvs queue list --status=scheduled</>      List scheduled tasks
  <fg=green>kvs queue list --status=error</>          List failed tasks
  <fg=green>kvs queue list --type=1</>                List new video tasks
  <fg=green>kvs queue show 123</>                     Show task #123 details
  <fg=green>kvs queue stats</>                        Show queue statistics
  <fg=green>kvs queue history --limit=50</>           Show last 50 history tasks
  <fg=green>kvs queue history --album=12</>           Show history for album #12
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action') ?? 'list';

        return match ($action) {
            'list' => $this->listTasks($input),
            'show' => $this->showTask($this->getStringArgument($input, 'id'), $input),
            'stats' => $this->showStats($input),
            'history' => $this->showHistory($input),
            'help-action' => $this->showHelp(),
            default => $this->failUnknownAction(
                'queue',
                $action,
                ['list', 'show', 'stats', 'history', 'help-action']
            ),
        };
    }

    private function listTasks(InputInterface $input): int
    {
        if ($this->rejectUnsupportedArgument($input, 'list', 'id', 'a task ID argument', 'show', 'a specific task')) {
            return self::FAILURE;
        }

        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromClause = "FROM {$this->table('background_tasks')} bt
                  LEFT JOIN {$this->table('admin_conversion_servers')} cs
                      ON bt.server_id = cs.server_id
                  WHERE 1=1";

        $params = [];

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusMap = [
                'scheduled' => StatusFormatter::TASK_PENDING,
                'pending' => StatusFormatter::TASK_PENDING,
                'in-process' => StatusFormatter::TASK_PROCESSING,
                'in_process' => StatusFormatter::TASK_PROCESSING,
                'processing' => StatusFormatter::TASK_PROCESSING,
                'error' => StatusFormatter::TASK_FAILED,
                'failed' => StatusFormatter::TASK_FAILED,
                '0' => StatusFormatter::TASK_PENDING,
                '1' => StatusFormatter::TASK_PROCESSING,
                '2' => StatusFormatter::TASK_FAILED,
            ];
            $statusKey = strtolower($status);
            if (!array_key_exists($statusKey, $statusMap)) {
                $this->io()->error(
                    'Invalid status "' . $status . '". Valid values: scheduled, pending, in-process, processing, error, failed'
                );
                return self::FAILURE;
            }
            $fromClause .= " AND bt.status_id = :status";
            $params['status'] = $statusMap[$statusKey];
        }

        if (!$this->applyTaskReferenceFilters($input, $fromClause, 'bt', $params)) {
            return self::FAILURE;
        }

        if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
            if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT) === null) {
                return self::FAILURE;
            }
            return $this->countQueueTasks($db, $fromClause, $params);
        }

        $query = "SELECT bt.*,
                  cs.title as server_name,
                  cs.title as server,
                  CASE WHEN bt.video_id > 0 THEN bt.video_id WHEN bt.album_id > 0 THEN bt.album_id END as object_id,
                  CASE WHEN bt.video_id > 0 THEN bt.video_id WHEN bt.album_id > 0 THEN bt.album_id END as object,
                  CASE WHEN bt.video_id > 0 THEN 1 WHEN bt.album_id > 0 THEN 2 END as object_type_id
                  $fromClause";
        $query .= " ORDER BY bt.task_id DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $tasks */
            $tasks = $stmt->fetchAll();
            $format = $this->getStringOption($input, 'format') ?? 'table';

            if ($tasks === []) {
                if ($format === 'table') {
                    $this->io()->success('Queue is empty - no tasks found');
                } else {
                    $formatter = new Formatter(
                        $input->getOptions(),
                        ['task_id', 'status', 'type', 'content_id', 'priority', 'server', 'error']
                    );
                    $formatter->display([], $this->io());
                }
                return self::SUCCESS;
            }

            // Transform data for display
            /** @var list<array{task_id: int, status_id: int, type_id: int, video_id: int|null, album_id: int|null, server_id: int|null, server_name: string|null, error_code: int|null, priority: int, id: int, status: string, type: string, content_id: string, server: string, error: string}> $tasks */
            $tasks = array_map(function (array $task) use ($db): array {
                $statusId = is_numeric($task['status_id'] ?? null) ? (int) $task['status_id'] : 0;
                $typeId = is_numeric($task['type_id'] ?? null) ? (int) $task['type_id'] : 0;
                $videoId = is_numeric($task['video_id'] ?? null) ? (int) $task['video_id'] : 0;
                $albumId = is_numeric($task['album_id'] ?? null) ? (int) $task['album_id'] : 0;
                $serverId = is_numeric($task['server_id'] ?? null) ? (int) $task['server_id'] : 0;
                $errorCode = is_numeric($task['error_code'] ?? null) ? (int) $task['error_code'] : 0;
                $serverName = $task['server_name'] ?? null;

                $task['id'] = $task['task_id'];
                $task['status'] = StatusFormatter::task($statusId, false);
                $task['type'] = self::TASK_TYPES[$typeId] ?? "Type #{$typeId}";
                $task['content_id'] = $videoId > 0
                    ? "Video #{$videoId}"
                    : ($albumId > 0 ? "Album #{$albumId}" : '-');
                $task['server'] = is_string($serverName) ? $serverName : ($serverId > 0 ? "Server #{$serverId}" : '-');
                $task['error'] = $errorCode > 0
                    ? (self::ERROR_CODES[$errorCode] ?? "Error #{$errorCode}")
                    : '';
                return $this->hydrateTaskListAppendFields($task, $db);
            }, $tasks);

            // Format and display
            if ($format === 'table') {
                $this->io()->title('Background Tasks Queue');
                /** @var list<list<string|int|null>> $rows */
                $rows = [];
                foreach ($tasks as $task) {
                    $rows[] = [
                        $task['task_id'],
                        StatusFormatter::task($task['status_id']),
                        $task['type'],
                        $task['content_id'],
                        $task['priority'],
                        $task['server'],
                        $task['error'],
                    ];
                }
                $this->renderTable(['ID', 'Status', 'Type', 'Content', 'Priority', 'Server', 'Error'], $rows);
                $this->io()->text($this->formatTaskCount(count($tasks)));
            } else {
                $formatter = new Formatter(
                    $input->getOptions(),
                    ['task_id', 'status', 'type', 'content_id', 'priority', 'server', 'error']
                );
                $formatter->display($tasks, $this->io());
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch queue: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Hydrate append-only fields used by KVS admin background_tasks grid.
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function hydrateTaskListAppendFields(array $task, \PDO $db): array
    {
        $task['format_postfix'] = '';
        $task['format_size'] = '';
        $task['pc_complete'] = '';
        $task['is_error'] = $this->scalarToPositiveInt($task['status_id'] ?? null) === StatusFormatter::TASK_FAILED ? 1 : 0;

        $taskData = $this->unserializeTaskData($task['data'] ?? null);
        if ($taskData !== []) {
            $formatGroupId = $this->scalarToPositiveInt($taskData['new_format_video_group_id'] ?? null);
            if ($formatGroupId !== null) {
                $task['format_postfix'] = $this->fetchFormatVideoGroupTitle($db, $formatGroupId);
            } elseif (isset($taskData['format_postfix']) && is_scalar($taskData['format_postfix'])) {
                $task['format_postfix'] = (string) $taskData['format_postfix'];
            }

            if (isset($taskData['format_size']) && is_scalar($taskData['format_size'])) {
                $task['format_size'] = (string) $taskData['format_size'];
            }
        }

        $taskId = $this->scalarToPositiveInt($task['task_id'] ?? null);
        if ($taskId !== null) {
            $task['pc_complete'] = $this->readTaskProgressCompletion($taskId);
        }

        return $task;
    }

    /**
     * @return array<string, mixed>
     */
    private function unserializeTaskData(mixed $data): array
    {
        if (!is_string($data) || $data === '') {
            return [];
        }

        $taskData = @unserialize($data, ['allowed_classes' => false]);
        if (!is_array($taskData)) {
            return [];
        }

        $normalized = [];
        foreach ($taskData as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function fetchFormatVideoGroupTitle(\PDO $db, int $formatGroupId): string
    {
        $stmt = $db->prepare("
            SELECT title
            FROM {$this->table('formats_videos_groups')}
            WHERE format_video_group_id = :format_group_id
        ");
        $stmt->execute(['format_group_id' => $formatGroupId]);

        $title = $stmt->fetchColumn();
        return is_scalar($title) ? (string) $title : '';
    }

    private function readTaskProgressCompletion(int $taskId): string
    {
        $progressFile = $this->config->getKvsPath() . '/admin/data/engine/tasks/' . $taskId . '.dat';
        if (!is_file($progressFile)) {
            return '';
        }

        $progress = @file_get_contents($progressFile);
        if ($progress === false || $progress === '') {
            return '';
        }

        return ((int) $progress) . '%';
    }

    private function scalarToPositiveInt(mixed $value): ?int
    {
        if (!is_scalar($value) || !is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;
        return $intValue > 0 ? $intValue : null;
    }

    private function showTask(?string $id, InputInterface $input): int
    {
        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptions($input, 'show', self::LIST_ONLY_OPTIONS)) {
            return self::FAILURE;
        }

        $taskId = $this->getRequiredPositiveId($id, 'Task');
        if ($taskId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $result = $this->fetchTask($db, $taskId);
            if ($result === null) {
                $this->io()->error("Task not found: $taskId");
                return self::FAILURE;
            }

            [$task, $isHistory] = $result;
            $info = $this->buildTaskInfo($task, $isHistory);

            if (!$this->isTableFormat($input)) {
                $extra = [
                    'task_id' => (string) $taskId,
                    'is_history' => $isHistory,
                ];
                $data = $task['data'] ?? null;
                if (is_string($data) && $data !== '') {
                    $unserialized = @unserialize($data, ['allowed_classes' => false]);
                    $extra['data'] = $unserialized !== false ? $unserialized : $data;
                }

                return $this->displayDetailRows($input, $info, $extra);
            }

            $this->io()->title("Task #$taskId" . ($isHistory ? ' (History)' : ''));
            $this->renderTable(['Property', 'Value'], $info);

            $this->displayTaskData($task);
            $this->displayTaskProgress($task, (string) $taskId, $isHistory);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch task: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Fetch task from active or history table
     * @return array{0: array<string, mixed>, 1: bool}|null
     */
    private function fetchTask(\PDO $db, int $id): ?array
    {
        $stmt = $db->prepare("
            SELECT bt.*, cs.title as server_name
            FROM {$this->table('background_tasks')} bt
            LEFT JOIN {$this->table('admin_conversion_servers')} cs
                ON bt.server_id = cs.server_id
            WHERE bt.task_id = :id
        ");
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $task */
        $task = $stmt->fetch();

        if ($task !== false) {
            return [$task, false];
        }

        $stmt = $db->prepare("
            SELECT bh.*, cs.title as server_name
            FROM {$this->table('background_tasks_history')} bh
            LEFT JOIN {$this->table('admin_conversion_servers')} cs
                ON bh.server_id = cs.server_id
            WHERE bh.task_id = :id
        ");
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $task */
        $task = $stmt->fetch();

        return $task !== false ? [$task, true] : null;
    }

    /**
     * Build task info array for display
     * @param array<string, mixed> $task
     * @return list<list<string|int|null>>
     */
    private function buildTaskInfo(array $task, bool $isHistory): array
    {
        $statusId = is_numeric($task['status_id'] ?? null) ? (int) $task['status_id'] : 0;
        $typeId = is_numeric($task['type_id'] ?? null) ? (int) $task['type_id'] : 0;
        $videoId = is_numeric($task['video_id'] ?? null) ? (int) $task['video_id'] : 0;
        $albumId = is_numeric($task['album_id'] ?? null) ? (int) $task['album_id'] : 0;
        $serverId = is_numeric($task['server_id'] ?? null) ? (int) $task['server_id'] : 0;
        $serverName = $task['server_name'] ?? null;
        $errorCode = is_numeric($task['error_code'] ?? null) ? (int) $task['error_code'] : 0;
        $priority = is_numeric($task['priority'] ?? null) ? (int) $task['priority'] : 0;

        $info = [
            ['Status', StatusFormatter::task($statusId)],
            ['Type', self::TASK_TYPES[$typeId] ?? "Type #{$typeId}"],
            ['Priority', (string) $priority],
        ];

        if ($videoId > 0) {
            $info[] = ['Video ID', (string) $videoId];
        }
        if ($albumId > 0) {
            $info[] = ['Album ID', (string) $albumId];
        }

        $serverDisplay = is_string($serverName) ? $serverName : ($serverId > 0 ? "Server #{$serverId}" : 'None');
        $info[] = ['Server', $serverDisplay];

        if ($errorCode > 0) {
            $errorText = self::ERROR_CODES[$errorCode] ?? "Error #{$errorCode}";
            $info[] = ['Error Code', "<fg=red>{$errorText}</>"];
        }

        $message = is_string($task['message'] ?? null) ? $task['message'] : '';
        if ($message !== '') {
            $info[] = ['Message', $message];
        }

        $this->appendTaskLifecycleInfo($info, $task, $isHistory);

        return $info;
    }

    /**
     * @param list<list<string|int|null>> $info
     * @param array<string, mixed> $task
     */
    private function appendTaskLifecycleInfo(array &$info, array $task, bool $isHistory): void
    {
        if (!$isHistory) {
            $timesRestarted = is_numeric($task['times_restarted'] ?? null)
                ? (int) $task['times_restarted']
                : 0;
            $addedDate = is_string($task['added_date'] ?? null) ? $task['added_date'] : '';
            $info[] = ['Restarts', (string) $timesRestarted];
            $info[] = ['Added', $addedDate];
        }

        $startDate = is_string($task['start_date'] ?? null) ? $task['start_date'] : '';
        if ($startDate !== '' && $startDate !== '0000-00-00 00:00:00') {
            $info[] = ['Started', $startDate];
        }

        if ($isHistory) {
            $endDate = is_string($task['end_date'] ?? null) ? $task['end_date'] : '';
            $effectiveDuration = is_numeric($task['effective_duration'] ?? null)
                ? (int) $task['effective_duration']
                : 0;
            if ($endDate !== '') {
                $info[] = ['Ended', $endDate];
            }
            if ($effectiveDuration > 0) {
                $info[] = ['Duration', $this->formatDuration($effectiveDuration)];
            }
        }
    }

    /**
     * Display serialized task data if present
     * @param array<string, mixed> $task
     */
    private function displayTaskData(array $task): void
    {
        $data = $task['data'] ?? null;
        if ($data === null || $data === '' || !is_string($data)) {
            return;
        }

        $this->io()->section('Task Data');
        $unserialized = @unserialize($data);
        if ($unserialized !== false) {
            $this->io()->text(print_r($unserialized, true));
        } else {
            $this->io()->text($data);
        }
    }

    /**
     * Display task progress for active processing tasks
     * @param array<string, mixed> $task
     */
    private function displayTaskProgress(array $task, string $id, bool $isHistory): void
    {
        $statusId = is_numeric($task['status_id'] ?? null) ? (int) $task['status_id'] : 0;
        if ($isHistory || $statusId !== StatusFormatter::TASK_PROCESSING) {
            return;
        }

        $progressFile = $this->config->getKvsPath() . '/admin/data/engine/tasks/' . $id . '.dat';
        if (!file_exists($progressFile)) {
            return;
        }

        $progress = @file_get_contents($progressFile);
        if ($progress !== false && $progress !== '') {
            $this->io()->section('Progress');
            $this->io()->text("Progress: {$progress}%");
        }
    }

    private function showStats(InputInterface $input): int
    {
        if ($this->rejectUnsupportedArgument($input, 'stats', 'id', 'a task ID argument', 'show', 'a specific task')) {
            return self::FAILURE;
        }

        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        if ($this->rejectUnsupportedOptions($input, 'stats', self::LIST_ONLY_OPTIONS)) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $statusCounts = $this->getQueueStatusCounts($db);

            /** @var list<list<string|int|null>> $statusRows */
            $statusRows = [
                [StatusFormatter::task(0), number_format($statusCounts[0] ?? 0)],
                [StatusFormatter::task(1), number_format($statusCounts[1] ?? 0)],
                [StatusFormatter::task(2), number_format($statusCounts[2] ?? 0)],
                ['<fg=white>Total</>', number_format(array_sum($statusCounts))],
            ];
            /** @var list<array<string, mixed>> $metricRows */
            $metricRows = [
                $this->metricRow('queue_status', StatusFormatter::task(0, false), $statusCounts[0] ?? 0),
                $this->metricRow('queue_status', StatusFormatter::task(1, false), $statusCounts[1] ?? 0),
                $this->metricRow('queue_status', StatusFormatter::task(2, false), $statusCounts[2] ?? 0),
                $this->metricRow('queue_status', 'Total', array_sum($statusCounts)),
            ];

            $typeRows = $this->getQueueTypeStatsRows($db, $metricRows);
            $errorRows = $this->getQueueErrorStatsRows($db, $metricRows);
            $historyRows = $this->getQueueRecentHistoryRows($db, $metricRows);

            if (!$this->isTableFormat($input)) {
                $this->displayMetricRows($input, $metricRows);
                return self::SUCCESS;
            }

            $this->io()->title('Queue Statistics');
            $this->io()->section('Queue Status');
            $this->renderTable(['Status', 'Count'], $statusRows);

            if ($typeRows !== []) {
                $this->io()->section('Tasks by Type (Top ' . Constants::TOP_QUERY_LIMIT . ')');
                $this->renderTable(['ID', 'Type', 'Count'], $typeRows);
            }

            if ($errorRows !== []) {
                $this->io()->section('Failed Tasks by Error');
                $this->renderTable(['Code', 'Error', 'Count'], $errorRows);
            }

            if ($historyRows !== []) {
                $this->io()->section('Last 24 Hours');
                $this->renderTable(['Metric', 'Value'], $historyRows);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @return array<int, int>
     */
    private function getQueueStatusCounts(\PDO $db): array
    {
        $stmt = $db->query("
            SELECT status_id, COUNT(*) as count
            FROM {$this->table('background_tasks')}
            GROUP BY status_id
        ");

        $statusCounts = [];
        if ($stmt === false) {
            return $statusCounts;
        }

        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                continue;
            }

            $statusIdVal = $row['status_id'] ?? null;
            $countVal = $row['count'] ?? null;
            if (is_numeric($statusIdVal) && is_numeric($countVal)) {
                $statusCounts[(int) $statusIdVal] = (int) $countVal;
            }
        }

        return $statusCounts;
    }

    /**
     * @param list<array<string, mixed>> $metricRows
     * @return list<list<string|int|null>>
     */
    private function getQueueTypeStatsRows(\PDO $db, array &$metricRows): array
    {
        $stmt = $db->query("
            SELECT type_id, COUNT(*) as count
            FROM {$this->table('background_tasks')}
            GROUP BY type_id
            ORDER BY count DESC
            LIMIT " . Constants::TOP_QUERY_LIMIT . "
        ");
        if ($stmt === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $types */
        $types = $stmt->fetchAll();
        $rows = [];
        foreach ($types as $type) {
            $typeId = is_numeric($type['type_id'] ?? null) ? (int) $type['type_id'] : 0;
            $count = is_numeric($type['count'] ?? null) ? (int) $type['count'] : 0;
            $typeName = self::TASK_TYPES[$typeId] ?? "Type #{$typeId}";
            $metricRows[] = $this->metricRow(
                'tasks_by_type',
                (string) $typeId,
                $count,
                number_format($count),
                $typeName
            );
            $rows[] = [$typeId, $typeName, number_format($count)];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $metricRows
     * @return list<list<string|int|null>>
     */
    private function getQueueErrorStatsRows(\PDO $db, array &$metricRows): array
    {
        $stmt = $db->query("
            SELECT error_code, COUNT(*) as count
            FROM {$this->table('background_tasks')}
            WHERE status_id = " . StatusFormatter::TASK_FAILED . " AND error_code > 0
            GROUP BY error_code
            ORDER BY count DESC
        ");
        if ($stmt === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $errors */
        $errors = $stmt->fetchAll();
        $rows = [];
        foreach ($errors as $error) {
            $errorCode = is_numeric($error['error_code'] ?? null) ? (int) $error['error_code'] : 0;
            $count = is_numeric($error['count'] ?? null) ? (int) $error['count'] : 0;
            $errorName = self::ERROR_CODES[$errorCode] ?? "Error #{$errorCode}";
            $metricRows[] = $this->metricRow(
                'failed_tasks_by_error',
                (string) $errorCode,
                $count,
                number_format($count),
                $errorName
            );
            $rows[] = [$errorCode, $errorName, number_format($count)];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $metricRows
     * @return list<list<string|int|null>>
     */
    private function getQueueRecentHistoryRows(\PDO $db, array &$metricRows): array
    {
        $recentHistoryCutoff = date('Y-m-d H:i:s', time() - (Constants::RECENT_HOURS * 3600));
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status_id = " . StatusFormatter::TASK_COMPLETED . " THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status_id = " . StatusFormatter::TASK_CANCELLED . " THEN 1 ELSE 0 END) as cancelled,
                AVG(effective_duration) as avg_duration
            FROM {$this->table('background_tasks_history')}
            WHERE end_date >= :cutoff
        ");
        $stmt->execute(['cutoff' => $recentHistoryCutoff]);

        /** @var array<string, mixed>|false $history */
        $history = $stmt->fetch();
        if (!is_array($history)) {
            return [];
        }

        $total = is_numeric($history['total'] ?? null) ? (int) $history['total'] : 0;
        if ($total <= 0) {
            return [];
        }

        $completed = is_numeric($history['completed'] ?? null) ? (int) $history['completed'] : 0;
        $cancelled = is_numeric($history['cancelled'] ?? null) ? (int) $history['cancelled'] : 0;
        $avgDuration = is_numeric($history['avg_duration'] ?? null) ? (int) $history['avg_duration'] : 0;
        $metricRows[] = $this->metricRow('last_24_hours', 'Completed', $completed, number_format($completed));
        $metricRows[] = $this->metricRow('last_24_hours', 'Cancelled', $cancelled, number_format($cancelled));
        $metricRows[] = $this->metricRow(
            'last_24_hours',
            'Avg Duration',
            $avgDuration,
            $this->formatDuration($avgDuration)
        );

        return [
            ['Completed', number_format($completed)],
            ['Cancelled', number_format($cancelled)],
            ['Avg Duration', $this->formatDuration($avgDuration)],
        ];
    }

    private function showHistory(InputInterface $input): int
    {
        if ($this->rejectUnsupportedArgument($input, 'history', 'id', 'a task ID argument', 'show', 'a specific task')) {
            return self::FAILURE;
        }

        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromClause = "FROM {$this->table('background_tasks_history')} bh
                       LEFT JOIN {$this->table('admin_conversion_servers')} cs
                           ON bh.server_id = cs.server_id
                       WHERE 1=1";
        $params = [];

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusMap = [
                'error' => StatusFormatter::TASK_FAILED,
                'failed' => StatusFormatter::TASK_FAILED,
                'completed' => StatusFormatter::TASK_COMPLETED,
                'cancelled' => StatusFormatter::TASK_CANCELLED,
                'canceled' => StatusFormatter::TASK_CANCELLED,
                'deleted' => StatusFormatter::TASK_DELETED,
                '2' => StatusFormatter::TASK_FAILED,
                '3' => StatusFormatter::TASK_COMPLETED,
                '4' => StatusFormatter::TASK_CANCELLED,
            ];
            $statusKey = strtolower($status);
            if (!array_key_exists($statusKey, $statusMap)) {
                $this->io()->error('Invalid status "' . $status . '". Valid values: error, failed, completed, cancelled');
                return self::FAILURE;
            }
            $fromClause .= " AND bh.status_id = :status";
            $params['status'] = $statusMap[$statusKey];
        }

        if (!$this->applyTaskReferenceFilters($input, $fromClause, 'bh', $params)) {
            return self::FAILURE;
        }

        if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
            if ($this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT) === null) {
                return self::FAILURE;
            }
            return $this->countQueueHistory($db, $fromClause, $params);
        }

        $query = "SELECT bh.*,
                  cs.title as server_name,
                  cs.title as server,
                  CASE WHEN bh.video_id > 0 THEN bh.video_id WHEN bh.album_id > 0 THEN bh.album_id END as object_id,
                  CASE WHEN bh.video_id > 0 THEN bh.video_id WHEN bh.album_id > 0 THEN bh.album_id END as object,
                  CASE WHEN bh.video_id > 0 THEN 1 WHEN bh.album_id > 0 THEN 2 END as object_type_id
                  $fromClause";
        $query .= " ORDER BY bh.task_id DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            if ($limit === null) {
                return self::FAILURE;
            }
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $tasks */
            $tasks = $stmt->fetchAll();
            $format = $this->getStringOption($input, 'format') ?? 'table';

            if ($tasks === []) {
                if ($format === 'table') {
                    $this->io()->info('No history found');
                } else {
                    $formatter = new Formatter(
                        $input->getOptions(),
                        ['task_id', 'status', 'type', 'content_id', 'duration', 'end_date']
                    );
                    $formatter->display([], $this->io());
                }
                return self::SUCCESS;
            }

            $tasks = array_map(fn (array $task): array => $this->transformHistoryTask($task), $tasks);

            if ($format === 'table') {
                $this->io()->title('Task History');
                /** @var list<list<string|int|null>> $rows */
                $rows = [];
                foreach ($tasks as $task) {
                    $taskIdValue = $task['task_id'] ?? null;
                    $taskId = is_numeric($taskIdValue) ? (int) $taskIdValue : null;
                    $statusId = is_numeric($task['status_id'] ?? null) ? (int) $task['status_id'] : 0;
                    $statusColor = match ($statusId) {
                        StatusFormatter::TASK_COMPLETED => 'green',
                        StatusFormatter::TASK_FAILED => 'red',
                        StatusFormatter::TASK_CANCELLED => 'gray',
                        default => 'yellow',
                    };
                    $endDateValue = $task['end_date'] ?? '';
                    $endDate = is_string($endDateValue) ? $endDateValue : '';
                    $timestamp = $endDate !== '' ? strtotime($endDate) : false;
                    $endDateStr = $timestamp !== false ? date('Y-m-d H:i', $timestamp) : '-';
                    $status = is_scalar($task['status'] ?? null) ? (string) $task['status'] : '';
                    $type = is_scalar($task['type'] ?? null) ? (string) $task['type'] : '';
                    $contentId = is_scalar($task['content_id'] ?? null) ? (string) $task['content_id'] : '';
                    $duration = is_scalar($task['duration'] ?? null) ? (string) $task['duration'] : '';

                    $rows[] = [
                        $taskId,
                        "<fg={$statusColor}>{$status}</>",
                        $type,
                        $contentId,
                        $duration,
                        $endDateStr,
                    ];
                }
                $this->renderTable(['ID', 'Status', 'Type', 'Content', 'Duration', 'Ended'], $rows);
                $this->io()->text($this->formatTaskCount(count($tasks)));
            } else {
                $formatter = new Formatter(
                    $input->getOptions(),
                    ['task_id', 'status', 'type', 'content_id', 'duration', 'end_date']
                );
                $formatter->display($tasks, $this->io());
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch history: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function transformHistoryTask(array $task): array
    {
        $statusId = is_numeric($task['status_id'] ?? null) ? (int) $task['status_id'] : 0;
        $typeId = is_numeric($task['type_id'] ?? null) ? (int) $task['type_id'] : 0;
        $videoId = is_numeric($task['video_id'] ?? null) ? (int) $task['video_id'] : 0;
        $albumId = is_numeric($task['album_id'] ?? null) ? (int) $task['album_id'] : 0;
        $effectiveDuration = is_numeric($task['effective_duration'] ?? null) ? (int) $task['effective_duration'] : 0;
        $serverId = is_numeric($task['server_id'] ?? null) ? (int) $task['server_id'] : 0;
        $serverName = $task['server_name'] ?? null;
        $errorCode = is_numeric($task['error_code'] ?? null) ? (int) $task['error_code'] : 0;

        $task['id'] = $task['task_id'];
        $task['status_id'] = $statusId;
        $task['status'] = StatusFormatter::task($statusId, false);
        $task['type'] = self::TASK_TYPES[$typeId] ?? "Type #{$typeId}";
        $task['content_id'] = $videoId > 0 ? "Video #{$videoId}" : ($albumId > 0 ? "Album #{$albumId}" : '-');
        $task['server'] = is_string($serverName) ? $serverName : ($serverId > 0 ? "Server #{$serverId}" : '-');
        $task['error_code'] = $errorCode;
        $task['error'] = $errorCode > 0 ? (self::ERROR_CODES[$errorCode] ?? "Error #{$errorCode}") : '';
        $duration = $this->formatDuration($effectiveDuration);
        $task['effective_duration_seconds'] = $effectiveDuration;
        $task['effective_duration'] = $duration;
        $task['duration'] = $duration;

        return $task;
    }

    private function showHelp(): int
    {
        $this->io()->info('Available actions:');
        $this->io()->listing([
            'list : List active tasks in queue',
            'show <id> : Show details for a specific task',
            'stats : Show queue statistics',
            'history : Show completed/cancelled/failed tasks history',
        ]);

        $this->io()->section('Examples');
        $this->io()->text([
            'kvs queue list',
            'kvs queue list --status=failed',
            'kvs queue show 123',
            'kvs queue stats',
            'kvs queue history --limit=50',
        ]);

        return self::SUCCESS;
    }

    /**
     * @param array<string, int> $params
     */
    private function applyTaskReferenceFilters(
        InputInterface $input,
        string &$fromClause,
        string $alias,
        array &$params
    ): bool {
        $errorCode = $this->getOptionalPositiveIntOption($input, 'error-code');
        if ($errorCode === false) {
            return false;
        }
        if ($errorCode !== null) {
            $fromClause .= sprintf(' AND %s.error_code = :error_code', $alias);
            $params['error_code'] = $errorCode;
        }

        $filters = [
            'type' => ['column' => 'type_id', 'param' => 'type'],
            'video' => ['column' => 'video_id', 'param' => 'video_id'],
            'album' => ['column' => 'album_id', 'param' => 'album_id'],
            'server' => ['column' => 'server_id', 'param' => 'server_id'],
        ];

        foreach ($filters as $option => $filter) {
            $value = $this->getOptionalNonNegativeIntOption($input, $option);
            if ($value === false) {
                return false;
            }
            if ($value !== null) {
                $fromClause .= sprintf(' AND %s.%s = :%s', $alias, $filter['column'], $filter['param']);
                $params[$filter['param']] = $value;
            }
        }

        return true;
    }

    /**
     * @param array<string, int> $params
     */
    private function countQueueTasks(\PDO $db, string $fromClause, array $params): int
    {
        return $this->countRows($db, "SELECT COUNT(*) $fromClause", $params, 'queue tasks');
    }

    /**
     * @param array<string, int> $params
     */
    private function countQueueHistory(\PDO $db, string $fromClause, array $params): int
    {
        return $this->countRows($db, "SELECT COUNT(*) $fromClause", $params, 'queue history');
    }

    /**
     * @param array<string, int> $params
     */
    private function countRows(\PDO $db, string $query, array $params, string $label): int
    {
        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();

            $this->io()->writeln((string) (int) $stmt->fetchColumn());
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to count ' . $label . ': ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0:00';
        }

        $hours = (int)floor($seconds / 3600);
        $minutes = (int)floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }

    private function formatTaskCount(int $count): string
    {
        return sprintf('Showing %d %s', $count, pluralize('task', $count));
    }
}
