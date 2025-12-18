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
    private array $configFiles = [
        'db' => '/admin/include/setup_db.php',
        'main' => '/admin/include/setup.php',
        'paths' => '/admin/include/setup_paths.php',
    ];

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
        $action = $input->getArgument('action');

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
        $key = $input->getArgument('key');
        if (!$key) {
            $this->io->error('Configuration key is required for get action');
            return self::FAILURE;
        }

        $value = $this->getConfigValue($key);

        if ($value === null) {
            $this->io->error("Configuration key not found: $key");
            return self::FAILURE;
        }

        // Check if value is protected
        if (isset($this->protectedKeys[$key]) && !$input->getOption('show-protected')) {
            $value = '**********';
        }

        if ($input->getOption('json')) {
            $this->io->writeln(json_encode([$key => $value]));
        } else {
            $this->io->writeln("<info>$key</info> = $value");
        }

        return self::SUCCESS;
    }

    private function setConfig(InputInterface $input): int
    {
        $key = $input->getArgument('key');
        $value = $input->getArgument('value');

        if (!$key || $value === null) {
            $this->io->error('Both key and value are required for set action');
            return self::FAILURE;
        }

        // Validate key format
        if (!preg_match('/^[a-z]+\.[a-z_]+$/i', $key)) {
            $this->io->error('Invalid key format. Use format: category.key (e.g., db.host)');
            return self::FAILURE;
        }

        // Create backup if requested
        if ($input->getOption('backup')) {
            $this->createBackup($key);
        }

        // Parse key
        [$category, $configKey] = explode('.', $key, 2);

        // Determine file
        $file = $this->getConfigFile($category);
        if (!$file) {
            $this->io->error("Unknown configuration category: $category");
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

        $this->io->warning('Setting config for this category is not yet implemented');
        return self::FAILURE;
    }

    private function listConfig(InputInterface $input): int
    {
        $file = $input->getOption('file');
        $showProtected = $input->getOption('show-protected');
        $json = $input->getOption('json');

        $configs = $this->getAllConfigs($file);

        if ($json) {
            if (!$showProtected) {
                foreach ($configs as $key => &$value) {
                    if (isset($this->protectedKeys[$key])) {
                        $value = '**********';
                    }
                }
            }
            $this->io->writeln(json_encode($configs, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        // Table output
        $this->io->title('KVS Configuration');

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
                if (strpos($key, 'content_path_') === 0) {
                    $itemKey = str_replace('content_path_', '', $key);
                    if (!isset($contentItems[$itemKey])) {
                        $contentItems[$itemKey] = ['path' => '', 'url' => ''];
                    }
                    $contentItems[$itemKey]['path'] = $value;
                } elseif (strpos($key, 'content_url_') === 0) {
                    $itemKey = str_replace('content_url_', '', $key);
                    if (!isset($contentItems[$itemKey])) {
                        $contentItems[$itemKey] = ['path' => '', 'url' => ''];
                    }
                    $contentItems[$itemKey]['url'] = $value;
                }
            }
            if (!empty($contentItems)) {
                $this->showContentPathsSection('Content Paths & URLs', $contentItems, $showProtected);
            }
        }

        // Database configs
        if ($file === 'db' || $file === 'all') {
            $this->io->section('Database Configuration');
            $dbConfigs = $this->getDatabaseConfigs();
            $rows = [];
            foreach ($dbConfigs as $key => $value) {
                $fullKey = "db.$key";
                if (isset($this->protectedKeys[$fullKey]) && !$showProtected) {
                    $value = '**********';
                }
                $rows[] = [$key, $value];
            }
            if (!empty($rows)) {
                $this->renderTable(['Parameter', 'Value'], $rows);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Display a configuration section with specific keys
     */
    private function showConfigSection(string $title, array $keys, array $configs, bool $showProtected): void
    {
        $rows = [];
        foreach ($keys as $configKey => $label) {
            if (isset($configs[$configKey])) {
                $value = $configs[$configKey];

                // Handle arrays
                if (is_array($value)) {
                    $value = json_encode($value);
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

        if (!empty($rows)) {
            $this->io->section($title);
            $this->renderTable(['Parameter', 'Value'], $rows);
        }
    }

    /**
     * Display content paths and URLs in a 3-column table
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

        if (!empty($rows)) {
            $this->io->section($title);
            $this->renderTable(['Content Type', 'Local Path', 'URL'], $rows);
        }
    }

    private function editConfig(InputInterface $input): int
    {
        $file = $input->getOption('file');

        $filePath = $this->getConfigFilePath($file);
        if (!$filePath) {
            $this->io->error("Unknown config file: $file");
            return self::FAILURE;
        }

        // Get editor from environment
        $editor = getenv('EDITOR') ?: 'nano';

        // Create backup
        $backupFile = $filePath . '.backup.' . date('YmdHis');
        copy($filePath, $backupFile);
        $this->io->info("Backup created: $backupFile");

        // Open in editor
        $command = sprintf('%s %s', escapeshellcmd($editor), escapeshellarg($filePath));

        $this->io->info("Opening $filePath in $editor...");
        passthru($command, $returnCode);

        if ($returnCode === 0) {
            $this->io->success('Configuration file edited successfully');

            // Validate syntax
            $result = shell_exec("php -l $filePath 2>&1");
            if (strpos($result, 'No syntax errors') === false) {
                $this->io->error('Syntax error in configuration file!');
                $this->io->warning($result);

                if ($this->io->confirm('Restore from backup?', true)) {
                    copy($backupFile, $filePath);
                    $this->io->success('Restored from backup');
                }
            }
        }

        return self::SUCCESS;
    }

    private function getDatabaseConfigs(): array
    {
        $file = $this->config->getKvsPath() . '/admin/include/setup_db.php';
        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        $configs = [];

        // Parse define() statements
        if (preg_match_all("/define\('DB_([^']+)',\s*'([^']*)'\)/", $content, $matches)) {
            foreach ($matches[1] as $i => $key) {
                $configs[strtolower($key)] = $matches[2][$i];
            }
        }

        return $configs;
    }

    private function getMainConfigs(): array
    {
        $file = $this->config->getKvsPath() . '/admin/include/setup.php';
        if (!file_exists($file)) {
            return [];
        }

        // Save current directory and change to setup.php directory
        // This is needed because setup.php includes version.php with relative path
        $oldCwd = getcwd();
        chdir(dirname($file));

        // Capture config array
        ob_start();
        $config = [];
        @include basename($file);

        // Explicitly include version.php as it might not be loaded
        // due to include_once potentially failing silently
        $versionFile = dirname($file) . '/version.php';
        if (file_exists($versionFile) && !isset($config['project_version'])) {
            @include 'version.php';
        }

        ob_end_clean();

        // Restore directory
        chdir($oldCwd);

        return $config;
    }

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
                return is_array($mainConfigs[$key])
                    ? json_encode($mainConfigs[$key])
                    : (string)$mainConfigs[$key];
            }

            // Try database config
            $dbConfigs = $this->getDatabaseConfigs();
            if (isset($dbConfigs[$key])) {
                return $dbConfigs[$key];
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
                return null;
            }
            return is_array($configs[$configKey])
                ? json_encode($configs[$configKey])
                : (string)$configs[$configKey];
        }

        return null;
    }

    private function setDatabaseConfig(string $key, string $value): int
    {
        $file = $this->config->getKvsPath() . '/admin/include/setup_db.php';
        if (!file_exists($file)) {
            $this->io->error('Database configuration file not found');
            return self::FAILURE;
        }

        $content = file_get_contents($file);
        $defineKey = 'DB_' . strtoupper($key);

        // Check if key exists
        if (!preg_match("/define\('$defineKey',/", $content)) {
            $this->io->error("Configuration key not found: db.$key");
            return self::FAILURE;
        }

        // Replace value
        $pattern = "/define\('$defineKey',\s*'[^']*'\)/";
        $replacement = "define('$defineKey','$value')";
        $newContent = preg_replace($pattern, $replacement, $content);

        // Write file
        file_put_contents($file, $newContent);

        $this->io->success("Configuration updated: db.$key = $value");

        // Test database connection if it's a db setting
        if (in_array($key, ['host', 'login', 'pass', 'device'])) {
            $this->io->info('Testing database connection...');
            $db = $this->getDatabaseConnection();
            if ($db) {
                $this->io->success('Database connection successful');
            } else {
                $this->io->warning('Database connection failed with new settings');
            }
        }

        return self::SUCCESS;
    }

    private function setMainConfig(string $key, string $value): int
    {
        $file = $this->config->getKvsPath() . '/admin/include/setup.php';
        if (!file_exists($file)) {
            $this->io->error('Main configuration file not found');
            return self::FAILURE;
        }

        $content = file_get_contents($file);

        // Try to find and replace
        $patterns = [
            "/\\\$config\['$key'\]\s*=\s*[^;]+;/",
            "/\\\$config\[\"$key\"\]\s*=\s*[^;]+;/",
        ];

        $found = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                // Determine if value should be quoted
                if (is_numeric($value) || $value === 'true' || $value === 'false' || $value === 'null') {
                    $replacement = "\$config['$key'] = $value;";
                } else {
                    $replacement = "\$config['$key'] = '$value';";
                }
                $content = preg_replace($pattern, $replacement, $content);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->io->error("Configuration key not found: main.$key");
            return self::FAILURE;
        }

        // Write file
        file_put_contents($file, $content);

        $this->io->success("Configuration updated: main.$key = $value");
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
        $file = $this->getConfigFilePath($this->getConfigFile($category));

        if ($file) {
            $backupFile = $file . '.backup.' . date('YmdHis');
            copy($file, $backupFile);
            $this->io->info("Backup created: $backupFile");
        }
    }

    private function showHelp(): int
    {
        $this->io->info('Usage: kvs config <action> [key] [value] [options]');
        $this->io->newLine();

        $this->io->section('Actions');
        $this->io->listing([
            'get <key> : Get a configuration value',
            'set <key> <value> : Set a configuration value',
            'list : List all configurations',
            'edit : Open config file in editor',
        ]);

        $this->io->section('Examples');
        $this->io->listing([
            'kvs config get db.host',
            'kvs config set db.host 127.0.0.1',
            'kvs config set db.port 3307',
            'kvs config list',
            'kvs config list --file=db',
            'kvs config list --json',
            'kvs config edit --file=db',
        ]);

        $this->io->section('Available Keys');
        $this->io->listing([
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
