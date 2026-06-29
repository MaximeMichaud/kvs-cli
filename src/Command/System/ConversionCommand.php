<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\Traits\ExperimentalCommandTrait;
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
    description: '[EXPERIMENTAL] Manage KVS conversion servers',
    aliases: ['conversion']
)]
class ConversionCommand extends BaseCommand
{
    use ExperimentalCommandTrait;
    use ToggleStatusTrait;

    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml', 'count'];

    private const TASK_TYPE_LABELS = [
        'video_admins' => 'New videos from admins',
        'video_feeds' => 'New videos from feeds',
        'video_grabbers' => 'New videos from grabbers',
        'video_users' => 'New videos from users',
        'video_update' => 'Updating video files',
        'album_admins' => 'New albums from admins',
        'album_grabbers' => 'New albums from grabbers',
        'album_users' => 'New albums from users',
        'album_update' => 'Updating album files',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: list|show|enable|disable|debug-on|debug-off|log|config|stats'
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
  show <id>      Show server details (tasks, options, connection)
  enable <id>    Enable/activate a server
  disable <id>   Disable/deactivate a server
  debug-on <id>  Enable debug mode
  debug-off <id> Disable debug mode
  log <id>       View server conversion log
  config <id>    View server configuration (libraries, paths)
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
  <fg=green>kvs conversion log 1</>
  <fg=green>kvs conversion config 1</>
  <fg=green>kvs conversion stats</>
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
            'debug-on' => $this->toggleDebug($id, true),
            'debug-off' => $this->toggleDebug($id, false),
            'log' => $this->showLog($id, $input),
            'config' => $this->showConfig($id, $input),
            'stats' => $this->showStats($input),
            default => $this->failUnknownAction(
                'conversion',
                $action,
                ['list', 'show', 'enable', 'disable', 'debug-on', 'debug-off', 'log', 'config', 'stats']
            ),
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
                     WHERE status_id IN (0,1) AND server_id = s.server_id) as tasks_amount,
                    (SELECT COUNT(*) FROM {$this->table('background_tasks_history')}
                     WHERE server_id = s.server_id) as finished_tasks_amount
                 FROM {$this->table('admin_conversion_servers')} s
                 WHERE 1=1";

        $params = [];

        $status = $this->getStringOption($input, 'status');
        if ($status !== null) {
            $statusMap = [
                'active' => 1,
                '1' => 1,
                'disabled' => 0,
                'inactive' => 0,
                '0' => 0,
                'init' => 2,
                'initializing' => 2,
                '2' => 2,
            ];
            $statusKey = strtolower($status);
            if (!array_key_exists($statusKey, $statusMap)) {
                $this->io()->error('Invalid value for --status (use: active, disabled, or init)');
                return self::FAILURE;
            }
            $query .= " AND s.status_id = :status";
            $params['status'] = $statusMap[$statusKey];
        }

        // Errors filter
        if ($input->getOption('errors')) {
            $query .= " AND s.status_id != 0 AND s.error_iteration > 1";
        }

        $query .= " ORDER BY s.server_id DESC LIMIT :limit";

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
            $latestApiVersion = $this->getLatestConversionApiVersion($db);

