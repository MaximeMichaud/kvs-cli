<?php

namespace KVS\CLI\Command;

use KVS\CLI\Output\Formatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'plugin',
    description: 'Manage KVS plugins',
    aliases: ['plugins', 'plug']
)]
class PluginCommand extends BaseCommand
{
    private const HIDDEN_PLUGIN_IDS = ['push_notifications', 'awe_black_label'];
    private const OUTPUT_FORMATS = ['table', 'csv', 'json', 'yaml', 'count', 'ids'];
    private const LIST_FILTER_OPTIONS = ['status', 'type'];

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Manage KVS plugins - list, inspect, and get information about installed plugins.

<info>ACTIONS:</info>
  list              List all plugins (default)
  show <id>         Show plugin details
  path <id>         Show plugin directory path
  status            Show plugin statistics

<info>LIST OPTIONS:</info>
  --status=<status>     Filter by status (active|inactive|all)
  --type=<type>         Filter by type (manual|cron|api|process_object)
  --fields=<fields>     Comma-separated fields to display
  --field=<field>       Display single field value
  --format=<format>     Output format: table, csv, json, yaml, count

<info>AVAILABLE FIELDS:</info>
  id, name, author, version, kvs_version
  status, enabled, types, title, description

<info>EXAMPLES:</info>
  <comment>kvs plugin list</comment>
  <comment>kvs plugin list --status=active</comment>
  <comment>kvs plugin list --fields=id,name,version,status</comment>
  <comment>kvs plugin list --format=json</comment>
  <comment>kvs plugin show backup</comment>
  <comment>kvs plugin path backup</comment>
  <comment>kvs plugin status</comment>

<info>NOTE:</info>
  This command provides read-only access to plugins.
  Activation/deactivation is done through KVS admin panel.
HELP
            )
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: list, show, path, status', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'Plugin ID')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (active|inactive|all)', 'all')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by type (manual|cron)')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fields to display')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'Display single field value')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, csv, json, yaml, count, ids', 'table')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Disable truncation of long text fields');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->validateOutputFormat($input, self::OUTPUT_FORMATS) === null) {
            return self::FAILURE;
        }

        $action = $this->getStringArgument($input, 'action') ?? 'list';
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listPlugins($input),
            'show' => $this->showPlugin($id, $input),
            'path' => $this->showPluginPath($id, $input),
            'status' => $this->showStatus($input),
            default => $this->failUnknownAction(
                'plugin',
                $action,
                ['list', 'show', 'path', 'status']
            ),
        };
    }

    private function listPlugins(InputInterface $input): int
    {
        $allPlugins = $this->getAllPlugins();
        $plugins = $allPlugins;

        if ($plugins === []) {
            if ($this->isTableFormat($input)) {
                $this->io()->info('No plugins found');
            } else {
                $formatter = new Formatter(
                    $input->getOptions(),
                    ['id', 'name', 'version', 'status', 'types']
                );
                $formatter->display([], $this->io());
            }
            return self::SUCCESS;
        }

        // Apply filters
        $statusFilter = $this->getStringOption($input, 'status');
        $typeFilter = $this->getStringOption($input, 'type');

        if ($statusFilter !== null && $statusFilter !== 'all') {
            if (!in_array($statusFilter, ['active', 'inactive'], true)) {
                $this->io()->error('Invalid status "' . $statusFilter . '". Valid values: active, inactive, all');
                return self::FAILURE;
            }

            $plugins = $this->filterPluginsByStatus($plugins, $statusFilter);
        }

        if ($typeFilter !== null && $typeFilter !== '') {
            $typeFilter = strtolower($typeFilter);
            $availableTypes = $this->getAvailableTypes($allPlugins);
            if (!in_array($typeFilter, $availableTypes, true)) {
                $validTypes = $availableTypes === [] ? 'none' : implode(', ', $availableTypes);
                $this->io()->error('Invalid type "' . $typeFilter . '". Valid values: ' . $validTypes);
                return self::FAILURE;
            }

            $plugins = $this->filterPluginsByType($plugins, $typeFilter);
        }

        // Transform plugins to flattened format for Formatter
        $transformedPlugins = array_map(
            fn (array $plugin): array => $this->formatPluginForList($plugin),
            $plugins
        );

        // Format and display output using centralized Formatter
        $formatter = new Formatter(
            $input->getOptions(),
            ['id', 'name', 'version', 'status', 'types']
        );
        $formatter->display(array_values($transformedPlugins), $this->io());

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $plugins
     * @return array<int, array<string, mixed>>
     */
    private function filterPluginsByStatus(array $plugins, string $statusFilter): array
    {
        return array_filter($plugins, function (array $plugin) use ($statusFilter): bool {
            $isEnabled = $plugin['is_enabled'] ?? false;
            if ($statusFilter === 'active') {
                return (bool)$isEnabled;
            }
            return !(bool)$isEnabled;
        });
    }

    /**
     * @param array<int, array<string, mixed>> $plugins
     * @return array<int, array<string, mixed>>
     */
    private function filterPluginsByType(array $plugins, string $typeFilter): array
    {
        return array_filter($plugins, function (array $plugin) use ($typeFilter): bool {
            $types = $plugin['types'] ?? [];
            if (!is_array($types)) {
                return false;
            }
            $types = array_map(
                static fn (mixed $type): string => is_string($type) ? strtolower(trim($type)) : '',
                $types
            );
            return in_array($typeFilter, $types, true);
        });
    }

    /**
     * @param array<string, mixed> $plugin
     * @return array<string, string>
     */
    private function formatPluginForList(array $plugin): array
    {
        $filesOk = (bool)($plugin['files_ok'] ?? false);
        $syntaxOk = (bool)($plugin['syntax_ok'] ?? false);
        $compatible = (bool)($plugin['compatible'] ?? false);
        $isEnabled = (bool)($plugin['is_enabled'] ?? false);
        $types = $plugin['types'] ?? [];
        if (!is_array($types)) {
            $types = [];
        }
        $isValid = $filesOk && $syntaxOk && $compatible;

        $typesStr = '';
        if ($types !== []) {
            $typesArr = array_map(fn($t): string => is_string($t) ? $t : '', $types);
            $typesStr = implode(',', array_filter($typesArr, fn($t): bool => $t !== ''));
        }

        return [
            'id' => $this->getPluginStringField($plugin, 'id'),
            'name' => $this->getPluginStringField($plugin, 'name'),
            'title' => $this->getPluginStringField($plugin, 'title'),
            'author' => $this->getPluginStringField($plugin, 'author'),
            'version' => $this->getPluginStringField($plugin, 'version'),
            'kvs_version' => $this->getPluginStringField($plugin, 'kvs_version'),
            'status' => $isEnabled ? 'Active' : 'Inactive',
            'types' => $typesStr,
            'files_ok' => $filesOk ? 'Yes' : 'No',
            'syntax_ok' => $syntaxOk ? 'Yes' : 'No',
            'compatible' => $compatible ? 'Yes' : 'No',
            'valid' => $isValid ? 'Yes' : 'No',
            'description' => $this->getPluginStringField($plugin, 'description'),
            'path' => $this->getPluginStringField($plugin, 'path'),
        ];
    }

    /**
     * @param array<string, mixed> $plugin
     */
    private function getPluginStringField(array $plugin, string $field): string
    {
        $value = $plugin[$field] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<int, array<string, mixed>> $plugins
     * @return list<string>
     */
    private function getAvailableTypes(array $plugins): array
    {
        $availableTypes = [];

        foreach ($plugins as $plugin) {
            $types = $plugin['types'] ?? [];
            if (!is_array($types)) {
                continue;
            }

            foreach ($types as $type) {
                if (!is_string($type) || trim($type) === '') {
                    continue;
                }

                $availableTypes[] = strtolower(trim($type));
            }
        }

        $availableTypes = array_values(array_unique($availableTypes));
        sort($availableTypes);

        return $availableTypes;
    }

    private function showPlugin(?string $id, InputInterface $input): int
    {
        if ($this->rejectUnsupportedOptions($input, 'show', self::LIST_FILTER_OPTIONS)) {
            return self::FAILURE;
        }

        if ($id === null || $id === "") {
            $this->io()->error('Plugin ID is required');
            $this->io()->text('Usage: kvs plugin show <plugin_id>');
            return self::FAILURE;
        }

        $plugin = $this->getPluginById($id);

        if ($plugin === null) {
            $this->io()->error("Plugin not found: $id");
            return self::FAILURE;
        }

        $name = $plugin['name'] ?? 'Unknown';
        $nameStr = is_string($name) ? $name : 'Unknown';

        $filesOk = (bool)($plugin['files_ok'] ?? false);
        $syntaxOk = (bool)($plugin['syntax_ok'] ?? false);
        $compatible = (bool)($plugin['compatible'] ?? false);
        $isEnabled = (bool)($plugin['is_enabled'] ?? false);
        $types = $plugin['types'] ?? [];
        if (!is_array($types)) {
            $types = [];
        }
        $typesArr = array_map(fn($t): string => is_string($t) ? $t : '', $types);
        $typesStr = implode(', ', array_filter($typesArr, fn($t): bool => $t !== ''));

        $id = $plugin['id'] ?? '';
        $title = $plugin['title'] ?? '';
        $author = $plugin['author'] ?? '';
        $version = $plugin['version'] ?? '';
        $kvsVersion = $plugin['kvs_version'] ?? '';
        $pluginPath = $plugin['path'] ?? '';
        $pluginPathStr = is_string($pluginPath) ? $pluginPath : '';

        if (!$this->isTableFormat($input)) {
            $formatter = new Formatter(
                $input->getOptions(),
                [
                    'id',
                    'name',
                    'title',
                    'author',
                    'version',
                    'kvs_version',
                    'types',
                    'files_ok',
                    'syntax_ok',
                    'compatible',
                    'status',
                    'description',
                    'path',
                ]
            );
            $formatter->display([$this->formatPluginForList($plugin)], $this->io());
            return self::SUCCESS;
        }

        $this->io()->section("Plugin: {$nameStr}");

        $info = [
            ['ID', is_string($id) ? $id : ''],
            ['Name', $nameStr],
            ['Title', is_string($title) ? $title : ''],
            ['Author', is_string($author) ? $author : ''],
            ['Version', is_string($version) ? $version : ''],
            ['Required KVS', is_string($kvsVersion) ? $kvsVersion : ''],
            ['Types', $typesStr],
            ['Files OK', $filesOk ? '<fg=green>Yes</>' : '<fg=red>No</>'],
            ['Syntax OK', $syntaxOk ? '<fg=green>Yes</>' : '<fg=red>No</>'],
            ['Compatible', $compatible ? '<fg=green>Yes</>' : '<fg=red>No</>'],
            ['Status', $isEnabled ? '<fg=green>Active</>' : '<fg=yellow>Inactive</>'],
        ];

        $this->renderTable(['Property', 'Value'], $info);

        $description = $plugin['description'] ?? '';
        $descriptionStr = is_string($description) ? $description : '';
        if ($descriptionStr !== '') {
            $this->io()->section('Description');
            $this->io()->text($descriptionStr);
        }

        $pluginId = $plugin['id'] ?? '';
        $pluginIdStr = is_string($pluginId) ? $pluginId : '';
        $this->io()->section('Paths');
        $this->io()->listing([
            "Plugin directory: {$pluginPathStr}",
            "Main file: {$pluginPathStr}/{$pluginIdStr}.php",
            "Template: {$pluginPathStr}/{$pluginIdStr}.tpl",
            "Metadata: {$pluginPathStr}/{$pluginIdStr}.dat",
        ]);

        return self::SUCCESS;
    }

    private function showPluginPath(?string $id, InputInterface $input): int
    {
        if ($this->rejectUnsupportedOptions($input, 'path', self::LIST_FILTER_OPTIONS)) {
            return self::FAILURE;
        }

        if ($id === null || $id === "") {
            $this->io()->error('Plugin ID is required');
            return self::FAILURE;
        }

        $plugin = $this->getPluginById($id);

        if ($plugin === null) {
            $this->io()->error("Plugin not found: $id");
            return self::FAILURE;
        }

        $pluginPath = $plugin['path'] ?? '';
        $pluginPathStr = is_string($pluginPath) ? $pluginPath : '';
        if (!$this->isTableFormat($input)) {
            $formatter = new Formatter($input->getOptions(), ['id', 'path']);
            $formatter->display([[
                'id' => $id,
                'path' => $pluginPathStr,
            ]], $this->io());
            return self::SUCCESS;
        }

        $this->io()->writeln($pluginPathStr);
        return self::SUCCESS;
    }

    private function showStatus(InputInterface $input): int
    {
        if ($this->rejectUnsupportedOptions($input, 'status', self::LIST_FILTER_OPTIONS)) {
            return self::FAILURE;
        }

        $plugins = $this->getAllPlugins();

        $total = count($plugins);
        $active = count(array_filter($plugins, fn($p) => ($p['is_enabled'] ?? false) === true));
        $inactive = $total - $active;
        $missingFiles = count(array_filter($plugins, fn($p) => ($p['files_ok'] ?? true) === false));
        $syntaxErrors = count(array_filter($plugins, fn($p) => ($p['syntax_ok'] ?? true) === false));
        $incompatible = count(array_filter($plugins, fn($p) => ($p['compatible'] ?? true) === false));

        // Count by type
        /** @var array<string, int> $typeStats */
        $typeStats = [];
        foreach ($plugins as $plugin) {
            $types = $plugin['types'] ?? [];
            if (!is_array($types)) {
                continue;
            }
            foreach ($types as $type) {
                if (!is_string($type)) {
                    continue;
                }
                if (!isset($typeStats[$type])) {
                    $typeStats[$type] = 0;
                }
                $typeStats[$type]++;
            }
        }

        $stats = [
            ['Total Plugins', $total],
            ['Active', "<fg=green>$active</>"],
            ['Inactive', "<fg=yellow>$inactive</>"],
        ];

        // Only show problem stats if there are issues
        if ($missingFiles > 0) {
            $stats[] = ['Missing Files', "<fg=red>$missingFiles</>"];
        }
        if ($syntaxErrors > 0) {
            $stats[] = ['Syntax Errors', "<fg=red>$syntaxErrors</>"];
        }
        if ($incompatible > 0) {
            $stats[] = ['Incompatible', "<fg=red>$incompatible</>"];
        }

        /** @var list<array<string, mixed>> $metricRows */
        $metricRows = [
            $this->metricRow('overall', 'Total Plugins', $total),
            $this->metricRow('overall', 'Active', $active),
            $this->metricRow('overall', 'Inactive', $inactive),
        ];
        if ($missingFiles > 0) {
            $metricRows[] = $this->metricRow('overall', 'Missing Files', $missingFiles);
        }
        if ($syntaxErrors > 0) {
            $metricRows[] = $this->metricRow('overall', 'Syntax Errors', $syntaxErrors);
        }
        if ($incompatible > 0) {
            $metricRows[] = $this->metricRow('overall', 'Incompatible', $incompatible);
        }
        foreach ($typeStats as $type => $count) {
            $metricRows[] = $this->metricRow('by_type', $type, $count, (string) $count, ucfirst($type));
        }

        if (!$this->isTableFormat($input)) {
            $this->displayMetricRows($input, $metricRows);
            return self::SUCCESS;
        }

        $this->io()->section('Plugin Statistics');
        $this->renderTable(['Metric', 'Count'], $stats);

        if ($typeStats !== []) {
            $this->io()->section('By Type');
            $typeRows = [];
            foreach ($typeStats as $type => $count) {
                $typeRows[] = [ucfirst($type), $count];
            }
            $this->renderTable(['Type', 'Count'], $typeRows);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAllPlugins(): array
    {
        $kvsPath = $this->config->getKvsPath();
        $pluginsDir = "$kvsPath/admin/plugins";

        if (!is_dir($pluginsDir)) {
            return [];
        }

        $plugins = [];
        $items = scandir($pluginsDir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $pluginPath = "$pluginsDir/$item";

            if (!is_dir($pluginPath)) {
                continue;
            }
            if (in_array($item, self::HIDDEN_PLUGIN_IDS, true)) {
                continue;
            }

            // Check if plugin has required files
            if (
                !file_exists("$pluginPath/$item.php") ||
                !file_exists("$pluginPath/$item.dat") ||
                !file_exists("$pluginPath/$item.tpl")
            ) {
                continue;
            }

            $plugin = $this->parsePlugin($item, $pluginPath);
            if ($plugin !== null) {
                $plugins[] = $plugin;
            }
        }

        // Sort by name
        usort($plugins, function (array $a, array $b): int {
            $nameA = $a['name'] ?? '';
            $nameB = $b['name'] ?? '';
            return strcasecmp(
                is_string($nameA) ? $nameA : '',
                is_string($nameB) ? $nameB : ''
            );
        });

        return $plugins;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPluginById(string $id): ?array
    {
        $plugins = $this->getAllPlugins();

        foreach ($plugins as $plugin) {
            if ($plugin['id'] === $id) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parsePlugin(string $id, string $path): ?array
    {
        $datFile = "$path/$id.dat";

        if (!file_exists($datFile)) {
            return null;
        }

        $content = file_get_contents($datFile);
        if ($content === false) {
            return null;
        }

        $plugin = [
            'id' => $id,
            'path' => $path,
            'is_enabled' => false,
            'files_ok' => true,
            'syntax_ok' => true,
            'compatible' => true,
        ];

        // Parse XML metadata
        if (preg_match('|<plugin_name>(.*?)</plugin_name>|is', $content, $match) === 1) {
            $plugin['name'] = trim($match[1]);
        }

        if (preg_match('|<author>(.*?)</author>|is', $content, $match) === 1) {
            $plugin['author'] = trim($match[1]);
        }

        if (preg_match('|<version>(.*?)</version>|is', $content, $match) === 1) {
            $plugin['version'] = trim($match[1]);
        }
        if (($plugin['version'] ?? '') === 'del') {
            return null;
        }

        if (preg_match('|<kvs_version>(.*?)</kvs_version>|is', $content, $match) === 1) {
            $plugin['kvs_version'] = trim($match[1]);
        }

        if (preg_match('|<plugin_types>(.*?)</plugin_types>|is', $content, $match) === 1) {
            $plugin['types'] = array_map('trim', explode(',', trim($match[1])));
        } else {
            $plugin['types'] = [];
        }

        // Try to get localized title/description
        $plugin['title'] = $plugin['name'] ?? '';
        $plugin['description'] = '';

        $langFile = "$path/langs/english.php";
        if (file_exists($langFile)) {
            /** @var array<string, mixed> $lang */
            $lang = [];
            include $langFile;
            if (isset($lang['plugins']) && is_array($lang['plugins']) && isset($lang['plugins'][$id]) && is_array($lang['plugins'][$id])) {
                if (isset($lang['plugins'][$id]['title']) && is_string($lang['plugins'][$id]['title'])) {
                    $plugin['title'] = $lang['plugins'][$id]['title'];
                }
                if (isset($lang['plugins'][$id]['description']) && is_string($lang['plugins'][$id]['description'])) {
                    $plugin['description'] = $lang['plugins'][$id]['description'];
                }
            }
        }

        // Check required files exist (.php, .tpl) - .dat already checked above
        $phpFile = "$path/$id.php";
        $tplFile = "$path/$id.tpl";
        if (!file_exists($phpFile) || !file_exists($tplFile)) {
            $plugin['files_ok'] = false;
            return $plugin;
        }

        // Check PHP syntax
        $plugin['syntax_ok'] = $this->checkPhpSyntax($phpFile);

        // Check KVS version compatibility
        if (isset($plugin['kvs_version']) && $plugin['kvs_version'] !== '') {
            $plugin['compatible'] = $this->checkVersionCompatibility($plugin['kvs_version']);
        }

        $plugin['is_enabled'] = $plugin['compatible'] ? $this->checkPluginEnabled($id, $phpFile) : false;

        return $plugin;
    }

    private function checkPhpSyntax(string $phpFile): bool
    {
        $output = [];
        $returnCode = 0;
        exec(sprintf('php -l %s 2>&1', escapeshellarg($phpFile)), $output, $returnCode);
        return $returnCode === 0;
    }

    private function checkVersionCompatibility(string $requiredVersion): bool
    {
        $kvsVersion = $this->config->getKvsVersion();
        if ($kvsVersion === '') {
            return true; // Can't check, assume compatible
        }

        return version_compare($kvsVersion, $requiredVersion, '>=');
    }

    private function checkPluginEnabled(string $id, string $phpFile): bool
    {
        $phpContent = file_get_contents($phpFile);
        if ($phpContent === false) {
            return false;
        }

        $functionName = preg_quote($id . 'IsEnabled', '/');
        if (preg_match("/function\s+{$functionName}\s*\([^)]*\)\s*(?::\s*[^\\s{]+)?\s*\{(?<body>.*?)\n\}/s", $phpContent, $match) !== 1) {
            return false;
        }

        $body = $match['body'];
        if (preg_match('/return\s+true\s*;/i', $body) === 1) {
            return true;
        }
        if (preg_match('/return\s+false\s*;/i', $body) === 1) {
            return false;
        }

        return $this->checkPluginDataEnabled($id, $body);
    }

    private function checkPluginDataEnabled(string $id, string $functionBody): bool
    {
        if (preg_match("/['\"]is_enabled['\"]/", $functionBody) !== 1) {
            return false;
        }

        $dataFile = $this->config->getKvsPath() . '/admin/data/plugins/' . $id . '/data.dat';
        if (!is_file($dataFile)) {
            return false;
        }

        $rawData = file_get_contents($dataFile);
        if (!is_string($rawData) || $rawData === '') {
            return false;
        }

        $data = @unserialize($rawData, ['allowed_classes' => false]);
        if (!is_array($data)) {
            return false;
        }

        $isEnabled = $data['is_enabled'] ?? 0;

        return is_numeric($isEnabled) && (int) $isEnabled === 1;
    }
}
