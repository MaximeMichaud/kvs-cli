<?php

namespace KVS\CLI\Command\Migrate;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Config\Configuration;
use KVS\CLI\Constants;
use KVS\CLI\Docker\DockerDetector;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\format_bytes;

/**
 * @phpstan-type InstallInfo array{
 *     kvs_path: string,
 *     admin_path: string,
 *     content_path: string,
 *     kvs_version: string|null,
 *     table_prefix: string
 * }
 * @phpstan-type EnvInfo array{
 *     type: string,
 *     docker_available: bool,
 *     kvs_in_docker: bool,
 *     containers: array<string, string>,
 *     container_prefix?: string|null,
 *     cache?: array{available: bool, type: string|null, memory_mb: int|null}
 * }
 * @phpstan-type DbInfo array{
 *     host: string,
 *     database: string,
 *     user: string,
 *     status: string,
 *     version: string|null,
 *     is_mariadb: bool,
 *     tables: int,
 *     size_bytes: int,
 *     error?: string
 * }
 * @phpstan-type ContentInfo array{
 *     videos: array{total: int, active: int, disabled: int, error: int},
 *     albums: array{total: int, active: int, disabled: int},
 *     users: array{total: int, active: int},
 *     categories: int,
 *     tags: int,
 *     models: int,
 *     dvds: int,
 *     comments: int
 * }
 * @phpstan-type StorageInfo array{
 *     content_path: string,
 *     content_exists: bool,
 *     breakdown: array<string, array{path: string, exists: bool, size_bytes: int, files: int}>,
 *     total_bytes: int,
 *     total_files: int
 * }
 * @phpstan-type TotalsInfo array{
 *     database_size_bytes: int,
 *     storage_size_bytes: int,
 *     total_size_bytes: int,
 *     total_files: int,
 *     estimated_package_size_bytes: int
 * }
 * @phpstan-type AssessInfo array{can_migrate: bool, warnings: list<string>, recommendations: list<string>}
 * @phpstan-type ScanResult array{
 *     path: string,
 *     ready_for_migration: bool,
 *     issues: list<string>,
 *     installation: InstallInfo,
 *     environment: EnvInfo,
 *     database: DbInfo,
 *     content: ContentInfo,
 *     storage: StorageInfo,
 *     totals: TotalsInfo,
 *     assessment: AssessInfo
 * }
 */
#[AsCommand(
    name: 'migrate:scan',
    description: 'Scan a KVS installation for migration',
    aliases: ['scan']
)]
class ScanCommand extends BaseCommand
{
    private ?Configuration $targetConfig = null;
    private ?DockerDetector $targetDocker = null;

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to KVS installation to scan')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->setHelp(<<<'EOT'
Scan a KVS installation to analyze its structure, content, and readiness for migration.

<info>Examples:</info>
  kvs migrate:scan /var/www/maximemichaud.ca    # Scan specific installation
  kvs migrate:scan                               # Scan current installation
  kvs migrate:scan /var/www/site --json          # Output as JSON for scripting

<info>Output includes:</info>
  • KVS version and installation type (Docker/Standalone)
  • Database configuration and size
  • Content statistics (videos, albums, users)
  • Storage breakdown and total size
  • Migration readiness assessment
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonOutput = $this->getBoolOption($input, 'json');
        $targetPath = $this->getStringArgument($input, 'path');

        // Use target path or fall back to current installation
        if ($targetPath !== null) {
            try {
                $this->targetConfig = new Configuration(['path' => $targetPath]);
            } catch (\Exception $e) {
                if ($jsonOutput) {
                    $json = json_encode([
                        'error' => true,
                        'message' => $e->getMessage(),
                    ], Constants::JSON_FLAGS);
                    $output->writeln($json !== false ? $json : '{}');
                } else {
                    $this->io()->error($e->getMessage());
                }
                return self::FAILURE;
            }
        } else {
            $this->targetConfig = $this->config;
        }

        // Initialize Docker detector for target
        $this->targetDocker = new DockerDetector();
        $this->targetDocker->setKvsPath($this->targetConfig->getKvsPath());

        $results = $this->performScan();