            $transformed = array_map(function (array $server) use ($latestApiVersion): array {
                $statusId = $this->getNumericField($server, 'status_id');
                $priority = $this->getNumericField($server, 'process_priority');
                $totalSpace = $this->getNumericField($server, 'total_space');
                $freeSpace = $this->getNumericField($server, 'free_space');
                $load = $this->getFloatField($server, 'load');
                $errorIter = $this->getNumericField($server, 'error_iteration');
                $isDebug = $this->getNumericField($server, 'is_debug_enabled');
                $maxTasks = $this->getNumericField($server, 'max_tasks');
                $isMaxTasksPriority = $this->getNumericField($server, 'max_tasks_priority') === 1;
                $tasksAmount = $this->getNumericField($server, 'tasks_amount');
                $finishedTasksAmount = $this->getNumericField($server, 'finished_tasks_amount');
                $taskTypes = $this->formatTaskTypesForList($this->getStringField($server, 'task_types'));
                $computedAdminFields = $this->buildKvsAdminConversionComputedFields(
                    $server,
                    $totalSpace,
                    $freeSpace,
                    $latestApiVersion
                );
                $apiVersion = $computedAdminFields['api_version'];

                return [
                    ...$server,
                    ...$computedAdminFields,
                    'server_id' => $server['server_id'] ?? 0,
                    'id' => $server['server_id'] ?? 0,
                    'title' => $server['title'] ?? '',
                    'status_id' => $statusId,
                    'status' => StatusFormatter::conversion($statusId, false),
                    'priority' => StatusFormatter::conversionPriority($priority, false),
                    'max_tasks' => $isMaxTasksPriority ? "{$maxTasks} (prioritize)" : $maxTasks,
                    'tasks_amount' => $tasksAmount,
                    'finished_tasks_amount' => $finishedTasksAmount,
                    'task_types' => $taskTypes,
                    'tasks_pending' => $tasksAmount,
                    'tasks_completed' => $finishedTasksAmount,
                    'free_space' => $this->formatBytes($freeSpace),
                    'load' => number_format($load, 2),
                    'api_version' => $apiVersion,
                    'heartbeat' => $this->formatHeartbeat($this->getStringField($server, 'heartbeat_date')),
                    'has_error' => $statusId !== 0 && $errorIter > 1 ? 'Yes' : 'No',
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

    /**
     * @param array<string, mixed> $server
     * @return array{api_version: string, error_text: string, free_space_percent: string, has_debug_log: int, has_old_api: int, is_error: int}
     */
    private function buildKvsAdminConversionComputedFields(
        array $server,
        int $totalSpace,
        int $freeSpace,
        string $latestApiVersion
    ): array {
        $serverId = $this->getNumericField($server, 'server_id');
        $statusId = $this->getNumericField($server, 'status_id');
        $errorIteration = $this->getNumericField($server, 'error_iteration');
        $errorId = $this->getNumericField($server, 'error_id');
        $isDebug = $this->getNumericField($server, 'is_debug_enabled');
        $apiVersion = $this->getStringField($server, 'api_version');
        $hasOldApi = 0;

        if ($this->isConversionApiVersionObsolete($apiVersion, $latestApiVersion)) {
            $apiVersion .= ' (obsolete)';
            $hasOldApi = 1;
        }

        $isError = 0;
        $errorText = '';
        if ($statusId !== StatusFormatter::CONVERSION_DISABLED && $errorIteration > 1) {
            $isError = 1;
            $errorText = $this->formatConversionServerErrorText($errorId);
        } elseif ($isDebug === 1) {
            $errorText = '(This server has debug log enabled)';
        }

        return [
            'api_version' => $apiVersion,
            'error_text' => $errorText,
            'free_space_percent' => $totalSpace > 0 ? '(' . round(($freeSpace / $totalSpace) * 100, 2) . '%)' : '',
            'has_debug_log' => is_file(
                $this->config->getKvsPath() . '/admin/logs/debug_conversion_server_' . $serverId . '.txt'
            ) ? 1 : 0,
            'has_old_api' => $hasOldApi,
            'is_error' => $isError,
        ];
    }

    private function formatConversionServerErrorText(int $errorId): string
    {
        return match ($errorId) {
            1 => '(Conversion path is not writable)',
            2 => '(Conversion script is not working)',
            3 => '(Conversion script executed more than 15 minutes ago)',
            4 => '(Some libraries are not configured correctly on this server)',
            5 => '(This server has obsolete API version)',
            6 => "(This server didn't report any activity for the last 2 hours)",
            default => '',
        };
    }

    private function isConversionApiVersionObsolete(string $apiVersion, string $latestApiVersion): bool
    {
        if ($apiVersion === '' || $latestApiVersion === '') {
            return false;
        }

        return (int) str_replace('.', '', $apiVersion) < (int) str_replace('.', '', $latestApiVersion);
    }

    private function getLatestConversionApiVersion(\PDO $db): string
    {
        try {
            $stmt = $db->prepare("
                SELECT value
                FROM {$this->table('options')}
                WHERE variable = 'SYSTEM_CONVERSION_API_VERSION'
                LIMIT 1
            ");
            $stmt->execute();
            $value = $stmt->fetchColumn();
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
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
                SELECT s.*,
                    (SELECT COUNT(*) FROM {$this->table('background_tasks')}
                     WHERE status_id IN (0,1) AND server_id = s.server_id) as tasks_pending,
                    (SELECT COUNT(*) FROM {$this->table('background_tasks_history')}
                     WHERE server_id = s.server_id) as tasks_completed
                FROM {$this->table('admin_conversion_servers')} s
                WHERE s.server_id = :id
            ");
            $stmt->execute(['id' => $serverId]);
            /** @var array<string, mixed>|false $server */
            $server = $stmt->fetch();

            if ($server === false) {
                $this->io()->error("Conversion server not found: $serverId");
                return self::FAILURE;
            }

            $info = $this->buildServerInfo($server);
            $info = array_merge($info, $this->buildConnectionInfo($server));

            if (!$this->isTableFormat($input)) {
                return $this->displayDetailRows($input, $info, [
                    'server_id' => (string) $serverId,
                    'task_types' => $this->parseTaskTypes($this->getStringField($server, 'task_types')),
                    'allow_any_tasks' => $this->getNumericField($server, 'is_allow_any_tasks') === 1,
                ]);
            }

            $this->io()->section("Conversion Server #$serverId");
            $this->renderTable(['Property', 'Value'], $info);

            // Display task types
            $this->displayTaskTypes($server);

            // Display server options
            $this->displayServerOptions($server);

            // Display errors if any
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

    /**
     * Display task types assigned to server.
     *
     * @param array<string, mixed> $server
     */
    private function displayTaskTypes(array $server): void
    {
        $taskTypesStr = $this->getStringField($server, 'task_types');
        $isAllowAny = $this->getNumericField($server, 'is_allow_any_tasks') === 1;

        $enabledTypes = $this->parseTaskTypes($taskTypesStr);

        $this->io()->newLine();
        $this->io()->section('Task Types');

        if ($enabledTypes === []) {
            $this->io()->text('<fg=yellow>No specific task types assigned (processes all types)</>');
        } else {
            foreach (self::TASK_TYPE_LABELS as $type => $label) {
                $enabled = in_array($type, $enabledTypes, true);
                $icon = $enabled ? '<fg=green>✓</>' : '<fg=gray>-</>';
                $text = $enabled ? $label : "<fg=gray>$label</>";
                $this->io()->text("  $icon $text");
            }
        }

        if ($isAllowAny) {
            $this->io()->newLine();
            $this->io()->text('<fg=cyan>✓ Process any available task when free</>');
        }
    }

    private function formatTaskTypesForList(string $taskTypes): string
    {
        $enabledTypes = $this->parseTaskTypes($taskTypes);
        $allTypes = array_keys(self::TASK_TYPE_LABELS);

        if ($enabledTypes === [] || count($enabledTypes) === count($allTypes)) {
            return 'All';
        }

        if (count($enabledTypes) < count($allTypes) / 2) {
            $values = [];
            foreach ($enabledTypes as $type) {
                $values[] = '+' . (self::TASK_TYPE_LABELS[$type] ?? $type);
            }

            return implode(', ', $values);
        }

        $values = [];
        foreach ($allTypes as $type) {
            if (!in_array($type, $enabledTypes, true)) {
                $values[] = '-' . self::TASK_TYPE_LABELS[$type];
            }
        }

        return implode(', ', $values);
    }

    /**
     * @return list<string>
     */
    private function parseTaskTypes(string $taskTypes): array
    {
        if ($taskTypes === '') {
            return [];
        }

        $unserialized = @unserialize($taskTypes, ['allowed_classes' => false]);
        if (is_array($unserialized)) {
            $types = [];
            foreach ($unserialized as $value) {
                if (is_string($value)) {
                    $types[] = $value;
                }
            }
            return $types;
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $taskTypes)),
            static fn (string $value): bool => $value !== ''
        ));
    }

    /**
     * Display server options.
     *
     * @param array<string, mixed> $server
     */
    private function displayServerOptions(array $server): void
    {
        $maxTasks = $this->getNumericField($server, 'max_tasks');
        $isPriority = $this->getNumericField($server, 'max_tasks_priority') === 1;
        $optionStorage = $this->getNumericField($server, 'option_storage_servers') === 1;
        $optionPull = $this->getNumericField($server, 'option_pull_source_files') === 1;
        $isDebug = $this->getNumericField($server, 'is_debug_enabled') === 1;

        $this->io()->newLine();
        $this->io()->section('Options');

        $options = [
            ['Maximum concurrent tasks', (string) $maxTasks],
            ['Prioritize for new tasks', $isPriority ? '<fg=green>Yes</>' : 'No'],
            ['Copy content to storage servers', $optionStorage ? '<fg=green>Yes</>' : 'No'],
            ['Pull source files', $optionPull ? '<fg=green>Yes</>' : 'No'],
            ['Debug mode', $isDebug ? '<fg=yellow>Enabled</>' : 'Disabled'],
        ];

        $this->renderTable(['Option', 'Value'], $options);
    }

    /**
     * Show server conversion log.
     */
    private function showLog(?string $id, InputInterface $input): int
    {
        $serverId = $this->getRequiredPositiveId($id, 'Server');
        if ($serverId === null) {
            return self::FAILURE;
        }

        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("
                SELECT path, connection_type_id, title
                FROM {$this->table('admin_conversion_servers')}
                WHERE server_id = :id
            ");
            $stmt->execute(['id' => $serverId]);
            /** @var array<string, mixed>|false $server */
            $server = $stmt->fetch();

            if ($server === false) {
                $this->io()->error("Conversion server not found: $serverId");
                return self::FAILURE;
            }

            $path = $this->resolveConversionServerPath($this->getStringField($server, 'path'));
            $connType = $this->getNumericField($server, 'connection_type_id');

            // Only local and mount servers have readable logs
            if ($connType !== 0 && $connType !== 1) {
                $this->io()->warning('Log viewing only available for Local/Mount servers');
                return self::FAILURE;
            }

            $logFile = $this->getConversionLogFile($path);

            if (!file_exists($logFile)) {
                if (!$this->isTableFormat($input)) {
                    $this->displayConversionFileRows($input, [[
                        'server_id' => (string) $serverId,
                        'title' => $this->getStringField($server, 'title'),
                        'file' => $logFile,
                        'exists' => false,
                        'readable' => false,
                        'size_bytes' => 0,
                        'line_count' => 0,
                        'content' => null,
                        'message' => 'Log file not found',
                    ]]);
                    return self::FAILURE;
                }

                $this->io()->warning("Log file not found: $logFile");
                return self::FAILURE;
            }

            $content = file_get_contents($logFile);
            if ($content === false) {
                if (!$this->isTableFormat($input)) {
                    $this->displayConversionFileRows($input, [[
                        'server_id' => (string) $serverId,
                        'title' => $this->getStringField($server, 'title'),
                        'file' => $logFile,
                        'exists' => true,
                        'readable' => false,
                        'size_bytes' => 0,
                        'line_count' => 0,
                        'content' => null,
                        'message' => 'Cannot read log file',
                    ]]);
                    return self::FAILURE;
                }

                $this->io()->error("Cannot read log file: $logFile");
                return self::FAILURE;
            }

            if (!$this->isTableFormat($input)) {
                $this->displayConversionFileRows($input, [[
                    'server_id' => (string) $serverId,
                    'title' => $this->getStringField($server, 'title'),
                    'file' => $logFile,
                    'exists' => true,
                    'readable' => true,
                    'size_bytes' => strlen($content),
                    'line_count' => $this->countLines($content),
                    'content' => $content,
                    'message' => trim($content) === '' ? 'Log file is empty' : '',
                ]]);
                return self::SUCCESS;
            }

            $title = $this->getStringField($server, 'title');
            $this->io()->section("Conversion Log - $title");

            if (trim($content) === '') {
                $this->io()->info('Log file is empty');
            } else {
                $this->io()->text($content);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to read log: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Show server configuration.
     */
    private function showConfig(?string $id, InputInterface $input): int
    {
        $serverId = $this->getRequiredPositiveId($id, 'Server');
        if ($serverId === null) {
            return self::FAILURE;
        }

        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->prepare("
                SELECT path, connection_type_id, title
                FROM {$this->table('admin_conversion_servers')}
                WHERE server_id = :id
            ");
            $stmt->execute(['id' => $serverId]);
            /** @var array<string, mixed>|false $server */
            $server = $stmt->fetch();

            if ($server === false) {
                $this->io()->error("Conversion server not found: $serverId");
                return self::FAILURE;
            }

            $path = $this->resolveConversionServerPath($this->getStringField($server, 'path'));
            $connType = $this->getNumericField($server, 'connection_type_id');

            // Only local and mount servers have readable config
            if ($connType !== 0 && $connType !== 1) {
                $this->io()->warning('Config viewing only available for Local/Mount servers');
                return self::FAILURE;
            }

            $title = $this->getStringField($server, 'title');

            // Read config.properties
            $configFile = rtrim($path, '/') . '/config.properties';

            if (!file_exists($configFile)) {
                if (!$this->isTableFormat($input)) {
                    $this->displayConversionFileRows($input, [[
                        'server_id' => (string) $serverId,
                        'title' => $title,
                        'file' => $configFile,
                        'exists' => false,
                        'readable' => false,
                        'size_bytes' => 0,
                        'content' => null,
                        'heartbeat_file' => rtrim($path, '/') . '/heartbeat.dat',
                        'heartbeat_exists' => false,
                        'libraries' => [],
                        'message' => 'Config file not found',
                    ]]);
                    return self::FAILURE;
                }

                $this->io()->warning("Config file not found: $configFile");
                return self::FAILURE;
            }

            $content = file_get_contents($configFile);
            if ($content === false) {
                if (!$this->isTableFormat($input)) {
                    $this->displayConversionFileRows($input, [[
                        'server_id' => (string) $serverId,
                        'title' => $title,
                        'file' => $configFile,
                        'exists' => true,
                        'readable' => false,
                        'size_bytes' => 0,
                        'content' => null,
                        'heartbeat_file' => rtrim($path, '/') . '/heartbeat.dat',
                        'heartbeat_exists' => file_exists(rtrim($path, '/') . '/heartbeat.dat'),
                        'libraries' => [],
                        'message' => 'Cannot read config file',
                    ]]);
                    return self::FAILURE;
                }

                $this->io()->error("Cannot read config file: $configFile");
                return self::FAILURE;
            }

            $heartbeatFile = rtrim($path, '/') . '/heartbeat.dat';
            $libraries = $this->readConversionLibraries($heartbeatFile);

            if (!$this->isTableFormat($input)) {
                $this->displayConversionFileRows($input, [[
                    'server_id' => (string) $serverId,
                    'title' => $title,
                    'file' => $configFile,
                    'exists' => true,
                    'readable' => true,
                    'size_bytes' => strlen($content),
                    'content' => $content,
                    'heartbeat_file' => $heartbeatFile,
                    'heartbeat_exists' => file_exists($heartbeatFile),
                    'libraries' => $libraries,
                    'message' => '',
                ]]);
                return self::SUCCESS;
            }

            $this->io()->section("Configuration - $title");

            // Parse and display config
            $this->io()->text('<fg=cyan>Configuration File:</>');
            $this->io()->text($content);

            // Read heartbeat.dat for library versions
            if ($libraries !== []) {
                $this->io()->newLine();
                $this->io()->text('<fg=cyan>Conversion Libraries:</>');

                $rows = array_map(
                    static fn (array $library): array => [
                        $library['name'],
                        $library['command'],
                        $library['version'],
                    ],
                    $libraries
                );
                $this->renderTable(['Library', 'Command', 'Version'], $rows);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to read config: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function resolveConversionServerPath(string $path): string
    {
        $path = rtrim($path, '/');
        if ($path !== '' && is_dir($path)) {
            return $path;
        }

        $normalized = str_replace('\\', '/', $path);
        if (str_ends_with($normalized, '/admin/data/conversion')) {
            $candidate = $this->config->getAdminPath() . '/data/conversion';
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $path;
    }

    private function getConversionLogFile(string $path): string
    {
        $basePath = rtrim($path, '/');
        foreach (['cron_log.txt', 'log.txt'] as $filename) {
            $candidate = $basePath . '/' . $filename;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $basePath . '/cron_log.txt';
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
        $serverId = $this->getRequiredPositiveId($id, 'Server');
        if ($serverId === null) {
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            // Check if server exists
            $stmt = $db->prepare("SELECT title, is_debug_enabled FROM {$this->table('admin_conversion_servers')} WHERE server_id = :id");
            $stmt->execute(['id' => $serverId]);
            /** @var array<string, mixed>|false $server */
            $server = $stmt->fetch();

            if ($server === false) {
                $this->io()->error("Conversion server not found: {$serverId}");
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
            $stmt->execute(['debug' => $targetDebug, 'id' => $serverId]);

            $title = $this->getStringField($server, 'title');
            $action = $enable ? 'enabled' : 'disabled';
            $this->io()->success("Debug {$action} for server '{$title}'");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to toggle debug: ' . $e->getMessage());
            return self::FAILURE;
        }
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
                    SUM(CASE WHEN status_id = 2 THEN 1 ELSE 0 END) as init_servers,
                    SUM(total_space) as total_space,
                    SUM(free_space) as free_space,
                    AVG(`load`) as avg_load,
                    SUM(max_tasks) as total_capacity,
                    SUM(CASE WHEN status_id != 0 AND error_iteration > 1 THEN 1 ELSE 0 END) as servers_with_errors,
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

            /** @var list<array<string, mixed>> $metricRows */
            $metricRows = [
                $this->metricRow('overall', 'Total Servers', $totalServers),
                $this->metricRow('overall', 'Active', $activeServers),
                $this->metricRow('overall', 'Inactive', $disabledServers),
                $this->metricRow('overall', 'Initializing', $initServers),
                $this->metricRow('overall', 'With Errors', $serversWithErrors),
                $this->metricRow('overall', 'Debug Enabled', $debugEnabled),
                $this->metricRow('overall', 'Total Capacity', $totalCapacity, "{$totalCapacity} concurrent tasks"),
                $this->metricRow('overall', 'Total Space', $totalSpace, $this->formatBytes($totalSpace)),
                $this->metricRow('overall', 'Used Space', $usedSpace, $this->formatBytes($usedSpace) . " ({$usedPercent}%)"),
                $this->metricRow('overall', 'Free Space', $freeSpace, $this->formatBytes($freeSpace)),
                $this->metricRow('overall', 'Avg Load', $avgLoad, number_format($avgLoad, 2)),
            ];

            $overallInfo = [
                ['Total Servers', (string) $totalServers],
                ['Active', (string) $activeServers],
                ['Inactive', (string) $disabledServers],
                ['Initializing', (string) $initServers],
                ['With Errors', $serversWithErrors > 0 ? "<fg=red>{$serversWithErrors}</>" : '0'],
                ['Debug Enabled', $debugEnabled > 0 ? "<fg=yellow>{$debugEnabled}</>" : '0'],
                ['Total Capacity', "{$totalCapacity} concurrent tasks"],
                ['Total Space', $this->formatBytes($totalSpace)],
                ['Used Space', $this->formatBytes($usedSpace) . " ({$usedPercent}%)"],
                ['Free Space', $this->formatBytes($freeSpace)],
                ['Avg Load', number_format($avgLoad, 2)],
            ];

            // Task stats
            $stmtTasks = $db->query("
                SELECT
                    SUM(CASE WHEN status_id = 0 THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status_id = 1 THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN status_id = 2 THEN 1 ELSE 0 END) as failed
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
            $failed = $this->getNumericField($taskStats, 'failed');
            $completed = $this->getNumericField($historyStats, 'total');

            $taskInfo = [
                ['Pending', (string) $pending],
                ['Processing', (string) $processing],
                ['Failed', (string) $failed],
                ['Completed (history)', number_format($completed)],
            ];
            $metricRows[] = $this->metricRow('task_queue', 'Pending', $pending);
            $metricRows[] = $this->metricRow('task_queue', 'Processing', $processing);
            $metricRows[] = $this->metricRow('task_queue', 'Failed', $failed);
            $metricRows[] = $this->metricRow('task_queue', 'Completed (history)', $completed, number_format($completed));

            if (!$this->isTableFormat($input)) {
                $this->displayMetricRows($input, $metricRows);
                return self::SUCCESS;
            }

            $this->io()->section('Conversion Statistics');
            $this->renderTable(['Metric', 'Value'], $overallInfo);

            $this->io()->newLine();
            $this->io()->section('Task Queue');
            $this->renderTable(['Status', 'Count'], $taskInfo);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io()->error('Failed to fetch stats: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function displayConversionFileRows(InputInterface $input, array $rows): void
    {
        $this->displayFormattedRows($input, $rows, array_keys($rows[0] ?? []));
    }

    private function countLines(string $content): int
    {
        if ($content === '') {
            return 0;
        }

        return substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1);
    }

    /**
     * @return list<array{name: string, command: string, version: string}>
     */
    private function readConversionLibraries(string $heartbeatFile): array
    {
        if (!file_exists($heartbeatFile)) {
            return [];
        }

        $heartbeatContent = file_get_contents($heartbeatFile);
        if ($heartbeatContent === false) {
            return [];
        }

        $heartbeat = @unserialize($heartbeatContent, ['allowed_classes' => false]);
        if (!is_array($heartbeat) || !isset($heartbeat['libraries']) || !is_array($heartbeat['libraries'])) {
            return [];
        }

        $rows = [];
        foreach ($heartbeat['libraries'] as $name => $info) {
            if (!is_array($info)) {
                continue;
            }

            $command = isset($info['path']) && is_string($info['path']) && $info['path'] !== '' ? $info['path'] : 'N/A';
            $message = isset($info['message']) && is_string($info['message']) ? $info['message'] : '';
            $rows[] = [
                'name' => (string) $name,
                'command' => $command,
                'version' => explode("\n", $message)[0],
            ];
        }

        return $rows;
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
