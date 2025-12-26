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

#[AsCommand(
    name: 'system:queue',
    description: 'Manage KVS background tasks queue',
    aliases: ['queue']
)]
class QueueCommand extends BaseCommand
{
    /**
     * Task type definitions - maps type_id to human-readable names
     * @var array<int, string>
     */
    private const TASK_TYPES = [
        1 => 'New Video',
        2 => 'Delete Video',
        3 => 'Upload Video Format',
        4 => 'Create Video Format',
        5 => 'Delete Video Format File',
        6 => 'Delete Video Format',
        7 => 'Create Screenshot Format',
        8 => 'Create Timeline Screenshots',
        9 => 'Delete Screenshot Format',
        10 => 'New Album',
        11 => 'Delete Album',
        12 => 'Create Album Format',
        13 => 'Delete Album Format',
        14 => 'Upload Album Images',
        15 => 'Change Storage (Video)',
        16 => 'Create Screenshots ZIP',
        17 => 'Delete Screenshots ZIP',
        18 => 'Create Images ZIP',
        19 => 'Delete Images ZIP',
        22 => 'Album Images Manipulation',
        23 => 'Change Storage (Album)',
        24 => 'Create Overview Screenshots',
        26 => 'Update Resolution Type',
        27 => 'Sync Storage Server',
        28 => 'Delete Overview Screenshots',
        29 => 'Recreate Screenshot Formats',
        30 => 'Recreate Album Formats',
        31 => 'Recreate Player Preview',
        50 => 'Videos Import',
        51 => 'Albums Import',
        52 => 'Videos Mass Edit',
        53 => 'Albums Mass Edit',
    ];

    /**
     * Error code definitions
     * @var array<int, string>
     */
    private const ERROR_CODES = [
        1 => 'General Failure',
        2 => 'Download Failed',
        3 => 'Conversion Failed',
        4 => 'Upload Failed',
        5 => 'File System Error',
        6 => 'Format Error',
        7 => 'Manual Cancellation',
        8 => 'Plugin Error',
        9 => 'Server Error',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (list|show|stats|history)')
            ->addArgument('id', InputArgument::OPTIONAL, 'Task ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (pending|processing|failed)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by task type ID')
            ->addOption('video', null, InputOption::VALUE_REQUIRED, 'Filter by video ID')
            ->addOption('album', null, InputOption::VALUE_REQUIRED, 'Filter by album ID')
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Filter by conversion server ID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results to show', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count', 'table')
            ->setHelp(<<<'HELP'
Manage KVS background tasks queue (video/album conversion, processing, etc.).

<fg=yellow>ACTIONS:</>
  list     List active tasks in queue (default)
  show     Show details for a specific task
  stats    Show queue statistics
  history  Show completed/deleted tasks history

<fg=yellow>STATUS VALUES:</>
  pending     Tasks waiting to be processed (status_id=0)
  processing  Tasks currently being processed (status_id=1)
  failed      Tasks that failed with error (status_id=2)

<fg=yellow>COMMON TASK TYPES:</>
  1   New Video (full conversion)
  2   Delete Video
  4   Create Video Format
  8   Create Timeline Screenshots
  10  New Album
  11  Delete Album
  14  Upload Album Images

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs queue list</>                         List all active tasks
  <fg=green>kvs queue list --status=pending</>        List pending tasks
  <fg=green>kvs queue list --status=failed</>         List failed tasks
  <fg=green>kvs queue list --type=1</>                List new video tasks
  <fg=green>kvs queue show 123</>                     Show task #123 details
  <fg=green>kvs queue stats</>                        Show queue statistics
  <fg=green>kvs queue history --limit=50</>           Show last 50 completed tasks
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgumentOrDefault($input, 'action', 'list');

        return match ($action) {
            'list' => $this->listTasks($input),
            'show' => $this->showTask($this->getStringArgument($input, 'id')),
            'stats' => $this->showStats(),
            'history' => $this->showHistory($input),
            default => $this->showHelp(),
        };
    }

    private function listTasks(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $query = "SELECT bt.*,
                  cs.title as server_name
                  FROM {$this->table('background_tasks')} bt
                  LEFT JOIN {$this->table('admin_conversion_servers')} cs
                      ON bt.server_id = cs.server_id
                  WHERE 1=1";

        $params = [];

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusMap = ['pending' => 0, 'processing' => 1, 'failed' => 2];
            if (isset($statusMap[$status])) {
                $query .= " AND bt.status_id = :status";
                $params['status'] = $statusMap[$status];
            }
        }