        if ($jsonOutput) {
            $json = json_encode($results, Constants::JSON_FLAGS);
            $output->writeln($json !== false ? $json : '{}');
        } else {
            $this->displayResults($results);
        }

        return $results['ready_for_migration'] === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return ScanResult
     */
    private function performScan(): array
    {
        $config = $this->targetConfig;
        assert($config !== null);

        $installation = $this->scanInstallation();
        $environment = $this->scanEnvironment();
        $database = $this->scanDatabase();
        $content = $this->scanContent();
        $storage = $this->scanStorage();

        $issues = [];
        $readyForMigration = true;

        if ($database['status'] === 'error') {
            $readyForMigration = false;
            $issues[] = 'Database connection failed';
        }

        $totals = [
            'database_size_bytes' => $database['size_bytes'],
            'storage_size_bytes' => $storage['total_bytes'],
            'total_size_bytes' => $database['size_bytes'] + $storage['total_bytes'],
            'total_files' => $storage['total_files'],
            'estimated_package_size_bytes' => (int) (($database['size_bytes'] + $storage['total_bytes']) * 0.7),
        ];

        $assessment = $this->assessMigration($database, $storage, $environment, $content);

        return [
            'path' => $config->getKvsPath(),
            'ready_for_migration' => $readyForMigration,
            'issues' => $issues,
            'installation' => $installation,
            'environment' => $environment,
            'database' => $database,
            'content' => $content,
            'storage' => $storage,
            'totals' => $totals,
            'assessment' => $assessment,
        ];
    }

