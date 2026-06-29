<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ExperimentalCommandTrait;
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
    description: '[EXPERIMENTAL] Manage KVS storage servers',
    aliases: ['server', 'servers']
)]
class ServerCommand extends BaseCommand
{
    use ExperimentalCommandTrait;
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
  0=Nginx (x-accel-redirect), 1=Direct URL (no protection), 4=CDN, 5=No public access (backup server)

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
        $this->configureExperimentalOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $abort = $this->confirmExperimental($input, $output);
        if ($abort !== null) {
            return $abort;
        }

        $action = $this->getStringArgument($input, 'action') ?? 'list';
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listServers($input),
            'show' => $this->showServer($id, $input),
            'enable', 'activate' => $this->enableServer($id),
            'disable', 'deactivate' => $this->disableServer($id),
            'stats' => $this->showStats($input),
            'group' => $id !== null ? $this->showGroup($id, $input) : $this->listGroups($input),
            default => $this->failUnknownAction(
                'server',
                $action,
                ['list', 'show', 'enable', 'disable', 'stats', 'group']
            ),
        };
    }

    private function listServers(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        $fromSql = "FROM {$this->table('admin_servers')} s
                 LEFT JOIN {$this->table('admin_servers_groups')} g ON s.group_id = g.group_id
                 WHERE 1=1";

        $params = [];

        $type = $this->getStringOption($input, 'type');
        if ($type !== null) {
            $typeMap = [
                'video' => 1,
                'videos' => 1,
                'album' => 2,
                'albums' => 2,
                'image' => 2,
                'images' => 2,
            ];
            $typeKey = strtolower($type);
            if (!array_key_exists($typeKey, $typeMap)) {
                $this->io()->error('Invalid value for --type (use: video or album)');
                return self::FAILURE;
            }
            $fromSql .= " AND s.content_type_id = :type";
            $params['type'] = $typeMap[$typeKey];
        }

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusMap = [
                'active' => 1,
                '1' => 1,
                'disabled' => 0,
                'inactive' => 0,
                '0' => 0,
            ];
            $statusKey = strtolower($status);
            if (!array_key_exists($statusKey, $statusMap)) {
                $this->io()->error('Invalid value for --status (use: active or disabled)');
                return self::FAILURE;
            }
            $fromSql .= " AND s.status_id = :status";
            $params['status'] = $statusMap[$statusKey];
        }

        $connection = $this->getStringOption($input, 'connection');
        if ($connection !== null) {
            $connectionMap = [
                'local' => 0,
                'mount' => 1,
                'ftp' => 2,
                's3' => 3,
            ];
            $connectionKey = strtolower($connection);
            if (!array_key_exists($connectionKey, $connectionMap)) {
                $this->io()->error('Invalid value for --connection (use: local, mount, ftp, or s3)');
                return self::FAILURE;
            }
            $fromSql .= " AND s.connection_type_id = :connection";
            $params['connection'] = $connectionMap[$connectionKey];
        }

        // Group filter
        $groupId = $this->getOptionalNonNegativeIntOption($input, 'group');
        if ($groupId === false) {
            return self::FAILURE;
        }
        if ($groupId !== null) {
            $fromSql .= " AND s.group_id = :group_id";
            $params['group_id'] = $groupId;
        }

        // Errors filter
        if ($input->getOption('errors')) {
            $fromSql .= " AND (s.error_iteration > 1 OR s.error_streaming_iteration > 1)";
        }

        if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
            if ($this->getPositiveIntOptionOrDefault($input, 'limit', 50) === null) {
                return self::FAILURE;
            }

            return $this->countRows($db, "SELECT COUNT(*) $fromSql", $params, 'servers');
        }

        $query = "SELECT s.*, g.title as group_title, g.status_id as group_status_id
                 $fromSql
                 ORDER BY s.group_id ASC, s.server_id ASC LIMIT :limit";

        try {
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', 50);
            if ($limit === null) {
                return self::FAILURE;
            }
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<array<string, mixed>> $servers */
            $servers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $servers = $this->addStorageContentCounts($db, $servers);
            $minFreeSpaceBytes = $this->getServerGroupMinFreeSpaceBytes($db);

            $transformed = array_map(function (array $server) use ($minFreeSpaceBytes): array {
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
                $contentCount = isset($server['content_count']) && is_numeric($server['content_count'])
                    ? (int) $server['content_count'] : 0;
                $isRemote = isset($server['is_remote']) && is_numeric($server['is_remote'])
                    ? (int) $server['is_remote'] : null;

                $errorIter = isset($server['error_iteration']) && is_numeric($server['error_iteration'])
                    ? (int) $server['error_iteration'] : 0;
                $errorStreamIter = isset($server['error_streaming_iteration']) && is_numeric($server['error_streaming_iteration'])
                    ? (int) $server['error_streaming_iteration'] : 0;
                $hasError = $errorIter > 1 || $errorStreamIter > 1;
                if ($isRemote !== null && $isRemote !== 1) {
                    $server['control_script_url'] = '';
                    $server['control_script_url_version'] = 'N/A';
                    $server['control_script_url_lock_ip'] = 0;
                }
                $computedAdminFields = $this->buildKvsAdminServerComputedFields(
                    $server,
                    $totalSpace,
                    $freeSpace,
                    $minFreeSpaceBytes
                );

                return [
                    ...$server,
                    ...$computedAdminFields,
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
                    'content_count' => $contentCount,
                    'total_content' => $this->formatStorageContentCount($contentCount, $server['content_type_id'] ?? null),
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

    /**
     * @param array<string, mixed> $server
     * @return array{error_text: string, free_space_percent: string, is_error: int, is_warning: int, is_free_space_warning: int}
     */
    private function buildKvsAdminServerComputedFields(
        array $server,
        int $totalSpace,
        int $freeSpace,
        int $minFreeSpaceBytes
    ): array {
        $errorText = '';
        $isWarning = 0;
        $isFreeSpaceWarning = 0;

        if ($this->getNumericField($server, 'is_debug_enabled') === 1) {
            $isWarning = 1;
            $errorText .= ' (This server has debug log enabled)';
        }

        if (
            $this->getNumericField($server, 'group_status_id') === StatusFormatter::SERVER_ACTIVE
            && $minFreeSpaceBytes > 0
            && $freeSpace < $minFreeSpaceBytes
        ) {
            $isWarning = 1;
            $isFreeSpaceWarning = 1;
            $errorText .= ' (No free space is available)';
        }

        if ($this->getNumericField($server, 'warning_id') > 0) {
            $isWarning = 1;
            $errorText .= ' (Content is not protected from direct access)';
        }

        if ($this->hasIpProtectionWarning($server)) {
            $isWarning = 1;
            $errorText .= ' (IP protection without subdomain)';
        }

        $isError = 0;
        if ($this->getNumericField($server, 'error_iteration') > 1) {
            $isError = 1;
            if ($this->getNumericField($server, 'error_id') === 1) {
                $errorText .= ' (Content path is not writable)';
            }
        }

        if ($this->getNumericField($server, 'error_streaming_iteration') > 1) {
            $isError = 1;
            $errorText .= $this->formatStreamingServerErrorText($this->getNumericField($server, 'error_streaming_id'));
        }

        return [
            'error_text' => $errorText,
            'free_space_percent' => $totalSpace > 0 ? '(' . round(($freeSpace / $totalSpace) * 100, 2) . '%)' : '',
            'is_error' => $isError,
            'is_warning' => $isWarning,
            'is_free_space_warning' => $isFreeSpaceWarning,
        ];
    }

    /**
     * @param array<string, mixed> $server
     */
    private function hasIpProtectionWarning(array $server): bool
    {
        if (
            $this->getNumericField($server, 'status_id') !== StatusFormatter::SERVER_ACTIVE
            || $this->getNumericField($server, 'control_script_url_lock_ip') !== 1
            || $this->getNumericField($server, 'is_remote') !== 1
        ) {
            return false;
        }

        $licenseDomain = $this->config->get('project_licence_domain', '');
        if (!is_string($licenseDomain) || $licenseDomain === '') {
            return false;
        }

        $urlHost = parse_url($this->getStringField($server, 'urls'), PHP_URL_HOST);
        return is_string($urlHost) && !str_ends_with($urlHost, $licenseDomain);
    }

    private function formatStreamingServerErrorText(int $errorId): string
    {
        return match ($errorId) {
            2 => ' (Control script is not responding)',
            3 => ' (Control script has wrong secret key)',
            4 => ' (Time is not synchronized)',
            5 => ' (Content check found errors)',
            6 => ' (CDN control script is missing)',
            7 => ' (Server is not using HTTPS)',
            default => '',
        };
    }

    private function getServerGroupMinFreeSpaceBytes(\PDO $db): int
    {
        try {
            $stmt = $db->prepare("
                SELECT value
                FROM {$this->table('options')}
                WHERE variable = 'SERVER_GROUP_MIN_FREE_SPACE_MB'
                LIMIT 1
            ");
            $stmt->execute();
            $value = $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }

        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value) * 1024 * 1024;
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

            $total = $stmt->fetchColumn();
            $this->io()->writeln((string) (is_numeric($total) ? (int) $total : 0));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error("Failed to count $label: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param list<array<string, mixed>> $servers
     * @return list<array<string, mixed>>
     */
    private function addStorageContentCounts(\PDO $db, array $servers): array
    {
        foreach ($servers as $index => $server) {
            $servers[$index]['content_count'] = 0;
        }

        $groupsByType = [];
        foreach ($servers as $server) {
            $groupId = $server['group_id'] ?? null;
            $contentTypeId = $server['content_type_id'] ?? null;
            if (!is_numeric($groupId) || !is_numeric($contentTypeId)) {
                continue;
            }
            $groupsByType[(int) $contentTypeId][] = (int) $groupId;
        }

        $counts = [];
        foreach ([1 => 'videos', 2 => 'albums'] as $contentTypeId => $table) {
            $groupIds = array_values(array_unique($groupsByType[$contentTypeId] ?? []));
            if ($groupIds === []) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            try {
                $stmt = $db->prepare("
                    SELECT server_group_id, COUNT(*) as content_count
                    FROM {$this->table($table)}
                    WHERE server_group_id IN ($placeholders)
                    GROUP BY server_group_id
                ");
                foreach ($groupIds as $index => $groupId) {
                    $stmt->bindValue($index + 1, $groupId, \PDO::PARAM_INT);
                }
                $stmt->execute();
            } catch (\Throwable) {
                continue;
            }

            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if (!is_array($row)) {
                    continue;
                }
                $groupId = $row['server_group_id'] ?? null;
                $count = $row['content_count'] ?? null;
                if (!is_numeric($groupId) || !is_numeric($count)) {
                    continue;
                }
                $counts[$contentTypeId][(int) $groupId] = (int) $count;
            }
        }

        foreach ($servers as $index => $server) {
            $groupId = $server['group_id'] ?? null;
            $contentTypeId = $server['content_type_id'] ?? null;
            if (!is_numeric($groupId) || !is_numeric($contentTypeId)) {
                continue;
            }
            $servers[$index]['content_count'] = $counts[(int) $contentTypeId][(int) $groupId] ?? 0;
        }

        return $servers;
    }

    private function formatStorageContentCount(int $contentCount, mixed $contentTypeId): string
    {
        $typeId = is_numeric($contentTypeId) ? (int) $contentTypeId : 0;
        $label = $typeId === 2 ? 'Albums' : 'Videos';

        return "$contentCount $label";
    }

    private function showServer(?string $id, InputInterface $input): int
    {
        $serverId = $this->getRequiredPositiveId($id, 'Server');
        if ($serverId === null) {
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
            $stmt->execute(['id' => $serverId]);
            /** @var array<string, mixed>|false $server */
            $server = $stmt->fetch();

            if ($server === false) {
                $this->io()->error("Server not found: $serverId");
                return self::FAILURE;
            }

            $info = $this->buildServerInfo($server);
            $info = array_merge($info, $this->buildConnectionInfo($server));
            $info = array_merge($info, $this->buildControlInfo($server));

            if (!$this->isTableFormat($input)) {
                return $this->displayDetailRows($input, $info, ['server_id' => (string) $serverId]);
            }

            $this->io()->section("Server #$serverId");
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
            $errors[] = 'Content path is not writable';
        }

        if ($errorStreamingIteration > 1) {
            $streamingErrors = [
                2 => 'Control script is not responding',
                3 => 'Control script has wrong secret key',
                4 => 'Time is not synchronized',
                5 => 'Content check found errors',
                6 => 'CDN control script is missing',
                7 => 'Server is not using HTTPS',
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

    private function showStats(InputInterface $input): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
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

            /** @var list<array<string, mixed>> $metricRows */
            $metricRows = [
                $this->metricRow('overall', 'Total Servers', $totalServers),
                $this->metricRow('overall', 'Active', $activeServers),
                $this->metricRow('overall', 'Inactive', $disabledServers),
                $this->metricRow('overall', 'With Errors', $serversWithErrors),
                $this->metricRow('overall', 'Total Space', $totalSpace, $this->formatBytes($totalSpace)),
                $this->metricRow('overall', 'Used Space', $usedSpace, $this->formatBytes($usedSpace) . " ({$usedPercent}%)"),
                $this->metricRow('overall', 'Free Space', $freeSpace, $this->formatBytes($freeSpace)),
                $this->metricRow('overall', 'Avg Load', $avgLoad, number_format($avgLoad, 2)),
            ];

            $overallInfo = [
                ['Total Servers', (string) $totalServers],
                ['Active', (string) $activeServers],
                ['Inactive', (string) $disabledServers],
                ['With Errors', $serversWithErrors > 0 ? "<fg=red>{$serversWithErrors}</>" : '0'],
                ['Total Space', $this->formatBytes($totalSpace)],
                ['Used Space', $this->formatBytes($usedSpace) . " ({$usedPercent}%)"],
                ['Free Space', $this->formatBytes($freeSpace)],
                ['Avg Load', number_format($avgLoad, 2)],
            ];

            // By content type
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
                $metricRows[] = $this->metricRow('by_content_type', $typeName . ' Servers', $count, (string) $count, $typeName);
                $metricRows[] = $this->metricRow('by_content_type', $typeName . ' Total Space', $total, $this->formatBytes($total), $typeName);
                $metricRows[] = $this->metricRow('by_content_type', $typeName . ' Free Space', $free, $this->formatBytes($free), $typeName);
            }

            // By connection type
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
                $connection = StatusFormatter::serverConnection($connTypeId, false);
                $metricRows[] = $this->metricRow('by_connection_type', $connection, $count, (string) $count, $connection);
            }

            if (!$this->isTableFormat($input)) {
                $this->displayMetricRows($input, $metricRows);
                return self::SUCCESS;
            }

            $this->io()->section('Storage Statistics');
            $this->renderTable(['Metric', 'Value'], $overallInfo);

            $this->io()->newLine();
            $this->io()->section('By Content Type');
            $this->renderTable(['Type', 'Servers', 'Total Space', 'Free Space'], $typeData);

            $this->io()->newLine();
            $this->io()->section('By Connection Type');
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

        $limit = $this->getPositiveIntOptionOrDefault($input, 'limit', 50);
        if ($limit === null) {
            return self::FAILURE;
        }

        if ($this->getStringOptionOrDefault($input, 'format', 'table') === 'count') {
            return $this->countRows(
                $db,
                "SELECT COUNT(*) FROM {$this->table('admin_servers_groups')}",
                [],
                'server groups'
            );
        }

        try {
            $stmtGroups = $db->prepare("
                SELECT g.*,
                    (SELECT COUNT(*) FROM {$this->table('admin_servers')} WHERE group_id = g.group_id) as server_count,
                    (SELECT COUNT(*) FROM {$this->table('admin_servers')} WHERE group_id = g.group_id AND status_id = 1) as active_count,
                    (SELECT COALESCE(MIN(free_space), 0) FROM {$this->table('admin_servers')} WHERE group_id = g.group_id) as free_space,
                    (SELECT COALESCE(MIN(free_space), 0) FROM {$this->table('admin_servers')} WHERE group_id = g.group_id) as min_free_space,
                    (SELECT COALESCE(MIN(total_space), 0) FROM {$this->table('admin_servers')} WHERE group_id = g.group_id) as total_space,
                    (SELECT COALESCE(AVG(`load`), 0) FROM {$this->table('admin_servers')} WHERE group_id = g.group_id) as `load`,
                    (
                        SELECT COUNT(*) FROM {$this->table('videos')} WHERE server_group_id = g.group_id
                    ) + (
                        SELECT COUNT(*) FROM {$this->table('albums')} WHERE server_group_id = g.group_id
                    ) as total_content_count
                FROM {$this->table('admin_servers_groups')} g
                ORDER BY g.group_id ASC
                LIMIT :limit
            ");
            $stmtGroups->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmtGroups->execute();
            /** @var list<array<string, mixed>> $groups */
            $groups = $stmtGroups->fetchAll(\PDO::FETCH_ASSOC);
            $minFreeSpaceBytes = $this->getServerGroupMinFreeSpaceBytes($db);

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

            $transformed = array_map(function (array $group) use ($videoGroups, $albumGroups, $minFreeSpaceBytes): array {
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
                $load = isset($group['load']) && is_numeric($group['load'])
                    ? (float) $group['load'] : 0.0;

                $contentCount = isset($group['total_content_count']) && is_numeric($group['total_content_count'])
                    ? (int) $group['total_content_count']
                    : ($contentType === 1 ? ($videoGroups[$groupId] ?? 0) : ($albumGroups[$groupId] ?? 0));
                $contentTypeStr = $contentType === 1 ? 'Videos' : 'Albums';

                $titleVal = $group['title'] ?? '';
                $title = is_string($titleVal) ? $titleVal : '';
                $computedAdminFields = $this->buildKvsAdminServerGroupComputedFields(
                    $statusId,
                    $totalSpace,
                    $minFreeSpace,
                    $minFreeSpaceBytes
                );

                return [
                    ...$group,
                    ...$computedAdminFields,
                    'group_id' => $groupId,
                    'id' => $groupId,
                    'title' => $title,
                    'status_id' => $statusId,
                    'status' => StatusFormatter::server($statusId, false),
                    'content_type_id' => $contentType,
                    'content_type' => $contentTypeStr,
                    'servers' => "{$activeCount}/{$serverCount}",
                    'servers_count' => $serverCount,
                    'servers_amount' => $serverCount,
                    'total_servers_amount' => $serverCount,
                    'active_servers_amount' => $activeCount,
                    'content_count' => number_format($contentCount),
                    'total_content' => number_format($contentCount) . " {$contentTypeStr}",
                    'total_space' => $this->formatBytes($totalSpace),
                    'free_space' => $this->formatBytes($minFreeSpace),
                    'min_free' => $this->formatBytes($minFreeSpace),
                    'load' => number_format($load, 2),
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

    /**
     * @return array{error_text: string, free_space_percent: string, is_warning: int, is_free_space_warning: int}
     */
    private function buildKvsAdminServerGroupComputedFields(
        int $statusId,
        int $totalSpace,
        int $freeSpace,
        int $minFreeSpaceBytes
    ): array {
        $isFreeSpaceWarning = $statusId === StatusFormatter::SERVER_ACTIVE
            && $minFreeSpaceBytes > 0
            && $freeSpace < $minFreeSpaceBytes;

        return [
            'error_text' => $isFreeSpaceWarning ? ' (No free space is available)' : '',
            'free_space_percent' => $totalSpace > 0 ? '(' . round(($freeSpace / $totalSpace) * 100, 2) . '%)' : '',
            'is_warning' => $isFreeSpaceWarning ? 1 : 0,
            'is_free_space_warning' => $isFreeSpaceWarning ? 1 : 0,
        ];
    }

    private function showGroup(string $id, InputInterface $input): int
    {
        $requestedGroupId = $this->getRequiredPositiveId($id, 'Server group');
        if ($requestedGroupId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table('admin_servers_groups')} WHERE group_id = :id");
            $stmt->execute(['id' => $requestedGroupId]);
            /** @var array<string, mixed>|false $group */
            $group = $stmt->fetch();

            if ($group === false) {
                $this->io()->error("Server group not found: $requestedGroupId");
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

            // Get content count
            $contentTable = $contentType === 1 ? 'videos' : 'albums';
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->table($contentTable)} WHERE server_group_id = :id");
            $stmt->execute(['id' => $requestedGroupId]);
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

            $stmt = $db->prepare("
                SELECT * FROM {$this->table('admin_servers')}
                WHERE group_id = :id
                ORDER BY server_id ASC
            ");
            $stmt->execute(['id' => $requestedGroupId]);
            /** @var list<array<string, mixed>> $servers */
            $servers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $serverData = [];
            $serverRecords = [];
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
                $status = StatusFormatter::server($serverStatus);
                $freeSpaceDisplay = $this->formatBytes($freeSpace) . " ({$freePercent}%)";

                $serverData[] = [
                    (string) $serverId,
                    $serverTitle,
                    $status,
                    $freeSpaceDisplay,
                    number_format($serverLoad, 2),
                ];
                $serverRecords[] = [
                    'server_id' => $serverId,
                    'title' => $serverTitle,
                    'status' => $this->stripConsoleMarkup($status),
                    'free_space' => $freeSpaceDisplay,
                    'load' => number_format($serverLoad, 2),
                ];
            }

            if (!$this->isTableFormat($input)) {
                return $this->displayDetailRows($input, $info, [
                    'group_id' => $groupId,
                    'servers' => $serverRecords,
                ]);
            }

            $this->io()->section("Server Group #$requestedGroupId: $title");
            $this->renderTable(['Property', 'Value'], $info);

            // Servers in group
            $this->io()->newLine();
            $this->io()->section('Servers in Group');

            if (count($serverData) === 0) {
                $this->io()->text('No servers in this group');
            } else {
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