        $type = $this->getIntOption($input, 'type');
        if ($type !== null) {
            $query .= " AND bt.type_id = :type";
            $params['type'] = $type;
        }

        $videoId = $this->getIntOption($input, 'video');
        if ($videoId !== null) {
            $query .= " AND bt.video_id = :video_id";
            $params['video_id'] = $videoId;
        }

        $albumId = $this->getIntOption($input, 'album');
        if ($albumId !== null) {
            $query .= " AND bt.album_id = :album_id";
            $params['album_id'] = $albumId;
        }

        $serverId = $this->getIntOption($input, 'server');
        if ($serverId !== null) {
            $query .= " AND bt.server_id = :server_id";
            $params['server_id'] = $serverId;
        }

        $query .= " ORDER BY bt.priority DESC, bt.added_date ASC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $tasks */
            $tasks = $stmt->fetchAll();

            if ($tasks === []) {
                $this->io()->success('Queue is empty - no tasks found');
                return self::SUCCESS;
            }

            // Transform data for display
            $tasks = array_values(array_map(function (array $task): array {
                $task['id'] = $task['task_id'];
                $task['status'] = StatusFormatter::task((int)$task['status_id'], false);
                $typeId = (int)$task['type_id'];
                $task['type'] = self::TASK_TYPES[$typeId] ?? "Type #{$typeId}";
                $videoId = (int)($task['video_id'] ?? 0);
                $albumId = (int)($task['album_id'] ?? 0);
                $task['content_id'] = $videoId > 0
                    ? "Video #{$videoId}"
                    : ($albumId > 0 ? "Album #{$albumId}" : '-');
                $serverName = $task['server_name'] ?? null;
                $serverId = (int)($task['server_id'] ?? 0);
                $task['server'] = is_string($serverName) ? $serverName : ($serverId > 0 ? "Server #{$serverId}" : '-');
                $errorCode = (int)($task['error_code'] ?? 0);
                $task['error'] = $errorCode > 0
                    ? (self::ERROR_CODES[$errorCode] ?? "Error #{$errorCode}")
                    : '';
                return $task;
            }, $tasks));

            // Format and display
            $format = $this->getStringOption($input, 'format') ?? 'table';