    /**
     * @return array{kvs_path: string, admin_path: string, content_path: string, kvs_version: string|null, table_prefix: string}
     */
    private function scanInstallation(): array
    {
        $config = $this->targetConfig;
        assert($config !== null);

        $kvsVersion = null;
        $versionFile = $config->getAdminPath() . '/include/version.php';
        if (file_exists($versionFile)) {
            $content = file_get_contents($versionFile);
            if ($content !== false && preg_match('/\$config\[\'project_version\'\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches) === 1) {
                $kvsVersion = $matches[1];
            }
        }

        return [
            'kvs_path' => $config->getKvsPath(),
            'admin_path' => $config->getAdminPath(),
            'content_path' => $config->getContentPath(),
            'kvs_version' => $kvsVersion,
            'table_prefix' => $config->getTablePrefix(),
        ];
    }

    /**
     * @return EnvInfo
     */
    private function scanEnvironment(): array
    {
        $docker = $this->targetDocker;
        assert($docker !== null);

        $result = [
            'type' => 'standalone',
            'docker_available' => $docker->isDockerAvailable(),
            'kvs_in_docker' => $docker->isKvsInDocker(),
            'containers' => [],
        ];

        if ($docker->isKvsInDocker()) {
            $result['type'] = 'docker';
            $result['containers'] = $docker->getRunningContainers();
            $result['container_prefix'] = $docker->getContainerPrefix();
            $result['cache'] = $docker->checkCache();
        }

        return $result;
    }

    /**
     * @return DbInfo
     */
    private function scanDatabase(): array
    {
        $config = $this->targetConfig;
        assert($config !== null);

        $dbConfig = $config->getDatabaseConfig();

        $result = [
            'host' => $dbConfig['host'] ?? 'N/A',
            'database' => $dbConfig['database'] ?? 'N/A',
            'user' => $dbConfig['user'] ?? 'N/A',
            'status' => 'unknown',
            'version' => null,
            'is_mariadb' => false,
            'tables' => 0,
            'size_bytes' => 0,
        ];

        $db = $this->getTargetDatabaseConnection();
        if ($db === null) {
            $result['status'] = 'error';
            return $result;
        }

        $result['status'] = 'connected';

        try {
            $stmt = $db->query("SELECT VERSION() as version");
            if ($stmt !== false) {
                $row = $stmt->fetch();
                if (is_array($row) && isset($row['version']) && is_string($row['version'])) {
                    $result['version'] = $row['version'];
                    $result['is_mariadb'] = stripos($row['version'], 'mariadb') !== false;
                }
            }

            $stmt = $db->query("SHOW TABLE STATUS");
            if ($stmt !== false) {
                /** @var list<array<string, mixed>> $tables */
                $tables = $stmt->fetchAll();
                $result['tables'] = count($tables);

                $totalSize = 0;
                foreach ($tables as $table) {
                    $dataLength = isset($table['Data_length']) && is_numeric($table['Data_length']) ? (int) $table['Data_length'] : 0;
                    $indexLength = isset($table['Index_length']) && is_numeric($table['Index_length']) ? (int) $table['Index_length'] : 0;
                    $totalSize += $dataLength + $indexLength;
                }
                $result['size_bytes'] = $totalSize;
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @return ContentInfo
     */
    private function scanContent(): array
    {
        $result = [
            'videos' => ['total' => 0, 'active' => 0, 'disabled' => 0, 'error' => 0],
            'albums' => ['total' => 0, 'active' => 0, 'disabled' => 0],
            'users' => ['total' => 0, 'active' => 0],
            'categories' => 0,
            'tags' => 0,
            'models' => 0,
            'dvds' => 0,
            'comments' => 0,
        ];

        $db = $this->getTargetDatabaseConnection();
        if ($db === null) {
            return $result;
        }

        $config = $this->targetConfig;
        assert($config !== null);
        $prefix = $config->getTablePrefix();

        try {
            // Videos
            $stmt = $db->query("SELECT status_id, COUNT(*) as cnt FROM {$prefix}videos GROUP BY status_id");
            if ($stmt !== false) {
                /** @var list<array<string, mixed>> $rows */
                $rows = $stmt->fetchAll();
                foreach ($rows as $row) {
                    $statusId = isset($row['status_id']) && is_numeric($row['status_id']) ? (int) $row['status_id'] : 0;
                    $count = isset($row['cnt']) && is_numeric($row['cnt']) ? (int) $row['cnt'] : 0;
                    $result['videos']['total'] += $count;

                    if ($statusId === StatusFormatter::VIDEO_ACTIVE) {
                        $result['videos']['active'] = $count;
                    } elseif ($statusId === StatusFormatter::VIDEO_DISABLED) {
                        $result['videos']['disabled'] = $count;
                    } elseif ($statusId === StatusFormatter::VIDEO_ERROR) {
                        $result['videos']['error'] = $count;
                    }
                }
            }

            // Albums
            $stmt = $db->query("SELECT status_id, COUNT(*) as cnt FROM {$prefix}albums GROUP BY status_id");
            if ($stmt !== false) {
                /** @var list<array<string, mixed>> $rows */
                $rows = $stmt->fetchAll();
                foreach ($rows as $row) {
                    $statusId = isset($row['status_id']) && is_numeric($row['status_id']) ? (int) $row['status_id'] : 0;
                    $count = isset($row['cnt']) && is_numeric($row['cnt']) ? (int) $row['cnt'] : 0;
                    $result['albums']['total'] += $count;

                    if ($statusId === StatusFormatter::ALBUM_ACTIVE) {
                        $result['albums']['active'] = $count;
                    } elseif ($statusId === StatusFormatter::ALBUM_DISABLED) {
                        $result['albums']['disabled'] = $count;
                    }
                }
            }

            // Users
            $stmt = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}users");
            if ($stmt !== false) {
                $count = $stmt->fetchColumn();
                $result['users']['total'] = is_numeric($count) ? (int) $count : 0;
            }
            $stmt = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}users WHERE status_id >= " . StatusFormatter::USER_ACTIVE);
            if ($stmt !== false) {
                $count = $stmt->fetchColumn();
                $result['users']['active'] = is_numeric($count) ? (int) $count : 0;
            }

            // Other counts
            $tables = [
                'categories' => 'categories',
                'tags' => 'tags',
                'models' => 'models',
                'dvds' => 'dvds',
                'comments' => 'comments',
            ];

            foreach ($tables as $key => $table) {
                try {
                    $stmt = $db->query("SELECT COUNT(*) FROM {$prefix}{$table}");
                    if ($stmt !== false) {
                        $count = $stmt->fetchColumn();
                        $result[$key] = is_numeric($count) ? (int) $count : 0;
                    }
                } catch (\Exception $e) {
                    // Table might not exist
                }
            }
        } catch (\Exception $e) {
            // Ignore errors, return partial results
        }

        return $result;
    }

    /**
     * @return StorageInfo
     */
    private function scanStorage(): array
    {
        $config = $this->targetConfig;
        assert($config !== null);

        $contentPath = $config->getContentPath();

        $result = [
            'content_path' => $contentPath,
            'content_exists' => is_dir($contentPath),
            'breakdown' => [],
            'total_bytes' => 0,
            'total_files' => 0,
        ];

        if (!is_dir($contentPath)) {
            return $result;
        }

        $docker = $this->targetDocker;
        $useDocker = $docker !== null && $docker->isKvsInDocker();

        // Expected KVS directories with their labels
        $expectedDirs = [
            Constants::CONTENT_VIDEOS_SOURCES => 'Videos Sources',
            Constants::CONTENT_VIDEOS_SCREENSHOTS => 'Screenshots',
            Constants::CONTENT_ALBUMS_SOURCES => 'Albums',
            Constants::CONTENT_CATEGORIES => 'Categories',
            Constants::CONTENT_MODELS => 'Models',
            Constants::CONTENT_DVDS => 'DVDs',
            Constants::CONTENT_AVATARS => 'Avatars',
        ];

        // Scan all actual directories in content path
        $actualDirs = [];
        $handle = opendir($contentPath);
        if ($handle !== false) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry !== '.' && $entry !== '..' && is_dir($contentPath . '/' . $entry)) {
                    $actualDirs[] = $entry;
                }
            }
            closedir($handle);
        }
        sort($actualDirs);

