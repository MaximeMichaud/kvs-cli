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
  --type=<type>         Filter by type (manual|cron)
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
        $action = $this->getStringArgument($input, 'action');
        $id = $this->getStringArgument($input, 'id');

        return match ($action) {
            'list' => $this->listPlugins($input),
            'show' => $this->showPlugin($id),
            'path' => $this->showPluginPath($id),
            'status' => $this->showStatus(),
            default => $this->listPlugins($input),
        };
    }

    private function listPlugins(InputInterface $input): int
    {
        $plugins = $this->getAllPlugins();

        if ($plugins === []) {
            $this->io()->info('No plugins found');
            return self::SUCCESS;
        }

        // Apply filters
        $statusFilter = $this->getStringOption($input, 'status');
        $typeFilter = $this->getStringOption($input, 'type');

        if ($statusFilter !== null && $statusFilter !== 'all') {
            $plugins = array_filter($plugins, function (array $plugin) use ($statusFilter): bool {
                $isEnabled = $plugin['is_enabled'] ?? false;
                if ($statusFilter === 'active') {
                    return (bool)$isEnabled;
                } elseif ($statusFilter === 'inactive') {
                    return !(bool)$isEnabled;
                }
                return true;
            });
        }

        if ($typeFilter !== null && $typeFilter !== '') {
            $plugins = array_filter($plugins, function (array $plugin) use ($typeFilter): bool {
                $types = $plugin['types'] ?? [];
                if (!is_array($types)) {
                    return false;
                }
                return in_array($typeFilter, $types, true);
            });
        }

        // Transform plugins to flattened format for Formatter
        $transformedPlugins = array_map(function (array $plugin): array {
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

            $id = $plugin['id'] ?? '';
            $name = $plugin['name'] ?? '';
            $title = $plugin['title'] ?? '';
            $author = $plugin['author'] ?? '';
            $version = $plugin['version'] ?? '';
            $kvsVersion = $plugin['kvs_version'] ?? '';
            $description = $plugin['description'] ?? '';
            $path = $plugin['path'] ?? '';

            return [
                'id' => is_string($id) ? $id : '',
                'name' => is_string($name) ? $name : '',
                'title' => is_string($title) ? $title : '',
                'author' => is_string($author) ? $author : '',
                'version' => is_string($version) ? $version : '',
                'kvs_version' => is_string($kvsVersion) ? $kvsVersion : '',
                'status' => $isEnabled ? 'Active' : 'Inactive',
                'types' => $typesStr,
                'files_ok' => $filesOk ? 'Yes' : 'No',
                'syntax_ok' => $syntaxOk ? 'Yes' : 'No',
                'compatible' => $compatible ? 'Yes' : 'No',
                'valid' => $isValid ? 'Yes' : 'No',
                'description' => is_string($description) ? $description : '',
                'path' => is_string($path) ? $path : '',
            ];
        }, $plugins);

        // Format and display output using centralized Formatter
        $formatter = new Formatter(
            $input->getOptions(),
            ['id', 'name', 'version', 'status', 'types']
        );
        $formatter->display(array_values($transformedPlugins), $this->io());

        return self::SUCCESS;
    }

    private function showPlugin(?string $id): int
    {
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
        $this->io()->section("Plugin: {$nameStr}");

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

        $pluginPath = $plugin['path'] ?? '';
        $pluginId = $plugin['id'] ?? '';
        $pluginPathStr = is_string($pluginPath) ? $pluginPath : '';
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

    private function showPluginPath(?string $id): int
    {
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
        $this->io()->writeln($pluginPathStr);
        return self::SUCCESS;
    }

    private function showStatus(): int
    {
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

        $this->io()->section('Plugin Statistics');

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

        // Check if plugin is enabled/configured (only if all checks pass)
        if ($plugin['syntax_ok'] && $plugin['compatible']) {
            $plugin['is_enabled'] = $this->checkPluginEnabled($id, $phpFile);
        }

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
        /**
         * KVS Plugin Status Logic:
         * - If plugin has NO IsEnabled function → always enabled (default)
         * - If plugin HAS IsEnabled function → call it to check if configured/enabled
         *
         * Note: IsEnabled checks if plugin is CONFIGURED (e.g., backup has auto-backup enabled),
         * not just if it's installed. Plugins without IsEnabled are always operational.
         */
        try {
            $kvsPath = $this->config->getKvsPath();

            // Check if plugin has IsEnabled function
            $phpContent = file_get_contents($phpFile);
            if ($phpContent === false) {
                // Can't read file, assume enabled (to avoid false negatives)
                return true;
            }
            if (preg_match("/function\s+{$id}IsEnabled\s*\(/", $phpContent) !== 1) {
                // No IsEnabled function = plugin is always enabled by default
                return true;
            }

            // Plugin has IsEnabled function - need to call it
            /** @var array<string, mixed> $config */
            $config = [];

            // Load KVS config (required by most plugins)
            if (file_exists("$kvsPath/admin/include/setup.php")) {
                require_once "$kvsPath/admin/include/setup.php";
            }

            // Load plugin file
            if (!function_exists("{$id}Show")) {
                require_once $phpFile;
            }

            // Call IsEnabled function
            $funcName = "{$id}IsEnabled";
            if (function_exists($funcName)) {
                return (bool)$funcName();
            }

            // Couldn't call IsEnabled, assume not enabled
            return false;
        } catch (\Throwable $e) {
            // If anything fails, assume enabled (to avoid false negatives)
            // Better to show an "active" plugin that might not be configured
            // than to hide a working plugin
            return true;
        }
    }
}
