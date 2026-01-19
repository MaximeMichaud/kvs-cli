<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ToggleStatusTrait;
use KVS\CLI\Constants;
use KVS\CLI\Output\Formatter;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system:server',
    description: 'Manage KVS storage servers',
    aliases: ['server', 'servers']
)]
class ServerCommand extends BaseCommand
{
    use ToggleStatusTrait;

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: list|show|enable|disable|stats|group')
            ->addArgument('id', InputArgument::OPTIONAL, 'Server or group ID')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by content type (video|album)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|disabled)')
            ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Filter by connection (local|mount|ftp|s3)')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Filter by group ID')
            ->addOption('errors', null, InputOption::VALUE_NONE, 'Show only servers with errors')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results', 50)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count', 'table')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation')
            ->setHelp(<<<'HELP'
Manage KVS storage servers and server groups.

<fg=yellow>ACTIONS:</>
  list        List all storage servers (default)
  show <id>   Show server details
  enable <id> Enable/activate a server
  disable <id> Disable/deactivate a server
  stats       Show storage statistics overview
  group       List or show server groups (use: group, group <id>)

<fg=yellow>CONTENT TYPES:</>
  video    Video storage servers (content_type_id=1)
  album    Album/image storage servers (content_type_id=2)

<fg=yellow>CONNECTION TYPES:</>
  local    Local filesystem
  mount    Mounted/network filesystem
  ftp      FTP connection
  s3       Amazon S3 or compatible

<fg=yellow>STREAMING TYPES:</>
  0=Nginx, 1=Apache, 4=CDN, 5=Backup

<fg=yellow>EXAMPLES:</>
  <fg=green>kvs server list</>
  <fg=green>kvs server list --type=video</>
  <fg=green>kvs server list --status=active</>
  <fg=green>kvs server list --connection=s3</>
  <fg=green>kvs server list --errors</>
  <fg=green>kvs server show 1</>
  <fg=green>kvs server enable 1</>
  <fg=green>kvs server disable 1</>
  <fg=green>kvs server stats</>
  <fg=green>kvs server group</>
  <fg=green>kvs server group 1</>
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
            'stats' => $this->showStats(),
            'group' => $id !== null ? $this->showGroup($id) : $this->listGroups($input),
            default => $this->listServers($input),
        };
    }

    private function listServers(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $query = "SELECT s.*, g.title as group_title
                 FROM {$this->table('admin_servers')} s
                 LEFT JOIN {$this->table('admin_servers_groups')} g ON s.group_id = g.group_id
                 WHERE 1=1";

        $params = [];

        // Content type filter
        $type = $input->getOption('type');
        if (is_string($type) && $type !== '') {
            $typeId = match (strtolower($type)) {
                'video', 'videos' => 1,
                'album', 'albums', 'image', 'images' => 2,
                default => null,
            };
            if ($typeId !== null) {
                $query .= " AND s.content_type_id = :type";
                $params['type'] = $typeId;
            }
        }

        // Status filter
        $status = $input->getOption('status');
        if (is_string($status) && $status !== '') {
            $statusId = match (strtolower($status)) {
                'active', '1' => 1,
                'disabled', 'inactive', '0' => 0,
                default => null,
            };
            if ($statusId !== null) {
                $query .= " AND s.status_id = :status";
                $params['status'] = $statusId;
            }
        }

        // Connection type filter
        $connection = $input->getOption('connection');
        if (is_string($connection) && $connection !== '') {
            $connId = match (strtolower($connection)) {
                'local' => 0,
                'mount' => 1,
                'ftp' => 2,
                's3' => 3,
                default => null,
            };
            if ($connId !== null) {
                $query .= " AND s.connection_type_id = :connection";
                $params['connection'] = $connId;
            }
        }

        // Group filter
        $groupId = $this->getIntOption($input, 'group');
        if ($groupId !== null) {
            $query .= " AND s.group_id = :group_id";
            $params['group_id'] = $groupId;
        }

        // Errors filter
        if ($input->getOption('errors')) {
            $query .= " AND (s.error_iteration > 1 OR s.error_streaming_iteration > 1)";
        }

        $query .= " ORDER BY s.group_id ASC, s.server_id ASC LIMIT :limit";

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
                $statusId = isset($server['status_id']) && is_numeric($server['status_id'])
                    ? (int) $server['status_id'] : 0;
                $streamingType = isset($server['streaming_type_id']) && is_numeric($server['streaming_type_id'])
                    ? (int) $server['streaming_type_id'] : 0;
                $connType = isset($server['connection_type_id']) && is_numeric($server['connection_type_id'])
                    ? (int) $server['connection_type_id'] : 0;
                $totalSpace = isset($server['total_space']) && is_numeric($server['total_space'])
                    ? (int) $server['total_space'] : 0;
                $freeSpace = isset($server['free_space']) && is_numeric($server['free_space'])
                    ? (int) $server['free_space'] : 0;
                $load = isset($server['load']) && is_numeric($server['load'])
                    ? (float) $server['load'] : 0.0;

                $errorIter = isset($server['error_iteration']) && is_numeric($server['error_iteration'])
                    ? (int) $server['error_iteration'] : 0;
                $errorStreamIter = isset($server['error_streaming_iteration']) && is_numeric($server['error_streaming_iteration'])
                    ? (int) $server['error_streaming_iteration'] : 0;
                $hasError = $errorIter > 1 || $errorStreamIter > 1;

                return [
                    'server_id' => $server['server_id'] ?? 0,
                    'id' => $server['server_id'] ?? 0,
                    'title' => $server['title'] ?? '',
                    'group_title' => $server['group_title'] ?? '',
                    'status_id' => $statusId,
                    'status' => StatusFormatter::server($statusId, false),
                    'streaming_type_id' => $streamingType,
                    'streaming' => StatusFormatter::serverStreaming($streamingType, false),
                    'connection_type_id' => $connType,
                    'connection' => StatusFormatter::serverConnection($connType, false),
                    'total_space' => $this->formatBytes($totalSpace),
                    'free_space' => $this->formatBytes($freeSpace),
                    'free_percent' => $totalSpace > 0 ? round(($freeSpace / $totalSpace) * 100, 1) . '%' : '0%',
                    'load' => number_format($load, 2),
                    'has_error' => $hasError ? 'Yes' : 'No',
                    'urls' => $server['urls'] ?? '',
                ];
            }, $servers);

            $defaultFields = ['server_id', 'title', 'group_title', 'status', 'streaming', 'connection', 'free_space', 'load'];
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($transformed, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch servers: ' . $e->getMessage());
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
                SELECT s.*, g.title as group_title
                FROM {$this->table('admin_servers')} s
                LEFT JOIN {$this->table('admin_servers_groups')} g ON s.group_id = g.group_id
                WHERE s.server_id = :id
            ");
            $stmt->execute(['id' => $id]);
            /** @var array<string, mixed>|false $server */
            $server = $stmt->fetch();

            if ($server === false) {
                $this->io()->error("Server not found: $id");
                return self::FAILURE;
            }

            $this->io()->section("Server #$id");

            $info = $this->buildServerInfo($server);
            $info = array_merge($info, $this->buildConnectionInfo($server));
            $info = array_merge($info, $this->buildControlInfo($server));

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
        $streamingType = $this->getNumericField($server, 'streaming_type_id');
        $connType = $this->getNumericField($server, 'connection_type_id');
        $contentType = $this->getNumericField($server, 'content_type_id');
        $totalSpace = $this->getNumericField($server, 'total_space');
        $freeSpace = $this->getNumericField($server, 'free_space');
        $load = $this->getFloatField($server, 'load');
        $isDebug = $this->getNumericField($server, 'is_debug_enabled') === 1;

        $freePercent = $totalSpace > 0 ? round(($freeSpace / $totalSpace) * 100, 1) : 0;
        $contentTypeStr = $contentType === 1 ? 'Videos' : ($contentType === 2 ? 'Albums' : 'Unknown');
        $title = $this->getStringField($server, 'title');
        $groupTitle = $this->getStringField($server, 'group_title', 'None');
        $urls = $this->getStringField($server, 'urls');
        $addedDate = $this->getStringField($server, 'added_date');
        $addedTimestamp = $addedDate !== '' ? strtotime($addedDate) : false;

        $info = [
            ['Title', $title],
            ['Group', $groupTitle],
            ['Status', StatusFormatter::server($statusId)],
            ['Content Type', $contentTypeStr],
            ['Streaming', StatusFormatter::serverStreaming($streamingType)],
            ['Connection', StatusFormatter::serverConnection($connType)],
            ['URLs', $urls],
            ['Total Space', $this->formatBytes($totalSpace)],
            ['Free Space', $this->formatBytes($freeSpace) . " ({$freePercent}%)"],
            ['Load', number_format($load, 2)],
        ];

        if ($isDebug) {
            $info[] = ['Debug', '<fg=yellow>Enabled</>'];
        }

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
        } elseif ($connType === 3) {
            $s3Bucket = $this->getStringField($server, 's3_bucket');
            if ($s3Bucket !== '') {
                $info[] = ['S3 Region', $this->getStringField($server, 's3_region')];
                $info[] = ['S3 Bucket', $s3Bucket];
                $s3Endpoint = $this->getStringField($server, 's3_endpoint');
                if ($s3Endpoint !== '') {
                    $info[] = ['S3 Endpoint', $s3Endpoint];
                }
            }
        }

        return $info;
    }

    /**
     * Build remote control info array.
     *
     * @param array<string, mixed> $server
     * @return list<array{0: string, 1: string}>
     */
    private function buildControlInfo(array $server): array
    {
        $controlUrl = $this->getStringField($server, 'control_script_url');
        if ($controlUrl === '') {
            return [];
        }

        return [
            ['Control Script', $controlUrl],
            ['API Version', $this->getStringField($server, 'control_script_url_version')],
        ];
    }

    /**
     * Display server errors if any.
     *
     * @param array<string, mixed> $server
     */
    private function displayServerErrors(array $server): void
    {
        $errorIteration = $this->getNumericField($server, 'error_iteration');
        $errorStreamingIteration = $this->getNumericField($server, 'error_streaming_iteration');

        if ($errorIteration <= 1 && $errorStreamingIteration <= 1) {
            return;
        }

        $this->io()->newLine();
        $this->io()->section('Errors');

        $errors = [];
        if ($errorIteration > 1 && $this->getNumericField($server, 'error_id') === 1) {
            $errors[] = 'Write error - Cannot write to storage';
        }

        if ($errorStreamingIteration > 1) {
            $streamingErrors = [
                2 => 'Control script unreachable',
                3 => 'Control script key mismatch',
                4 => 'Time synchronization error',
                5 => 'Content availability error',
                6 => 'CDN API error',
                7 => 'HTTPS error',
            ];
            $errorStreamingId = $this->getNumericField($server, 'error_streaming_id');
            if (isset($streamingErrors[$errorStreamingId])) {
                $errors[] = $streamingErrors[$errorStreamingId];
            }
        }

        foreach ($errors as $error) {
            $this->io()->text("<fg=red>* {$error}</>");
        }
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

    private function showStats(): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $this->io()->section('Storage Statistics');

            // Overall stats
            $stmtStats = $db->query("
                SELECT
                    COUNT(*) as total_servers,
                    SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) as active_servers,
                    SUM(CASE WHEN status_id = 0 THEN 1 ELSE 0 END) as disabled_servers,
                    SUM(total_space) as total_space,
                    SUM(free_space) as free_space,
                    AVG(`load`) as avg_load,
                    SUM(CASE WHEN error_iteration > 1 OR error_streaming_iteration > 1 THEN 1 ELSE 0 END) as servers_with_errors
                FROM {$this->table('admin_servers')}
            ");
            if ($stmtStats === false) {
                $this->io()->error('Failed to query server stats');
                return self::FAILURE;
            }
            /** @var array<string, mixed>|false $stats */
            $stats = $stmtStats->fetch();

            if ($stats === false) {
                $this->io()->warning('No servers found');
                return self::SUCCESS;
            }

            $totalServers = isset($stats['total_servers']) && is_numeric($stats['total_servers'])
                ? (int) $stats['total_servers'] : 0;
            $activeServers = isset($stats['active_servers']) && is_numeric($stats['active_servers'])
                ? (int) $stats['active_servers'] : 0;
            $disabledServers = isset($stats['disabled_servers']) && is_numeric($stats['disabled_servers'])
                ? (int) $stats['disabled_servers'] : 0;
            $totalSpace = isset($stats['total_space']) && is_numeric($stats['total_space'])
                ? (int) $stats['total_space'] : 0;
            $freeSpace = isset($stats['free_space']) && is_numeric($stats['free_space'])
                ? (int) $stats['free_space'] : 0;
            $avgLoad = isset($stats['avg_load']) && is_numeric($stats['avg_load'])
                ? (float) $stats['avg_load'] : 0.0;
            $serversWithErrors = isset($stats['servers_with_errors']) && is_numeric($stats['servers_with_errors'])
                ? (int) $stats['servers_with_errors'] : 0;

            $usedSpace = $totalSpace - $freeSpace;
            $usedPercent = $totalSpace > 0 ? round(($usedSpace / $totalSpace) * 100, 1) : 0;

            $overallInfo = [
                ['Total Servers', (string) $totalServers],
                ['Active', (string) $activeServers],
                ['Disabled', (string) $disabledServers],
                ['With Errors', $serversWithErrors > 0 ? "<fg=red>{$serversWithErrors}</>" : '0'],
                ['Total Space', $this->formatBytes($totalSpace)],
                ['Used Space', $this->formatBytes($usedSpace) . " ({$usedPercent}%)"],
                ['Free Space', $this->formatBytes($freeSpace)],
                ['Avg Load', number_format($avgLoad, 2)],
            ];

            $this->renderTable(['Metric', 'Value'], $overallInfo);

            // By content type
            $this->io()->newLine();
            $this->io()->section('By Content Type');

            $stmtType = $db->query("
                SELECT
                    content_type_id,
                    COUNT(*) as count,
                    SUM(total_space) as total_space,
                    SUM(free_space) as free_space
                FROM {$this->table('admin_servers')}
                GROUP BY content_type_id
                ORDER BY content_type_id
            ");
            /** @var list<array<string, mixed>> $byType */
            $byType = $stmtType !== false ? $stmtType->fetchAll(\PDO::FETCH_ASSOC) : [];

            $typeData = [];
            foreach ($byType as $row) {
                $contentType = isset($row['content_type_id']) && is_numeric($row['content_type_id'])
                    ? (int) $row['content_type_id'] : 0;
                $count = isset($row['count']) && is_numeric($row['count']) ? (int) $row['count'] : 0;
                $total = isset($row['total_space']) && is_numeric($row['total_space']) ? (int) $row['total_space'] : 0;
                $free = isset($row['free_space']) && is_numeric($row['free_space']) ? (int) $row['free_space'] : 0;

                $typeName = $contentType === 1 ? 'Videos' : ($contentType === 2 ? 'Albums' : 'Unknown');
                $typeData[] = [
                    $typeName,
                    (string) $count,
                    $this->formatBytes($total),
                    $this->formatBytes($free),
                ];
            }

            $this->renderTable(['Type', 'Servers', 'Total Space', 'Free Space'], $typeData);

            // By connection type
            $this->io()->newLine();
            $this->io()->section('By Connection Type');

            $stmtConn = $db->query("
                SELECT
                    connection_type_id,
                    COUNT(*) as count
                FROM {$this->table('admin_servers')}
                GROUP BY connection_type_id
                ORDER BY connection_type_id
            ");
            /** @var list<array<string, mixed>> $byConn */
            $byConn = $stmtConn !== false ? $stmtConn->fetchAll(\PDO::FETCH_ASSOC) : [];

            $connData = [];
            foreach ($byConn as $row) {
                $connTypeId = isset($row['connection_type_id']) && is_numeric($row['connection_type_id'])
                    ? (int) $row['connection_type_id'] : 0;
                $count = isset($row['count']) && is_numeric($row['count']) ? (int) $row['count'] : 0;

                $connData[] = [
                    StatusFormatter::serverConnection($connTypeId, false),
                    (string) $count,
                ];
            }

            $this->renderTable(['Connection', 'Servers'], $connData);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch stats: ' . $e->getMessage());
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
            $stmtGroups = $db->query("
                SELECT g.*,
                    (SELECT COUNT(*) FROM {$this->table('admin_servers')} WHERE group_id = g.group_id) as server_count,
                    (SELECT COUNT(*) FROM {$this->table('admin_servers')} WHERE group_id = g.group_id AND status_id = 1) as active_count,
                    (SELECT COALESCE(MIN(free_space), 0) FROM {$this->table('admin_servers')} WHERE group_id = g.group_id) as min_free_space,
                    (SELECT COALESCE(SUM(total_space), 0) FROM {$this->table('admin_servers')} WHERE group_id = g.group_id) as total_space
                FROM {$this->table('admin_servers_groups')} g
                ORDER BY g.group_id ASC
            ");
            /** @var list<array<string, mixed>> $groups */
            $groups = $stmtGroups !== false ? $stmtGroups->fetchAll(\PDO::FETCH_ASSOC) : [];

            // Get content counts
            $videoGroups = [];
            $albumGroups = [];

            $stmtVids = $db->query("SELECT server_group_id, COUNT(*) as cnt FROM {$this->table('videos')} GROUP BY server_group_id");
            if ($stmtVids !== false) {
                foreach ($stmtVids->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $gid = isset($row['server_group_id']) && is_numeric($row['server_group_id'])
                        ? (int) $row['server_group_id'] : 0;
                    $videoGroups[$gid] = isset($row['cnt']) && is_numeric($row['cnt'])
                        ? (int) $row['cnt'] : 0;
                }
            }

            $stmtAlbs = $db->query("SELECT server_group_id, COUNT(*) as cnt FROM {$this->table('albums')} GROUP BY server_group_id");
            if ($stmtAlbs !== false) {
                foreach ($stmtAlbs->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $gid = isset($row['server_group_id']) && is_numeric($row['server_group_id'])
                        ? (int) $row['server_group_id'] : 0;
                    $albumGroups[$gid] = isset($row['cnt']) && is_numeric($row['cnt'])
                        ? (int) $row['cnt'] : 0;
                }
            }

            $transformed = array_map(function (array $group) use ($videoGroups, $albumGroups): array {
                $groupId = isset($group['group_id']) && is_numeric($group['group_id'])
                    ? (int) $group['group_id'] : 0;
                $statusId = isset($group['status_id']) && is_numeric($group['status_id'])
                    ? (int) $group['status_id'] : 0;
                $contentType = isset($group['content_type_id']) && is_numeric($group['content_type_id'])
                    ? (int) $group['content_type_id'] : 0;
                $serverCount = isset($group['server_count']) && is_numeric($group['server_count'])
                    ? (int) $group['server_count'] : 0;
                $activeCount = isset($group['active_count']) && is_numeric($group['active_count'])
                    ? (int) $group['active_count'] : 0;
                $totalSpace = isset($group['total_space']) && is_numeric($group['total_space'])
                    ? (int) $group['total_space'] : 0;
                $minFreeSpace = isset($group['min_free_space']) && is_numeric($group['min_free_space'])
                    ? (int) $group['min_free_space'] : 0;

                $contentCount = $contentType === 1
                    ? ($videoGroups[$groupId] ?? 0)
                    : ($albumGroups[$groupId] ?? 0);
                $contentTypeStr = $contentType === 1 ? 'Videos' : 'Albums';

                $titleVal = $group['title'] ?? '';
                $title = is_string($titleVal) ? $titleVal : '';

                return [
                    'group_id' => $groupId,
                    'id' => $groupId,
                    'title' => $title,
                    'status_id' => $statusId,
                    'status' => StatusFormatter::server($statusId, false),
                    'content_type' => $contentTypeStr,
                    'servers' => "{$activeCount}/{$serverCount}",
                    'content_count' => number_format($contentCount),
                    'total_space' => $this->formatBytes($totalSpace),
                    'min_free' => $this->formatBytes($minFreeSpace),
                ];
            }, $groups);

            $defaultFields = ['group_id', 'title', 'status', 'content_type', 'servers', 'content_count', 'total_space'];
            $formatter = new Formatter($input->getOptions(), $defaultFields);
            $formatter->display($transformed, $this->io());

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch groups: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function showGroup(string $id): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table('admin_servers_groups')} WHERE group_id = :id");
            $stmt->execute(['id' => $id]);
            /** @var array<string, mixed>|false $group */
            $group = $stmt->fetch();

            if ($group === false) {
                $this->io()->error("Server group not found: $id");
                return self::FAILURE;
            }

            $groupId = isset($group['group_id']) && is_numeric($group['group_id'])
                ? (int) $group['group_id'] : 0;
            $statusId = isset($group['status_id']) && is_numeric($group['status_id'])
                ? (int) $group['status_id'] : 0;
            $contentType = isset($group['content_type_id']) && is_numeric($group['content_type_id'])
                ? (int) $group['content_type_id'] : 0;

            $titleVal = $group['title'] ?? '';
            $title = is_string($titleVal) ? $titleVal : '';
            $contentTypeStr = $contentType === 1 ? 'Videos' : 'Albums';

            $this->io()->section("Server Group #$id: $title");

            // Get content count
            $contentTable = $contentType === 1 ? 'videos' : 'albums';
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table($contentTable)} WHERE server_group_id = :id");
            $stmt->execute(['id' => $id]);
            $contentCount = (int) $stmt->fetchColumn();

            $addedDate = isset($group['added_date']) && is_string($group['added_date']) ? $group['added_date'] : '';
            $addedTimestamp = $addedDate !== '' ? strtotime($addedDate) : false;

            $info = [
                ['Title', $title],
                ['Status', StatusFormatter::server($statusId)],
                ['Content Type', $contentTypeStr],
                ['Content Count', number_format($contentCount) . " {$contentTypeStr}"],
                ['Added', $addedTimestamp !== false ? date('Y-m-d H:i:s', $addedTimestamp) : 'Unknown'],
            ];

            $this->renderTable(['Property', 'Value'], $info);

            // Servers in group
            $this->io()->newLine();
            $this->io()->section('Servers in Group');

            $stmt = $db->prepare("
                SELECT * FROM {$this->table('admin_servers')}
                WHERE group_id = :id
                ORDER BY server_id ASC
            ");
            $stmt->execute(['id' => $id]);
            /** @var list<array<string, mixed>> $servers */
            $servers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (count($servers) === 0) {
                $this->io()->text('No servers in this group');
            } else {
                $serverData = [];
                foreach ($servers as $server) {
                    $serverId = isset($server['server_id']) && is_numeric($server['server_id'])
                        ? (int) $server['server_id'] : 0;
                    $serverTitle = isset($server['title']) && is_string($server['title']) ? $server['title'] : '';
                    $serverStatus = isset($server['status_id']) && is_numeric($server['status_id'])
                        ? (int) $server['status_id'] : 0;
                    $freeSpace = isset($server['free_space']) && is_numeric($server['free_space'])
                        ? (int) $server['free_space'] : 0;
                    $totalSpace = isset($server['total_space']) && is_numeric($server['total_space'])
                        ? (int) $server['total_space'] : 0;
                    $serverLoad = isset($server['load']) && is_numeric($server['load'])
                        ? (float) $server['load'] : 0.0;

                    $freePercent = $totalSpace > 0 ? round(($freeSpace / $totalSpace) * 100, 1) : 0;

                    $serverData[] = [
                        (string) $serverId,
                        $serverTitle,
                        StatusFormatter::server($serverStatus),
                        $this->formatBytes($freeSpace) . " ({$freePercent}%)",
                        number_format($serverLoad, 2),
                    ];
                }

                $this->renderTable(['ID', 'Title', 'Status', 'Free Space', 'Load'], $serverData);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch group: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function enableServer(?string $id): int
    {
        return $this->toggleEntityStatus(
            'Server',
            $this->table('admin_servers'),
            'server_id',
            'title',
            $id,
            StatusFormatter::SERVER_ACTIVE,
            'system:server'
        );
    }

    private function disableServer(?string $id): int
    {
        return $this->toggleEntityStatus(
            'Server',
            $this->table('admin_servers'),
            'server_id',
            'title',
            $id,
            StatusFormatter::SERVER_DISABLED,
            'system:server'
        );
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
}