        // Process each actual directory
        foreach ($actualDirs as $dir) {
            $path = $contentPath . '/' . $dir;
            $label = $expectedDirs[$dir] ?? ucfirst(str_replace('_', ' ', $dir));

            if ($useDocker) {
                $info = $this->getDirectorySizeDocker($path);
            } else {
                $info = $this->getDirectorySizeLocal($path);
            }

            $result['breakdown'][$label] = [
                'path' => $dir,
                'exists' => $info['exists'],
                'size_bytes' => $info['size'],
                'files' => $info['files'],
            ];

            $result['total_bytes'] += $info['size'];
            $result['total_files'] += $info['files'];
        }

        return $result;
    }

    /**
     * @return array{exists: bool, size: int, files: int}
     */
    private function getDirectorySizeLocal(string $path): array
    {
        if (!is_dir($path)) {
            return ['exists' => false, 'size' => 0, 'files' => 0];
        }

        $size = 0;
        $files = 0;

        if (PHP_OS_FAMILY === 'Linux') {
            $sizeOutput = shell_exec("du -sb " . escapeshellarg($path) . " 2>/dev/null | cut -f1");
            $filesOutput = shell_exec("find " . escapeshellarg($path) . " -type f 2>/dev/null | wc -l");

            if (is_string($sizeOutput)) {
                $size = (int) trim($sizeOutput);
            }
            if (is_string($filesOutput)) {
                $files = (int) trim($filesOutput);
            }

            return ['exists' => true, 'size' => $size, 'files' => $files];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $size += $file->getSize();
                    $files++;
                }
            }
        } catch (\Exception $e) {
            return ['exists' => false, 'size' => 0, 'files' => 0];
        }

        return ['exists' => true, 'size' => $size, 'files' => $files];
    }

    /**
     * @return array{exists: bool, size: int, files: int}
     */
    private function getDirectorySizeDocker(string $path): array
    {
        $docker = $this->targetDocker;
        if ($docker === null) {
            return $this->getDirectorySizeLocal($path);
        }

        $checkResult = $docker->exec('php', 'test -d ' . escapeshellarg($path) . ' && echo "EXISTS" || echo "MISSING"');
        if ($checkResult === null || trim($checkResult) !== 'EXISTS') {
            return ['exists' => false, 'size' => 0, 'files' => 0];
        }

        $sizeResult = $docker->exec('php', "du -sb " . escapeshellarg($path) . " 2>/dev/null | cut -f1");
        $countResult = $docker->exec('php', "find " . escapeshellarg($path) . " -type f 2>/dev/null | wc -l");

        $size = (is_string($sizeResult)) ? (int) trim($sizeResult) : 0;
        $files = (is_string($countResult)) ? (int) trim($countResult) : 0;

        return ['exists' => true, 'size' => $size, 'files' => $files];
    }

    /**
     * @param DbInfo $database
     * @param StorageInfo $storage
     * @param EnvInfo $environment
     * @param ContentInfo $content
     * @return AssessInfo
     */
    private function assessMigration(array $database, array $storage, array $environment, array $content): array
    {
        $assessment = [
            'can_migrate' => true,
            'warnings' => [],
            'recommendations' => [],
        ];

        if ($database['status'] !== 'connected') {
            $assessment['can_migrate'] = false;
            $assessment['warnings'][] = 'Database connection failed - cannot migrate';
        }

        if (!$storage['content_exists']) {
            $assessment['warnings'][] = 'Content directory not found - videos/images may be on external storage';
        }

        if ($environment['type'] === 'docker') {
            $assessment['recommendations'][] = 'Already running in Docker - consider backup instead of migration';
        } else {
            $assessment['recommendations'][] = 'Standalone installation - ready for Docker migration';
        }

        $totalSize = $database['size_bytes'] + $storage['total_bytes'];
        if ($totalSize > 100 * 1024 * 1024 * 1024) {
            $assessment['warnings'][] = 'Large installation (>100GB) - migration may take significant time';
        }

        $errorVideos = $content['videos']['error'];
        if ($errorVideos > 0) {
            $assessment['warnings'][] = "{$errorVideos} videos have error status - review before migration";
        }

        return $assessment;
    }

    /**
     * @param ScanResult $results
     */
    private function displayResults(array $results): void
    {
        $this->io()->title('KVS Installation Scan');

        // Installation Info
        $this->io()->section('Installation');
        $install = $results['installation'];
        $this->renderTable(['Parameter', 'Value'], [
            ['KVS Path', $install['kvs_path']],
            ['KVS Version', $install['kvs_version'] ?? 'Unknown'],
            ['Content Path', $install['content_path']],
            ['Table Prefix', $install['table_prefix']],
        ]);

        // Environment
        $this->io()->section('Environment');
        $env = $results['environment'];
        $envType = $env['type'] === 'docker' ? '<fg=cyan>Docker</>' : '<fg=yellow>Standalone</>';
        /** @var list<array{string, string}> $envInfo */
        $envInfo = [
            ['Type', $envType],
            ['Docker Available', $env['docker_available'] ? 'Yes' : 'No'],
        ];
        if ($env['type'] === 'docker') {
            $containers = implode(', ', array_values($env['containers']));
            $envInfo[] = ['Containers', $containers];
            if (isset($env['cache']['type'])) {
                $memoryMb = isset($env['cache']['memory_mb']) ? (string) $env['cache']['memory_mb'] : '?';
                $envInfo[] = ['Cache', $env['cache']['type'] . ' (' . $memoryMb . 'MB)'];
            }
        }
        $this->renderTable(['Parameter', 'Value'], $envInfo);

        // Database
        $this->io()->section('Database');
        $db = $results['database'];
        $dbStatus = match ($db['status']) {
            'connected' => '<fg=green>Connected</>',
            'error' => '<fg=red>Failed</>',
            default => '<fg=yellow>Unknown</>',
        };
        /** @var list<array{string, string}> $dbInfo */
        $dbInfo = [
            ['Host', $db['host']],
            ['Database', $db['database']],
            ['Status', $dbStatus],
        ];
        if ($db['status'] === 'connected') {
            $dbType = $db['is_mariadb'] ? 'MariaDB' : 'MySQL';
            $dbInfo[] = ['Version', $dbType . ' ' . ($db['version'] ?? 'Unknown')];
            $dbInfo[] = ['Tables', (string) $db['tables']];
            $dbInfo[] = ['Size', format_bytes($db['size_bytes'])];
        }
        $this->renderTable(['Parameter', 'Value'], $dbInfo);

        // Content Statistics
        $this->io()->section('Content Statistics');
        $content = $results['content'];
        /** @var list<array{string, string}> $contentStats */
        $contentStats = [
            ['Videos', sprintf(
                '%d total (%d active, %d disabled, %d error)',
                $content['videos']['total'],
                $content['videos']['active'],
                $content['videos']['disabled'],
                $content['videos']['error']
            )],
            ['Albums', sprintf(
                '%d total (%d active, %d disabled)',
                $content['albums']['total'],
                $content['albums']['active'],
                $content['albums']['disabled']
            )],
            ['Users', sprintf('%d total (%d active)', $content['users']['total'], $content['users']['active'])],
            ['Categories', (string) $content['categories']],
            ['Tags', (string) $content['tags']],
            ['Models', (string) $content['models']],
            ['DVDs', (string) $content['dvds']],
            ['Comments', (string) $content['comments']],
        ];
        $this->renderTable(['Content Type', 'Count'], $contentStats);

        // Storage Breakdown
        $this->io()->section('Storage Breakdown');
        $storage = $results['storage'];
        if ($storage['content_exists']) {
            /** @var list<array{string, string, string}> $storageRows */
            $storageRows = [];
            foreach ($storage['breakdown'] as $label => $info) {
                if ($info['exists']) {
                    $storageRows[] = [
                        $label,
                        format_bytes($info['size_bytes']),
                        number_format($info['files']) . ' files',
                    ];
                } else {
                    $storageRows[] = [$label, 'Not found', '-'];
                }
            }
            $storageRows[] = ['---', '---', '---'];
            $totalFiles = number_format($storage['total_files']) . ' files';
            $storageRows[] = ['<fg=white;options=bold>Total</>', format_bytes($storage['total_bytes']), $totalFiles];
            $this->renderTable(['Type', 'Size', 'Files'], $storageRows);
        } else {
            $this->io()->warning('Content directory not found: ' . $storage['content_path']);
        }

        // Totals
        $this->io()->section('Migration Summary');
        $totals = $results['totals'];
        $this->renderTable(['Metric', 'Value'], [
            ['Database Size', format_bytes($totals['database_size_bytes'])],
            ['Content Size', format_bytes($totals['storage_size_bytes'])],
            ['Total Size', format_bytes($totals['total_size_bytes'])],
            ['Total Files', number_format($totals['total_files'])],
            ['Est. Package Size (zstd)', format_bytes($totals['estimated_package_size_bytes'])],
        ]);

        // Assessment
        $assessment = $results['assessment'];
        if ($assessment['can_migrate']) {
            $this->io()->success('Ready for migration');
        } else {
            $this->io()->error('Cannot migrate - fix issues first');
        }

        if (count($assessment['warnings']) > 0) {
            $this->io()->warning('Warnings:');
            $this->io()->listing($assessment['warnings']);
        }

        if (count($assessment['recommendations']) > 0) {
            $this->io()->note('Recommendations:');
            $this->io()->listing($assessment['recommendations']);
        }
    }

    private function getTargetDatabaseConnection(): ?\PDO
    {
        $config = $this->targetConfig;
        if ($config === null) {
            return null;
        }

        $dbConfig = $config->getDatabaseConfig();

        $requiredKeys = ['host', 'database', 'user'];
        foreach ($requiredKeys as $key) {
            if (!isset($dbConfig[$key]) || $dbConfig[$key] === '') {
                return null;
            }
        }
        if (!array_key_exists('password', $dbConfig)) {
            return null;
        }

        $hostsToTry = [$dbConfig['host']];
        $originalHost = $dbConfig['host'];
        if (!str_contains($originalHost, '.') && $originalHost !== 'localhost' && $originalHost !== '127.0.0.1') {
            $hostsToTry[] = '127.0.0.1';
            $hostsToTry[] = 'localhost';
        }

        foreach ($hostsToTry as $host) {
            try {
                $port = 3306;
                if (str_contains($host, ':')) {
                    [$host, $portStr] = explode(':', $host, 2);
                    $port = (int) $portStr;
                }

                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=' . Constants::DB_CHARSET,
                    $host,
                    $port,
                    $dbConfig['database']
                );

                return new \PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_TIMEOUT => 3,
                ]);
            } catch (\PDOException $e) {
                continue;
            }
        }

        return null;
    }
}
