<?php

namespace KVS\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

use function KVS\CLI\Utils\truncate;

#[AsCommand(
    name: 'config',
    description: 'Manage KVS configuration',
    aliases: ['conf', 'cfg']
)]
class ConfigCommand extends BaseCommand
{
    /** @var array<string, string> */
    private array $configFiles = [
        'db' => '/admin/include/setup_db.php',
        'main' => '/admin/include/setup.php',
        'paths' => '/admin/include/setup_paths.php',
    ];

    /** @var array<string, bool> */
    private array $protectedKeys = [
        'db.pass' => true,
        'db.password' => true,
        'license.key' => true,
        'security.key' => true,
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: get, set, list, edit', 'list')
            ->addArgument('key', InputArgument::OPTIONAL, 'Configuration key (e.g., db.host)')
            ->addArgument('value', InputArgument::OPTIONAL, 'New value for set action')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Config file to use (db, main, paths)', 'all')
            ->addOption('show-protected', null, InputOption::VALUE_NONE, 'Show protected values')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output in JSON format')
            ->addOption('backup', null, InputOption::VALUE_NONE, 'Create backup before changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $this->getStringArgument($input, 'action');

        return match ($action) {
            'get' => $this->getConfig($input),
            'set' => $this->setConfig($input),
            'list' => $this->listConfig($input),
            'edit' => $this->editConfig($input),
            default => $this->showHelp(),
        };
    }

    private function getConfig(InputInterface $input): int
    {
        $key = $this->getStringArgument($input, 'key');
        if ($key === null || $key === '') {
            $this->io()->error('Configuration key is required for get action');
            return self::FAILURE;
        }

        $value = $this->getConfigValue($key);

        if ($value === null) {
            $this->io()->error("Configuration key not found: $key");

            // Suggest similar keys
            $allConfigs = $this->getAllConfigs('all');
            $suggestions = $this->findSimilarKeys($key, $allConfigs);

            if ($suggestions !== []) {
                $this->io()->text('Did you mean one of these?');
                $this->io()->listing($suggestions);
            } else {
                $this->io()->text('Use "kvs config list" to see all available keys.');
            }

            return self::FAILURE;
        }

        // Check if value is protected
        if (isset($this->protectedKeys[$key]) && !$this->getBoolOption($input, 'show-protected')) {
            $value = '**********';
        }

        if ($this->getBoolOption($input, 'json')) {
            $this->io()->writeln((string) json_encode([$key => $value]));
        } else {
            $this->io()->writeln("<info>$key</info> = $value");
        }

        return self::SUCCESS;
    }

    private function setConfig(InputInterface $input): int
    {
        $key = $this->getStringArgument($input, 'key');
        $value = $this->getStringArgument($input, 'value');

        if ($key === null || $key === '' || $value === null) {
            $this->io()->error('Both key and value are required for set action');
            return self::FAILURE;
        }

        // Validate key format
        if (preg_match('/^[a-z]+\.[a-z_]+$/i', $key) !== 1) {
            $this->io()->error('Invalid key format. Use format: category.key (e.g., db.host)');
            return self::FAILURE;
        }

        // Create backup if requested
        if ($this->getBoolOption($input, 'backup')) {
            $this->createBackup($key);
        }

        // Parse key
        [$category, $configKey] = explode('.', $key, 2);

        // Determine file
        $file = $this->getConfigFile($category);
        if ($file === null) {
            $this->io()->error("Unknown configuration category: $category");
            return self::FAILURE;
        }

        // Special handling for database config
        if ($category === 'db') {
            return $this->setDatabaseConfig($configKey, $value);
        }

        // For main config
        if ($category === 'main' || $category === 'site') {
            return $this->setMainConfig($configKey, $value);
        }

        $this->io()->warning('Setting config for this category is not yet implemented');
        return self::FAILURE;
    }

    private function listConfig(InputInterface $input): int
    {
        $file = $this->getStringOptionOrDefault($input, 'file', 'all');
        $showProtected = $this->getBoolOption($input, 'show-protected');
        $json = $this->getBoolOption($input, 'json');

        $configs = $this->getAllConfigs($file);

        if ($json) {
            if (!$showProtected) {
                foreach ($configs as $key => &$value) {
                    if (isset($this->protectedKeys[$key])) {
                        $value = '**********';
                    }
                }
            }
            $this->io()->writeln((string) json_encode($configs, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        // Table output
        $this->io()->title('KVS Configuration');

        // Get all main configs
        $mainConfigs = $this->getMainConfigs();

        // Show organized sections for 'all' or 'main'
        if ($file === 'main' || $file === 'all') {
            // Project Configuration
            $this->showConfigSection('Project Configuration', [
                'project_name' => 'Project Name',
                'project_version' => 'Version',
                'project_path' => 'Installation Path',
                'project_url' => 'Project URL',
                'project_licence_domain' => 'Licensed Domain',
            ], $mainConfigs, $showProtected);

            // Tools & Paths
            $this->showConfigSection('Tools & Paths', [
                'php_path' => 'PHP Binary',
                'ffmpeg_path' => 'FFmpeg',
                'ffprobe_path' => 'FFprobe',
                'image_magick_path' => 'ImageMagick',
                'nice_path' => 'Nice',
                'tar_path' => 'Tar',
                'mysqldump_path' => 'MySQLDump',
            ], $mainConfigs, $showProtected);

            // Server Configuration
            $this->showConfigSection('Server Configuration', [
                'server_type' => 'Server Type',
                'conversion_server_id' => 'Conversion Server ID',
                'memcache_server' => 'Memcache Server',
                'memcache_port' => 'Memcache Port',
            ], $mainConfigs, $showProtected);

            // Content Paths & URLs - combine paths and URLs in same table
            $contentItems = [];
            foreach ($mainConfigs as $key => $value) {
                if (str_starts_with($key, 'content_path_')) {
                    $itemKey = str_replace('content_path_', '', $key);
                    if (!isset($contentItems[$itemKey])) {
                        $contentItems[$itemKey] = ['path' => '', 'url' => ''];
                    }
                    $contentItems[$itemKey]['path'] = is_scalar($value) ? (string) $value : '';
                } elseif (str_starts_with($key, 'content_url_')) {
                    $itemKey = str_replace('content_url_', '', $key);
                    if (!isset($contentItems[$itemKey])) {
                        $contentItems[$itemKey] = ['path' => '', 'url' => ''];
                    }
                    $contentItems[$itemKey]['url'] = is_scalar($value) ? (string) $value : '';
                }
            }
            if ($contentItems !== []) {
                $this->showContentPathsSection('Content Paths & URLs', $contentItems, $showProtected);
            }
        }

        // Database configs
        if ($file === 'db' || $file === 'all') {
            $this->io()->section('Database Configuration');
            $dbConfigs = $this->getDatabaseConfigs();
            $rows = [];
            foreach ($dbConfigs as $key => $value) {
                $fullKey = "db.$key";
                $displayValue = $value;
                if (isset($this->protectedKeys[$fullKey]) && !$showProtected) {
                    $displayValue = '**********';
                }
                $rows[] = [$key, $displayValue];
            }
            if ($rows !== []) {
                $this->renderTable(['Parameter', 'Value'], $rows);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Display a configuration section with specific keys
     *
     * @param array<string, string> $keys
     * @param array<string, mixed> $configs
     */
    private function showConfigSection(string $title, array $keys, array $configs, bool $showProtected): void
    {
        $rows = [];
        foreach ($keys as $configKey => $label) {
            if (isset($configs[$configKey])) {
                $value = $configs[$configKey];

                // Handle arrays
                if (is_array($value)) {
                    $encoded = json_encode($value);
                    $value = $encoded !== false ? $encoded : '';
                } elseif (is_scalar($value)) {
                    $value = (string) $value;
                } else {
                    $value = '';
                }

                // Truncate long values
                if (strlen($value) > 80) {
                    $value = truncate($value, 80);
                }

                // Check for protected values
                $fullKey = "main.$configKey";
                if (isset($this->protectedKeys[$fullKey]) && !$showProtected) {
                    $value = '**********';
                }

                $rows[] = [$label, $value];
            }
        }

        if ($rows !== []) {
            $this->io()->section($title);
            $this->renderTable(['Parameter', 'Value'], $rows);
        }
    }

    /**
     * Display content paths and URLs in a 3-column table
     *
     * @param array<string, array{path?: string, url?: string}> $items
     */
    private function showContentPathsSection(string $title, array $items, bool $showProtected): void
    {
        $rows = [];
        foreach ($items as $key => $data) {
            $label = str_replace('_', ' ', $key);
            $label = ucwords($label);

            $path = $data['path'] ?? '';
            $url = $data['url'] ?? '';

            // Truncate long values
            if (strlen($path) > 50) {
                $path = truncate($path, 50);
            }
            if (strlen($url) > 50) {
                $url = truncate($url, 50);
            }

            $rows[] = [$label, $path, $url];
        }

        if ($rows !== []) {
            $this->io()->section($title);
            $this->renderTable(['Content Type', 'Local Path', 'URL'], $rows);
        }
    }

    private function editConfig(InputInterface $input): int
    {
        $file = $this->getStringOptionOrDefault($input, 'file', 'all');

        $filePath = $this->getConfigFilePath($file);
        if ($filePath === null) {
            $this->io()->error("Unknown config file: $file");
            return self::FAILURE;
        }

        // Get editor from environment
        $editorEnv = getenv('EDITOR');
        $editor = ($editorEnv !== false && $editorEnv !== '') ? $editorEnv : 'nano';

        // Create backup
        $backupFile = $filePath . '.backup.' . date('YmdHis');
        copy($filePath, $backupFile);
        $this->io()->info("Backup created: $backupFile");

        // Open in editor
        $command = sprintf('%s %s', escapeshellcmd($editor), escapeshellarg($filePath));

        $this->io()->info("Opening $filePath in $editor...");
        passthru($command, $returnCode);

        if ($returnCode === 0) {
            $this->io()->success('Configuration file edited successfully');

            // Validate syntax
            $result = shell_exec("php -l $filePath 2>&1");
            if ($result === null || $result === false || strpos($result, 'No syntax errors') === false) {
                $this->io()->error('Syntax error in configuration file!');
                if (is_string($result)) {
                    $this->io()->warning($result);
                }

                if ($this->io()->confirm('Restore from backup?', true) === true) {
                    copy($backupFile, $filePath);
                    $this->io()->success('Restored from backup');
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function getDatabaseConfigs(): array
    {
        $file = $this->config->getKvsPath() . '/admin/include/setup_db.php';
        if (!file_exists($file)) {
            return [];
        }

        $content = (string) file_get_contents($file);
        $configs = [];

        // Parse define() statements
        if (preg_match_all("/define\('DB_([^']+)',\s*'([^']*)'\)/", $content, $matches) > 0) {
            foreach ($matches[1] as $i => $key) {
                $configs[strtolower($key)] = $matches[2][$i];
            }
        }

        return $configs;
    }

    /**
     * @return array<string, mixed>
     */
    private function getMainConfigs(): array
    {
        $file = $this->config->getKvsPath() . '/admin/include/setup.php';
        if (!file_exists($file)) {
            return [];
        }

        // Save current directory and change to setup.php directory
        // This is needed because setup.php includes version.php with relative path
        $oldCwd = getcwd();
        if ($oldCwd !== false) {
            chdir(dirname($file));
        }

        // Capture config array
        ob_start();
        $config = [];
        @include basename($file);

        // Explicitly include version.php as it might not be loaded
        // due to include_once potentially failing silently
        $versionFile = dirname($file) . '/version.php';
        /** @phpstan-ignore function.alreadyNarrowedType (config may be reassigned by include) */
        if (file_exists($versionFile) && is_array($config)) {
            /** @phpstan-ignore function.impossibleType (config populated by include) */
            if (!array_key_exists('project_version', $config)) {
                /** @phpstan-ignore include.fileNotFound (dynamic include after chdir) */
                @include 'version.php';
            }
        }

        ob_end_clean();

        // Restore directory
        if ($oldCwd !== false) {
            chdir($oldCwd);
        }

        // $config may be reassigned by the include, so we verify it's still an array
        /** @phpstan-ignore function.alreadyNarrowedType (config may be reassigned by include) */
        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAllConfigs(string $type = 'all'): array
    {
        $configs = [];

        if ($type === 'db' || $type === 'all') {
            $dbConfigs = $this->getDatabaseConfigs();
            foreach ($dbConfigs as $key => $value) {
                $configs["db.$key"] = $value;
            }
        }

        if ($type === 'main' || $type === 'all') {
            $mainConfigs = $this->getMainConfigs();
            foreach ($mainConfigs as $key => $value) {
                if (!is_array($value)) {
                    $configs["main.$key"] = $value;
                }
            }
        }

        return $configs;
    }

    private function getConfigValue(string $key): ?string
    {
        // If no category prefix, try to find key in all configs
        if (strpos($key, '.') === false) {
            // Try main config first (most common)
            $mainConfigs = $this->getMainConfigs();
            if (isset($mainConfigs[$key])) {
                $value = $mainConfigs[$key];
                if (is_array($value)) {
                    $encoded = json_encode($value);
                    return $encoded !== false ? $encoded : null;
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }
                return null;
            }

            // Try database config
            $dbConfigs = $this->getDatabaseConfigs();
            if (isset($dbConfigs[$key])) {
                return $dbConfigs[$key];
            }

            // Try case-insensitive match in main configs
            $keyLower = strtolower($key);
            foreach ($mainConfigs as $configKey => $value) {
                if (strtolower($configKey) === $keyLower) {
                    if (is_array($value)) {
                        $encoded = json_encode($value);
                        return $encoded !== false ? $encoded : null;
                    }
                    if (is_scalar($value)) {
                        return (string) $value;
                    }
                    return null;
                }
            }

            return null;
        }

        [$category, $configKey] = explode('.', $key, 2);

        if ($category === 'db') {
            $configs = $this->getDatabaseConfigs();
            return $configs[$configKey] ?? null;
        }

        if ($category === 'main' || $category === 'site') {
            $configs = $this->getMainConfigs();
            if (!isset($configs[$configKey])) {
                // Try case-insensitive
                $configKeyLower = strtolower($configKey);
                foreach ($configs as $k => $v) {
                    if (strtolower($k) === $configKeyLower) {
                        if (is_array($v)) {
                            $encoded = json_encode($v);
                            return $encoded !== false ? $encoded : null;
                        }
                        if (is_scalar($v)) {
                            return (string) $v;
                        }
                        return null;
                    }
                }
                return null;
            }
            $value = $configs[$configKey];
            if (is_array($value)) {
                $encoded = json_encode($value);
                return $encoded !== false ? $encoded : null;
            }
            if (is_scalar($value)) {
                return (string) $value;
            }
            return null;
        }

        return null;
    }

    private function setDatabaseConfig(string $key, string $value): int
    {
        $file = $this->config->getKvsPath() . '/admin/include/setup_db.php';
        if (!file_exists($file)) {
            $this->io()->error('Database configuration file not found');
            return self::FAILURE;
        }

        $content = (string) file_get_contents($file);
        $defineKey = 'DB_' . strtoupper($key);

        // Check if key exists
        if (preg_match("/define\('$defineKey',/", $content) !== 1) {
            $this->io()->error("Configuration key not found: db.$key");
            return self::FAILURE;
        }

        $escapedDefineKey = preg_quote($defineKey, '/');
        $escapedValue = addslashes($value);
        $pattern = "/define\('" . $escapedDefineKey . "',\s*'[^']*'\)/";
        $replacement = "define('" . $defineKey . "','" . $escapedValue . "')";
        $newContent = preg_replace($pattern, $replacement, $content);

        // Write file
        file_put_contents($file, $newContent);

        $this->io()->success("Configuration updated: db.$key = $value");

        // Test database connection if it's a db setting
        if (in_array($key, ['host', 'login', 'pass', 'device'], true)) {
            $this->io()->info('Testing database connection...');
            $db = $this->getDatabaseConnection();
            if ($db !== null) {
                $this->io()->success('Database connection successful');
            } else {
                $this->io()->warning('Database connection failed with new settings');
            }
        }

        return self::SUCCESS;
    }

    private function setMainConfig(string $key, string $value): int
    {
        $file = $this->config->getKvsPath() . '/admin/include/setup.php';
        if (!file_exists($file)) {
            $this->io()->error('Main configuration file not found');
            return self::FAILURE;
        }

        $content = (string) file_get_contents($file);

        $escapedKey = preg_quote($key, '/');
        $patterns = [
            '/\$config\[\'' . $escapedKey . '\'\]\s*=\s*[^;]+;/',
            '/\$config\["' . $escapedKey . '"\]\s*=\s*[^;]+;/',
        ];

        $found = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                if (is_numeric($value) || $value === 'true' || $value === 'false' || $value === 'null') {
                    $replacement = "\$config['" . $key . "'] = " . $value . ";";
                } else {
                    $escapedValue = addslashes($value);
                    $replacement = "\$config['" . $key . "'] = '" . $escapedValue . "';";
                }
                $content = preg_replace($pattern, $replacement, $content);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->io()->error("Configuration key not found: main.$key");
            return self::FAILURE;
        }

        // Write file
        file_put_contents($file, $content);

        $this->io()->success("Configuration updated: main.$key = $value");
        return self::SUCCESS;
    }

    private function getConfigFile(string $category): ?string
    {
        return match ($category) {
            'db', 'database' => 'db',
            'main', 'site', 'app' => 'main',
            'path', 'paths' => 'paths',
            default => null,
        };
    }

    private function getConfigFilePath(string $file): ?string
    {
        if (!isset($this->configFiles[$file])) {
            return null;
        }

        $path = $this->config->getKvsPath() . $this->configFiles[$file];
        return file_exists($path) ? $path : null;
    }

    private function createBackup(string $key): void
    {
        [$category] = explode('.', $key, 2);
        $configFile = $this->getConfigFile($category);
        $file = $configFile !== null ? $this->getConfigFilePath($configFile) : null;

        if ($file !== null) {
            $backupFile = $file . '.backup.' . date('YmdHis');
            copy($file, $backupFile);
            $this->io()->info("Backup created: $backupFile");
        }
    }

    /**
     * Find similar keys using fuzzy matching
     *
     * @param array<string, mixed> $haystack
     * @return list<string>
     */
    private function findSimilarKeys(string $needle, array $haystack): array
    {
        $matches = [];
        $needleLower = strtolower($needle);

        // Remove prefix if present for better matching
        $needleWithoutPrefix = (string) preg_replace('/^(db|main|site)\./', '', $needleLower);

        foreach (array_keys($haystack) as $key) {
            $keyLower = strtolower($key);
            $keyWithoutPrefix = (string) preg_replace('/^(db|main|site)\./', '', $keyLower);

            // Exact match without prefix
            if ($keyWithoutPrefix === $needleWithoutPrefix) {
                $matches[] = $key;
                continue;
            }

            // Contains match
            if (
                str_contains($keyWithoutPrefix, $needleWithoutPrefix) ||
                str_contains($needleWithoutPrefix, $keyWithoutPrefix)
            ) {
                $matches[] = $key;
                continue;
            }

            // Levenshtein distance for typos (max distance 3)
            if (levenshtein($keyWithoutPrefix, $needleWithoutPrefix) <= 3) {
                $matches[] = $key;
            }
        }

        // Return first 5 matches
        return array_slice(array_unique($matches), 0, 5);
    }

    private function showHelp(): int
    {
        $this->io()->info('Usage: kvs config <action> [key] [value] [options]');
        $this->io()->newLine();

        $this->io()->section('Actions');
        $this->io()->listing([
            'get <key> : Get a configuration value',
            'set <key> <value> : Set a configuration value',
            'list : List all configurations',
            'edit : Open config file in editor',
        ]);

        $this->io()->section('Examples');
        $this->io()->listing([
            'kvs config get db.host',
            'kvs config set db.host 127.0.0.1',
            'kvs config set db.port 3307',
            'kvs config list',
            'kvs config list --file=db',
            'kvs config list --json',
            'kvs config edit --file=db',
        ]);

        $this->io()->section('Available Keys');
        $this->io()->listing([
            'db.host : Database host',
            'db.login : Database username',
            'db.pass : Database password (protected)',
            'db.device : Database name',
            'main.project_version : KVS version',
            'main.project_url : Site URL',
            'main.admin_url : Admin panel URL',
        ]);

        return self::SUCCESS;
    }
}