            if ($format === 'table') {
                $this->io()->title('Background Tasks Queue');
                /** @var list<list<string|int|null>> $rows */
                $rows = [];
                foreach ($tasks as $task) {
                    $rows[] = [
                        (int)$task['task_id'],
                        StatusFormatter::task((int)$task['status_id']),
                        (string)$task['type'],
                        (string)$task['content_id'],
                        (int)$task['priority'],
                        (string)$task['server'],
                        (string)$task['error'],
                    ];
                }
                $this->renderTable(['ID', 'Status', 'Type', 'Content', 'Priority', 'Server', 'Error'], $rows);
                $this->io()->text(sprintf('Showing %d tasks', count($tasks)));
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

    private function showTask(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Task ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Try active tasks first
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

            $isHistory = false;
            if ($task === false) {
                // Try history table
                $stmt = $db->prepare("
                    SELECT * FROM {$this->table('background_tasks_history')}
                    WHERE task_id = :id
                ");
                $stmt->execute(['id' => $id]);
                /** @var array<string, mixed>|false $task */
                $task = $stmt->fetch();
                $isHistory = true;
            }

            if ($task === false) {
                $this->io()->error("Task not found: $id");
                return self::FAILURE;
            }

            $this->io()->title("Task #$id" . ($isHistory ? ' (History)' : ''));

            $statusId = (int)$task['status_id'];
            $typeId = (int)$task['type_id'];
            $priority = (int)$task['priority'];
            $videoId = (int)($task['video_id'] ?? 0);
            $albumId = (int)($task['album_id'] ?? 0);
            $serverId = (int)($task['server_id'] ?? 0);
            $serverName = $task['server_name'] ?? null;
            $errorCode = (int)($task['error_code'] ?? 0);
            $timesRestarted = (int)($task['times_restarted'] ?? 0);
            $addedDate = (string)($task['added_date'] ?? '');
            $startDate = (string)($task['start_date'] ?? '');
            $message = (string)($task['message'] ?? '');
            $data = $task['data'] ?? null;

            /** @var list<list<string|int|null>> $info */
            $info = [
                ['Status', StatusFormatter::task($statusId)],
                ['Type', self::TASK_TYPES[$typeId] ?? "Type #{$typeId}"],
                ['Priority', (string)$priority],
            ];

            if ($videoId > 0) {
                $info[] = ['Video ID', (string)$videoId];
            }
            if ($albumId > 0) {
                $info[] = ['Album ID', (string)$albumId];
            }

            $serverDisplay = is_string($serverName) ? $serverName : ($serverId > 0 ? "Server #{$serverId}" : 'None');
            $info[] = ['Server', $serverDisplay];

            if ($errorCode > 0) {
                $errorText = self::ERROR_CODES[$errorCode] ?? "Error #{$errorCode}";
                $info[] = ['Error Code', "<fg=red>{$errorText}</>"];
            }

            if ($message !== '') {
                $info[] = ['Message', $message];
            }

            $info[] = ['Restarts', (string)$timesRestarted];
            $info[] = ['Added', $addedDate];

            if ($startDate !== '' && $startDate !== '0000-00-00 00:00:00') {
                $info[] = ['Started', $startDate];
            }

            if ($isHistory) {
                $endDate = (string)($task['end_date'] ?? '');
                $effectiveDuration = (int)($task['effective_duration'] ?? 0);
                if ($endDate !== '') {
                    $info[] = ['Ended', $endDate];
                }
                if ($effectiveDuration > 0) {
                    $info[] = ['Duration', $this->formatDuration($effectiveDuration)];
                }
            }

            $this->renderTable(['Property', 'Value'], $info);

            // Show task data if present
            if ($data !== null && $data !== '') {
                $this->io()->section('Task Data');
                $unserialized = @unserialize((string)$data);
                if ($unserialized !== false) {
                    $this->io()->text(print_r($unserialized, true));
                } else {
                    $this->io()->text((string)$data);
                }
            }

            // Try to show progress for active processing tasks
            if (!$isHistory && $statusId === StatusFormatter::TASK_PROCESSING) {
                $progressFile = $this->config->getKvsPath() . '/admin/data/engine/tasks/' . $id . '.dat';
                if (file_exists($progressFile)) {
                    $progress = @file_get_contents($progressFile);
                    if ($progress !== false && $progress !== '') {
                        $this->io()->section('Progress');
                        $this->io()->text("Progress: {$progress}%");
                    }
                }
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch task: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showStats(): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $this->io()->title('Queue Statistics');

            // Status counts
            $stmt = $db->query("
                SELECT status_id, COUNT(*) as count
                FROM {$this->table('background_tasks')}
                GROUP BY status_id
            ");
            /** @var array<int, int> $statusCounts */
            $statusCounts = [];
            if ($stmt !== false) {
                while ($row = $stmt->fetch()) {
                    if (is_array($row)) {
                        $statusCounts[(int)$row['status_id']] = (int)$row['count'];
                    }
                }
            }

            $this->io()->section('Queue Status');
            /** @var list<list<string|int|null>> $rows */
            $rows = [
                [StatusFormatter::task(0), number_format($statusCounts[0] ?? 0)],
                [StatusFormatter::task(1), number_format($statusCounts[1] ?? 0)],
                [StatusFormatter::task(2), number_format($statusCounts[2] ?? 0)],
                ['<fg=white>Total</>', number_format(array_sum($statusCounts))],
            ];
            $this->renderTable(['Status', 'Count'], $rows);

            // Type breakdown (top 10)
            $stmt = $db->query("
                SELECT type_id, COUNT(*) as count
                FROM {$this->table('background_tasks')}
                GROUP BY type_id
                ORDER BY count DESC
                LIMIT 10
            ");

            if ($stmt !== false) {
                /** @var list<array<string, mixed>> $types */
                $types = $stmt->fetchAll();
                if ($types !== []) {
                    $this->io()->section('Tasks by Type (Top 10)');
                    /** @var list<list<string|int|null>> $rows */
                    $rows = [];
                    foreach ($types as $type) {
                        $typeId = (int)$type['type_id'];
                        $typeName = self::TASK_TYPES[$typeId] ?? "Type #{$typeId}";
                        $rows[] = [$typeId, $typeName, number_format((int)$type['count'])];
                    }
                    $this->renderTable(['ID', 'Type', 'Count'], $rows);
                }
            }

            // Error breakdown
            $stmt = $db->query("
                SELECT error_code, COUNT(*) as count
                FROM {$this->table('background_tasks')}
                WHERE status_id = 2 AND error_code > 0
                GROUP BY error_code
                ORDER BY count DESC
            ");

            if ($stmt !== false) {
                /** @var list<array<string, mixed>> $errors */
                $errors = $stmt->fetchAll();
                if ($errors !== []) {
                    $this->io()->section('Failed Tasks by Error');
                    /** @var list<list<string|int|null>> $rows */
                    $rows = [];
                    foreach ($errors as $error) {
                        $errorCode = (int)$error['error_code'];
                        $errorName = self::ERROR_CODES[$errorCode] ?? "Error #{$errorCode}";
                        $rows[] = [$errorCode, $errorName, number_format((int)$error['count'])];
                    }
                    $this->renderTable(['Code', 'Error', 'Count'], $rows);
                }
            }

            // Recent history stats (last 24h)
            $stmt = $db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status_id = 3 THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status_id = 4 THEN 1 ELSE 0 END) as deleted,
                    AVG(effective_duration) as avg_duration
                FROM {$this->table('background_tasks_history')}
                WHERE end_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");

            if ($stmt !== false) {
                /** @var array<string, mixed>|false $history */
                $history = $stmt->fetch();
                if (is_array($history) && (int)($history['total'] ?? 0) > 0) {
                    $this->io()->section('Last 24 Hours');
                    /** @var list<list<string|int|null>> $rows */
                    $rows = [
                        ['Completed', number_format((int)($history['completed'] ?? 0))],
                        ['Deleted', number_format((int)($history['deleted'] ?? 0))],
                        ['Avg Duration', $this->formatDuration((int)($history['avg_duration'] ?? 0))],
                    ];
                    $this->renderTable(['Metric', 'Value'], $rows);
                }
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch statistics: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showHistory(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $query = "SELECT * FROM {$this->table('background_tasks_history')} WHERE 1=1";
        $params = [];

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusMap = ['completed' => 3, 'deleted' => 4];
            if (isset($statusMap[$status])) {
                $query .= " AND status_id = :status";
                $params['status'] = $statusMap[$status];
            }
        }

        $type = $this->getIntOption($input, 'type');
        if ($type !== null) {
            $query .= " AND type_id = :type";
            $params['type'] = $type;
        }

        $videoId = $this->getIntOption($input, 'video');
        if ($videoId !== null) {
            $query .= " AND video_id = :video_id";
            $params['video_id'] = $videoId;
        }

        $query .= " ORDER BY end_date DESC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getIntOptionOrDefault($input, 'limit', Constants::DEFAULT_CONTENT_LIMIT);
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $tasks */
            $tasks = $stmt->fetchAll();

            if ($tasks === []) {
                $this->io()->info('No history found');
                return self::SUCCESS;
            }

            // Transform for display
            $tasks = array_values(array_map(function (array $task): array {
                $task['id'] = $task['task_id'];
                $statusId = (int)$task['status_id'];
                $task['status'] = $statusId === 3 ? 'Completed' : 'Deleted';
                $typeId = (int)$task['type_id'];
                $task['type'] = self::TASK_TYPES[$typeId] ?? "Type #{$typeId}";
                $videoId = (int)($task['video_id'] ?? 0);
                $albumId = (int)($task['album_id'] ?? 0);
                $task['content_id'] = $videoId > 0
                    ? "Video #{$videoId}"
                    : ($albumId > 0 ? "Album #{$albumId}" : '-');
                $task['duration'] = $this->formatDuration((int)($task['effective_duration'] ?? 0));
                return $task;
            }, $tasks));

            $format = $this->getStringOption($input, 'format') ?? 'table';

            if ($format === 'table') {
                $this->io()->title('Task History');
                /** @var list<list<string|int|null>> $rows */
                $rows = [];
                foreach ($tasks as $task) {
                    $statusId = (int)$task['status_id'];
                    $statusColor = $statusId === 3 ? 'green' : 'yellow';
                    $endDate = (string)($task['end_date'] ?? '');
                    $rows[] = [
                        (int)$task['task_id'],
                        "<fg={$statusColor}>{$task['status']}</>",
                        (string)$task['type'],
                        (string)$task['content_id'],
                        (string)$task['duration'],
                        $endDate !== '' ? date('Y-m-d H:i', strtotime($endDate)) : '-',
                    ];
                }
                $this->renderTable(['ID', 'Status', 'Type', 'Content', 'Duration', 'Ended'], $rows);
                $this->io()->text(sprintf('Showing %d tasks', count($tasks)));
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

    private function showHelp(): int
    {
        $this->io()->info('Available actions:');
        $this->io()->listing([
            'list : List active tasks in queue',
            'show <id> : Show details for a specific task',
            'stats : Show queue statistics',
            'history : Show completed/deleted tasks history',
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

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $hours = (int)floor($seconds / 3600);
        $minutes = (int)floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
        }
        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        }
        return sprintf('%ds', $secs);
    }
}
