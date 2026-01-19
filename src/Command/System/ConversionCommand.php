<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ToggleStatusTrait;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system:conversion',
    description: 'Manage KVS conversion servers',
    aliases: ['conversion']
)]
class ConversionCommand extends BaseCommand
{
    use ToggleStatusTrait;

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: list|show|enable|disable|debug-on|debug-off|stats'
            )
            ->addArgument('id', InputArgument::OPTIONAL, 'Server ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled|init)')
            ->addOption('errors', null, InputOption::VALUE_NONE, 'Show only servers with errors')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results', 50)
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: table, csv, json, yaml, count',
                'table'
            )
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation')
            ->setHelp(<<<'HELP'
Manage KVS conversion servers (video/image transcoding).

<fg=yellow>ACTIONS:</>
  list           List all conversion servers (default)
  show <id>      Show server details
  enable <id>    Enable/activate a server
  disable <id>   Disable/deactivate a server
  debug-on <id>  Enable debug mode
  debug-off <id> Disable debug mode
  stats          Show conversion statistics

<fg=yellow>STATUS VALUES:</>
  0=Disabled, 1=Active, 2=Initializing

<fg=yellow>PRIORITY LEVELS:</>
  0=Realtime, 4=High, 9=Medium, 14=Low, 19=Very Low

<fg=yellow>ERROR CODES:</>
  1=Write error, 2=Heartbeat, 3=Heartbeat timeout
  4=Library path, 5=API version, 6=Locked too long

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs conversion list</>
  <fg=green>kvs conversion list --status=active</>
  <fg=green>kvs conversion list --errors</>
  <fg=green>kvs conversion show 1</>
  <fg=green>kvs conversion enable 1</>
  <fg=green>kvs conversion disable 1</>
  <fg=green>kvs conversion debug-on 1</>
  <fg=green>kvs conversion debug-off 1</>
  <fg=green>kvs conversion stats</>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listServers($input),
            'show' => $this->showServer($id),
            'enable', 'activate' => $this->enableServer($id),
            'disable', 'deactivate' => $this->disableServer($id),
            'debug-on' => $this->toggleDebug($id, true),
            'debug-off' => $this->toggleDebug($id, false),
            'stats' => $this->showStats(),
            default => $this->listServers($input),
        };
    }

    private function listServers(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $query = "SELECT s.*,
                    (SELECT COUNT(*) FROM {$this->table('background_tasks')}
                     WHERE status_id IN (0,1) AND server_id = s.server_id) as tasks_pending,
                    (SELECT COUNT(*) FROM {$this->table('background_tasks_history')}
                     WHERE server_id = s.server_id) as tasks_completed
                 FROM {$this->table('admin_conversion_servers')} s
                 WHERE 1=1";

        $params = [];

        // Status filter
        $status = $input->getOption('status');
        if (is_string($status) && $status !== '') {
            $statusId = match (strtolower($status)) {
                'active', '1' => 1,
                'disabled', 'inactive', '0' => 0,
                'init', 'initializing', '2' => 2,
                default => null,
            };
            if ($statusId !== null) {
                $query .= " AND s.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        // Errors filter
        if ($input->getOption('errors')) {
            $query .= " AND s.error_iteration > 1";
        }

        $query .= " ORDER BY s.server_id ASC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getIntOptionOrDefault($input, 'limit', 50);
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $servers */
            $servers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $transformed = array_map(function (array $server): array {
                $statusId = $this->getNumericField($server, 'status_id');
                $priority = $this->getNumericField($server, 'process_priority');
                $totalSpace = $this->getNumericField($server, 'total_space');
                $freeSpace = $this->getNumericField($server, 'free_space');
                $load = $this->getFloatField($server, 'load');
                $errorIter = $this->getNumericField($server, 'error_iteration');
                $isDebug = $this->getNumericField($server, 'is_debug_enabled');

                return [
                    'server_id' => $server['server_id'] ?? 0,
                    'id' => $server['server_id'] ?? 0,
                    'title' => $server['title'] ?? '',
                    'status_id' => $statusId,
                    'status' => StatusFormatter::conversion($statusId, false),
                    'priority' => StatusFormatter::conversionPriority($priority, false),
                    'max_tasks' => $server['max_tasks'] ?? 0,
                    'tasks_pending' => $server['tasks_pending'] ?? 0,
                    'tasks_completed' => $server['tasks_completed'] ?? 0,
                    'free_space' => $this->formatBytes($freeSpace),
                    'load' => number_format($load, 2),
                    'api_version' => $server['api_version'] ?? '',
                    'heartbeat' => $this->formatHeartbeat($this->getStringField($server, 'heartbeat_date')),
                    'has_error' => $errorIter > 1 ? 'Yes' : 'No',
                    'debug' => $isDebug === 1 ? 'On' : 'Off',
                ];
            }, $servers);

            $defaultFields = [
                'server_id', 'title', 'status', 'priority', 'max_tasks',
                'tasks_pending', 'free_space', 'load', 'heartbeat'
            ];
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($transformed, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch conversion servers: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showServer(?string $id): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Server ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("
                SELECT s.*,
                    (SELECT COUNT(*) FROM {$this->table('background_tasks')}
                     WHERE status_id IN (0,1) AND server_id = s.server_id) as tasks_pending,
                    (SELECT COUNT(*) FROM {$this->table('background_tasks_history')}
                     WHERE server_id = s.server_id) as tasks_completed
                FROM {$this->table('admin_conversion_servers')} s
                WHERE s.server_id = :id
            ");
            $stmt->execute(['id' => $id]);
            /** @var array<string, mixed>|false $server */
            $server = $stmt->fetch();

            if ($server === false) {
                $this->io()->error("Conversion server not found: $id");
                return self::FAILURE;
            }

            $this->io()->section("Conversion Server #$id");

            $info = $this->buildServerInfo($server);
            $info = array_merge($info, $this->buildConnectionInfo($server));

            $this->renderTable(['Property', 'Value'], $info);
            $this->displayServerErrors($server);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch server: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Build basic server info array.
     *
     * @param array<string, mixed> $server
     * @return list<array{0: string, 1: string}>
     */
    private function buildServerInfo(array $server): array
    {
        $statusId = $this->getNumericField($server, 'status_id');
        $priority = $this->getNumericField($server, 'process_priority');
        $connType = $this->getNumericField($server, 'connection_type_id');
        $totalSpace = $this->getNumericField($server, 'total_space');
        $freeSpace = $this->getNumericField($server, 'free_space');
        $load = $this->getFloatField($server, 'load');
        $maxTasks = $this->getNumericField($server, 'max_tasks');
        $tasksPending = $this->getNumericField($server, 'tasks_pending');
        $tasksCompleted = $this->getNumericField($server, 'tasks_completed');
        $isDebug = $this->getNumericField($server, 'is_debug_enabled') === 1;

        $freePercent = $totalSpace > 0 ? round(($freeSpace / $totalSpace) * 100, 1) : 0;
        $title = $this->getStringField($server, 'title');
        $apiVersion = $this->getStringField($server, 'api_version');
        $heartbeat = $this->getStringField($server, 'heartbeat_date');
        $addedDate = $this->getStringField($server, 'added_date');

        $connTypes = [0 => 'Local', 1 => 'Mount', 2 => 'FTP'];
        $connTypeStr = $connTypes[$connType] ?? 'Unknown';

        $info = [
            ['Title', $title],
            ['Status', StatusFormatter::conversion($statusId)],
            ['Priority', StatusFormatter::conversionPriority($priority)],
            ['Connection', $connTypeStr],
            ['Max Tasks', (string) $maxTasks],
            ['Tasks Pending', (string) $tasksPending],
            ['Tasks Completed', number_format($tasksCompleted)],
            ['Total Space', $this->formatBytes($totalSpace)],
            ['Free Space', $this->formatBytes($freeSpace) . " ({$freePercent}%)"],
            ['Load', number_format($load, 2)],
            ['API Version', $apiVersion],
            ['Last Heartbeat', $this->formatHeartbeat($heartbeat)],
        ];

        if ($isDebug) {
            $info[] = ['Debug', '<fg=yellow>Enabled</>'];
        }

        $addedTimestamp = $addedDate !== '' ? strtotime($addedDate) : false;
        $info[] = ['Added', $addedTimestamp !== false ? date('Y-m-d H:i:s', $addedTimestamp) : 'Unknown'];

        return $info;
    }

    /**
     * Build connection-specific info array.
     *
     * @param array<string, mixed> $server
     * @return list<array{0: string, 1: string}>
     */
    private function buildConnectionInfo(array $server): array
    {
        $connType = $this->getNumericField($server, 'connection_type_id');
        $info = [];

        if ($connType === 0 || $connType === 1) {
            $path = $this->getStringField($server, 'path');
            if ($path !== '') {
                $info[] = ['Path', $path];
            }
        } elseif ($connType === 2) {
            $ftpHost = $this->getStringField($server, 'ftp_host');
            if ($ftpHost !== '') {
                $info[] = ['FTP Host', $ftpHost . ':' . $this->getStringField($server, 'ftp_port')];
                $info[] = ['FTP User', $this->getStringField($server, 'ftp_user')];
                $info[] = ['FTP Folder', $this->getStringField($server, 'ftp_folder')];
            }
        }

        return $info;
    }

    /**
     * Display server errors if any.
     *
     * @param array<string, mixed> $server
     */
    private function displayServerErrors(array $server): void
    {
        $errorIteration = $this->getNumericField($server, 'error_iteration');

        if ($errorIteration <= 1) {
            return;
        }

        $this->io()->newLine();
        $this->io()->section('Errors');

        $errorId = $this->getNumericField($server, 'error_id');
        $errorMessages = [
            1 => 'Write error - Cannot write to storage',
            2 => 'Heartbeat error - Server not responding',
            3 => 'Heartbeat timeout - Server response delayed',
            4 => 'Library path error - FFmpeg/ImageMagick not found',
            5 => 'API version mismatch - Update required',
            6 => 'Task locked too long - Possible deadlock',
        ];

        if (isset($errorMessages[$errorId])) {
            $this->io()->text("<fg=red>* {$errorMessages[$errorId]}</>");
        }
    }

    private function enableServer(?string $id): int
    {
        return $this->toggleEntityStatus(
            'Conversion server',
            $this->table('admin_conversion_servers'),
            'server_id',
            'title',
            $id,
            StatusFormatter::CONVERSION_ACTIVE,
            'system:conversion'
        );
    }

    private function disableServer(?string $id): int
    {
        return $this->toggleEntityStatus(
            'Conversion server',
            $this->table('admin_conversion_servers'),
            'server_id',
            'title',
            $id,
            StatusFormatter::CONVERSION_DISABLED,
            'system:conversion'
        );
    }

    private function toggleDebug(?string $id, bool $enable): int
    {
        if ($id === null || $id === '') {
            $this->io()->error('Server ID is required');
            $action = $enable ? 'debug-on' : 'debug-off';
            $this->io()->text("Usage: kvs system:conversion {$action} <server_id>");
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Check if server exists
            $stmt = $db->prepare("SELECT title, is_debug_enabled FROM {$this->table('admin_conversion_servers')} WHERE server_id = :id");
            $stmt->execute(['id' => $id]);
            /** @var array<string, mixed>|false $server */
            $server = $stmt->fetch();

            if ($server === false) {
                $this->io()->error("Conversion server not found: {$id}");
                return self::FAILURE;
            }

            $currentDebug = $this->getNumericField($server, 'is_debug_enabled');
            $targetDebug = $enable ? 1 : 0;

            if ($currentDebug === $targetDebug) {
                $status = $enable ? 'enabled' : 'disabled';
                $this->io()->info("Debug is already {$status}");
                return self::SUCCESS;
            }

            // Update debug status
            $stmt = $db->prepare("UPDATE {$this->table('admin_conversion_servers')} SET is_debug_enabled = :debug WHERE server_id = :id");
            $stmt->execute(['debug' => $targetDebug, 'id' => $id]);

            $title = $this->getStringField($server, 'title');
            $action = $enable ? 'enabled' : 'disabled';
            $this->io()->success("Debug {$action} for server '{$title}'");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to toggle debug: ' . $e->getMessage());
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
            $this->io()->section('Conversion Statistics');

            // Overall stats
            $stmtStats = $db->query("
                SELECT
                    COUNT(*) as total_servers,
                    SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) as active_servers,
                    SUM(CASE WHEN status_id = 0 THEN 1 ELSE 0 END) as disabled_servers,
                    SUM(CASE WHEN status_id = 2 THEN 1 ELSE 0 END) as init_servers,
                    SUM(total_space) as total_space,
                    SUM(free_space) as free_space,
                    AVG(`load`) as avg_load,
                    SUM(max_tasks) as total_capacity,
                    SUM(CASE WHEN error_iteration > 1 THEN 1 ELSE 0 END) as servers_with_errors,
                    SUM(CASE WHEN is_debug_enabled = 1 THEN 1 ELSE 0 END) as debug_enabled
                FROM {$this->table('admin_conversion_servers')}
            ");
            if ($stmtStats === false) {
                $this->io()->error('Failed to query stats');
                return self::FAILURE;
            }
            /** @var array<string, mixed>|false $stats */
            $stats = $stmtStats->fetch();

            if ($stats === false) {
                $this->io()->warning('No conversion servers found');
                return self::SUCCESS;
            }

            $totalServers = $this->getNumericField($stats, 'total_servers');
            $activeServers = $this->getNumericField($stats, 'active_servers');
            $disabledServers = $this->getNumericField($stats, 'disabled_servers');
            $initServers = $this->getNumericField($stats, 'init_servers');
            $totalSpace = $this->getNumericField($stats, 'total_space');
            $freeSpace = $this->getNumericField($stats, 'free_space');
            $avgLoad = $this->getFloatField($stats, 'avg_load');
            $totalCapacity = $this->getNumericField($stats, 'total_capacity');
            $serversWithErrors = $this->getNumericField($stats, 'servers_with_errors');
            $debugEnabled = $this->getNumericField($stats, 'debug_enabled');

            $usedSpace = $totalSpace - $freeSpace;
            $usedPercent = $totalSpace > 0 ? round(($usedSpace / $totalSpace) * 100, 1) : 0;

            $overallInfo = [
                ['Total Servers', (string) $totalServers],
                ['Active', (string) $activeServers],
                ['Disabled', (string) $disabledServers],
                ['Initializing', (string) $initServers],
                ['With Errors', $serversWithErrors > 0 ? "<fg=red>{$serversWithErrors}</>" : '0'],
                ['Debug Enabled', $debugEnabled > 0 ? "<fg=yellow>{$debugEnabled}</>" : '0'],
                ['Total Capacity', "{$totalCapacity} concurrent tasks"],
                ['Total Space', $this->formatBytes($totalSpace)],
                ['Used Space', $this->formatBytes($usedSpace) . " ({$usedPercent}%)"],
                ['Free Space', $this->formatBytes($freeSpace)],
                ['Avg Load', number_format($avgLoad, 2)],
            ];

            $this->renderTable(['Metric', 'Value'], $overallInfo);

            // Task stats
            $this->io()->newLine();
            $this->io()->section('Task Queue');

            $stmtTasks = $db->query("
                SELECT
                    SUM(CASE WHEN status_id = 0 THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) as processing
                FROM {$this->table('background_tasks')}
            ");
            $taskResult = $stmtTasks !== false ? $stmtTasks->fetch() : false;
            /** @var array<string, mixed> $taskStats */
            $taskStats = is_array($taskResult) ? $taskResult : [];

            $stmtHistory = $db->query("SELECT COUNT(*) as total FROM {$this->table('background_tasks_history')}");
            $historyResult = $stmtHistory !== false ? $stmtHistory->fetch() : false;
            /** @var array<string, mixed> $historyStats */
            $historyStats = is_array($historyResult) ? $historyResult : [];

            $pending = $this->getNumericField($taskStats, 'pending');
            $processing = $this->getNumericField($taskStats, 'processing');
            $completed = $this->getNumericField($historyStats, 'total');

            $taskInfo = [
                ['Pending', (string) $pending],
                ['Processing', (string) $processing],
                ['Completed (history)', number_format($completed)],
            ];

            $this->renderTable(['Status', 'Count'], $taskInfo);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch stats: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $exp = (int) floor(log($bytes, 1024));
        $exp = min($exp, count($units) - 1);

        return round($bytes / (1024 ** $exp), 2) . ' ' . $units[$exp];
    }

    private function formatHeartbeat(string $date): string
    {
        if ($date === '' || $date === '0000-00-00 00:00:00') {
            return 'Never';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return 'Unknown';
        }

        $diff = time() - $timestamp;
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return "{$mins}m ago";
        } elseif ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return "{$hours}h ago";
        }

        return date('Y-m-d H:i', $timestamp);
    }

    /**
     * Get numeric field from server array.
     *
     * @param array<string, mixed> $data
     */
    private function getNumericField(array $data, string $key, int $default = 0): int
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : $default;
    }

    /**
     * Get float field from server array.
     *
     * @param array<string, mixed> $data
     */
    private function getFloatField(array $data, string $key, float $default = 0.0): float
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (float) $data[$key] : $default;
    }

    /**
     * Get string field from server array.
     *
     * @param array<string, mixed> $data
     */
    private function getStringField(array $data, string $key, string $default = ''): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : $default;
    }
}
