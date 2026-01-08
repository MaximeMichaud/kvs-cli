<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use KVS\CLI\Output\StatusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'system:status',
    description: 'Show KVS system status',
    aliases: ['status']
)]
class StatusCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io()->title('KVS System Status');

        $this->showInstallationInfo();
        $this->showDatabaseStatus();
        $this->showSystemInfo();
        $this->showServicesStatus();
        $this->showContentStats();
        $this->showConversionQueue();
        $this->showStorageBreakdown();
        $this->showHealthChecks();
        $this->showSecurityWarnings();

        return self::SUCCESS;
    }

    private function showInstallationInfo(): void
    {
        $this->io()->section('Installation');

        $info = [];

        $kvsPath = $this->config->getKvsPath();
        $info[] = ['KVS Path', $kvsPath !== '' ? $kvsPath : 'Not found'];
        $info[] = ['Admin Path', $this->config->getAdminPath()];
        $info[] = ['Content Path', $this->config->getContentPath()];

        $versionFile = $this->config->getAdminPath() . '/include/setup.php';
        if (file_exists($versionFile)) {
            $content = file_get_contents($versionFile);
            if ($content !== false && preg_match('/\$config\[\'project_version\'\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches) === 1) {
                $info[] = ['KVS Version', $matches[1]];
            }
        }

        /** @var list<array{string, string}> $info */
        $this->renderTable(['Parameter', 'Value'], $info);
    }

    private function showDatabaseStatus(): void
    {
        $this->io()->section('Database');

        $db = $this->getDatabaseConnection();

        if ($db === null) {
            $this->io()->error('Database connection failed');
            return;
        }

        $info = [];

        $dbConfig = $this->config->getDatabaseConfig();
        $info[] = ['Host', $dbConfig['host'] ?? 'N/A'];
        $info[] = ['Database', $dbConfig['database'] ?? 'N/A'];

        try {
            $stmt = $db->query("SELECT VERSION() as version");
            if ($stmt === false) {
                throw new \Exception('Failed to query MySQL version');
            }
            $version = $stmt->fetch();
            if (is_array($version) && isset($version['version']) && (is_string($version['version']) || is_numeric($version['version']))) {
                $info[] = ['MySQL Version', (string) $version['version']];
            }

            $stmt = $db->query("SHOW TABLE STATUS");
            if ($stmt === false) {
                throw new \Exception('Failed to query table status');
            }
            $tables = $stmt->fetchAll();
            $info[] = ['Total Tables', count($tables)];

            $totalSize = 0;
            foreach ($tables as $table) {
                $totalSize += (int)($table['Data_length'] ?? 0) + (int)($table['Index_length'] ?? 0);
            }
            $info[] = ['Database Size', format_bytes($totalSize)];
        } catch (\Exception $e) {
            $this->io()->warning('Could not fetch database statistics');
        }

        $this->renderTable(['Parameter', 'Value'], $info);
    }

    private function showSystemInfo(): void
    {
        $this->io()->section('System');

        $info = [];

        $info[] = ['Operating System', $this->getOsInfo()];
        $info[] = ['Web Server', $_SERVER['SERVER_SOFTWARE'] ?? 'CLI'];

        // Use Docker-aware methods for PHP info
        $phpVersion = $this->getKvsPhpVersion();
        $phpSource = $this->isDockerMode() ? ' (Docker)' : '';
        $info[] = ['PHP Version', $phpVersion . $phpSource];

        $memLimit = $this->getPhpSetting('memory_limit');
        $info[] = ['PHP Memory Limit', $memLimit !== false ? $memLimit : 'N/A'];

        $maxExec = $this->getPhpSetting('max_execution_time');
        $info[] = ['Max Execution Time', ($maxExec !== false ? $maxExec : '0') . ' seconds'];

        $uploadMax = $this->getPhpSetting('upload_max_filesize');
        $info[] = ['Upload Max Filesize', $uploadMax !== false ? $uploadMax : 'N/A'];

        $postMax = $this->getPhpSetting('post_max_size');
        $info[] = ['Post Max Size', $postMax !== false ? $postMax : 'N/A'];

        if (function_exists('disk_free_space')) {
            $diskPath = $this->config->getKvsPath();
            if ($diskPath === '') {
                $diskPath = '.';
            }
            $free = disk_free_space($diskPath);
            $total = disk_total_space($diskPath);

            if ($free !== false && $total !== false) {
                $used = $total - $free;

                $info[] = ['Disk Usage', sprintf(
                    '%s / %s (%.1f%%)',
                    format_bytes((int)$used),
                    format_bytes((int)$total),
                    ($used / $total) * 100
                )];
            }
        }

        /** @var list<array{string, string}> $info */
        $this->renderTable(['Parameter', 'Value'], $info);
    }

    private function showContentStats(): void
    {
        $this->io()->section('Content Statistics');

        $db = $this->getDatabaseConnection();

        if ($db === null) {
            return;
        }

        $stats = [];

        try {
            $queries = [
                'Videos' => "SELECT COUNT(*) FROM " . $this->table('videos') . " WHERE status_id = " . StatusFormatter::VIDEO_ACTIVE,
                'Albums' => "SELECT COUNT(*) FROM " . $this->table('albums') . " WHERE status_id = " . StatusFormatter::ALBUM_ACTIVE,
                'Users' => "SELECT COUNT(*) FROM " . $this->table('users')
                    . " WHERE status_id NOT IN (" . StatusFormatter::USER_DISABLED
                    . "," . StatusFormatter::USER_NOT_CONFIRMED . ")",
                'Categories' => "SELECT COUNT(*) FROM " . $this->table('categories') . "",
                'Tags' => "SELECT COUNT(*) FROM " . $this->table('tags') . "",
                'Models' => "SELECT COUNT(*) FROM " . $this->table('models') . "",
                'DVDs' => "SELECT COUNT(*) FROM " . $this->table('dvds') . "",
            ];

            foreach ($queries as $label => $query) {
                try {
                    $stmt = $db->query($query);
                    if ($stmt === false) {
                        throw new \Exception('Query failed');
                    }
                    $count = $stmt->fetchColumn();
                    $stats[] = [$label, number_format((int)$count)];
                } catch (\Exception $e) {
                    $stats[] = [$label, 'N/A'];
                }
            }
        } catch (\Exception $e) {
            $this->io()->warning('Could not fetch content statistics');
            return;
        }

        $this->renderTable(['Content Type', 'Count'], $stats);
    }

    private function getOsInfo(): string
    {
        // For non-Linux systems, just return PHP_OS
        if (PHP_OS_FAMILY !== 'Linux') {
            return PHP_OS;
        }

        // Try to get distribution info from /etc/os-release (modern standard)
        if (file_exists('/etc/os-release')) {
            $osRelease = parse_ini_file('/etc/os-release');

            if ($osRelease !== false && $osRelease !== []) {
                $name = $osRelease['NAME'] ?? $osRelease['ID'] ?? 'Linux';
                $version = $osRelease['VERSION_ID'] ?? $osRelease['VERSION'] ?? '';

                // For rolling releases like Arch/CachyOS that might not have VERSION_ID
                if ($version === '' && isset($osRelease['BUILD_ID'])) {
                    $version = $osRelease['BUILD_ID'];
                }

                // Get kernel version for additional context
                $kernel = php_uname('r');

                if ($version !== '') {
                    return sprintf('%s %s (kernel %s)', $name, $version, $kernel);
                }

                return sprintf('%s (kernel %s)', $name, $kernel);
            }
        }

        // Fallback to uname for kernel info
        $uname = php_uname('s') . ' ' . php_uname('r');

        // Try to detect distribution from files
        $distroFiles = [
            '/etc/debian_version' => 'Debian',
            '/etc/redhat-release' => 'RedHat',
            '/etc/arch-release' => 'Arch',
            '/etc/gentoo-release' => 'Gentoo',
        ];

        foreach ($distroFiles as $file => $distro) {
            if (file_exists($file)) {
                return "$distro Linux (kernel " . php_uname('r') . ')';
            }
        }

        return $uname;
    }

    private function showServicesStatus(): void
    {
        $this->io()->section('Services Status');

        $services = [];

        // Check FFmpeg
        $ffmpegPath = $this->checkCommand('ffmpeg', '--version');
        if ($ffmpegPath['available']) {
            $services[] = ['✓', 'FFmpeg', $ffmpegPath['path'], $ffmpegPath['version']];
        } else {
            $services[] = ['✗', 'FFmpeg', 'Not found', 'N/A'];
        }

        // Check ImageMagick
        $convertPath = $this->checkCommand('convert', '--version');
        if ($convertPath['available']) {
            $services[] = ['✓', 'ImageMagick', $convertPath['path'], $convertPath['version']];
        } else {
            $services[] = ['✗', 'ImageMagick', 'Not found', 'N/A'];
        }

        // Check MySQLDump
        $mysqldumpPath = $this->checkCommand('mysqldump', '--version');
        if ($mysqldumpPath['available']) {
            $services[] = ['✓', 'MySQLDump', $mysqldumpPath['path'], $mysqldumpPath['version']];
        } else {
            $services[] = ['✗', 'MySQLDump', 'Not found', 'N/A'];
        }

        // Check cache (Memcached or Dragonfly)
        $cacheStatus = $this->checkMemcached();
        $services[] = [
            $cacheStatus['available'] ? '✓' : '✗',
            $cacheStatus['type'],
            $cacheStatus['host'],
            $cacheStatus['status']
        ];

        $this->renderTable(['Status', 'Service', 'Path/Host', 'Version'], $services);
    }

    /**
     * @return array{available: bool, path: string, version: string}
     */
    private function checkCommand(string $command, string $versionFlag = '--version'): array
    {
        $result = [
            'available' => false,
            'path' => 'N/A',
            'version' => 'N/A'
        ];

        // Check if command exists
        $which = shell_exec("which $command 2>/dev/null");
        if ($which === null || $which === false || trim($which) === '') {
            return $result;
        }

        $result['path'] = trim($which);
        $result['available'] = true;

        // Get version
        $versionOutput = shell_exec("$command $versionFlag 2>&1 | head -n 1");
        if ($versionOutput !== null && $versionOutput !== false) {
            // Extract version number from output
            if (preg_match('/(\d+\.\d+\.\d+)/', $versionOutput, $matches) === 1) {
                $result['version'] = $matches[1];
            } else {
                $result['version'] = 'Available';
            }
        }

        return $result;
    }

    /**
     * @return array{available: bool, host: string, status: string, type: string}
     */
    private function checkMemcached(): array
    {
        // Read memcached configuration from KVS config
        $serverValue = $this->config->get('memcache_server', '127.0.0.1');
        $server = is_string($serverValue) ? $serverValue : '127.0.0.1';
        $portValue = $this->config->get('memcache_port', Constants::DEFAULT_MEMCACHE_PORT);
        $port = is_int($portValue) ? $portValue : Constants::DEFAULT_MEMCACHE_PORT;

        $result = [
            'available' => false,
            'host' => "$server:$port",
            'status' => 'Not responding',
            'type' => 'Memcached'
        ];

        // In Docker mode, use centralized cache check
        if ($this->isDockerMode()) {
            $cacheInfo = $this->docker()->checkCache();
            if ($cacheInfo['type'] !== null) {
                $result['type'] = $cacheInfo['type'];
            }
            if ($cacheInfo['available']) {
                $result['available'] = true;
                $result['status'] = 'Connected';
            }
            return $result;
        }

        // Non-Docker mode: use local Memcached extension
        if (!class_exists('Memcached')) {
            $result['status'] = 'Extension not installed';
            return $result;
        }

        try {
            $memcached = new \Memcached();
            $memcached->addServer($server, $port);
            $memcached->set('kvs_cli_test', 'test', 10);
            $value = $memcached->get('kvs_cli_test');

            if ($value === 'test') {
                $result['available'] = true;
                $result['status'] = 'Connected';
            }
        } catch (\Exception $e) {
            $result['status'] = 'Error: ' . $e->getMessage();
        }

        return $result;
    }

    private function showConversionQueue(): void
    {
        $this->io()->section('Video Processing');

        $db = $this->getDatabaseConnection();
        if ($db === null) {
            $this->io()->warning('Database connection not available');
            return;
        }

        try {
            // Check if background_tasks table exists
            $stmt = $db->query("SHOW TABLES LIKE '" . $this->config->getTablePrefix() . "background_tasks'");
            if ($stmt === false || $stmt->rowCount() === 0) {
                $this->io()->text('No conversion queue table found (' . $this->table('background_tasks') . ')');
                return;
            }

            $stats = [];

            // Pending tasks
            $stmt = $db->query("SELECT COUNT(*) FROM " . $this->table('background_tasks') . " WHERE status_id = " . StatusFormatter::TASK_PENDING);
            if ($stmt === false) {
                throw new \Exception('Failed to query pending tasks');
            }
            $pending = $stmt->fetchColumn();
            $stats[] = ['Pending', number_format((int)$pending)];

            // Processing tasks
            $stmt = $db->query("SELECT COUNT(*) FROM " . $this->table('background_tasks') . " WHERE status_id = " . StatusFormatter::TASK_PROCESSING);
            if ($stmt === false) {
                throw new \Exception('Failed to query processing tasks');
            }
            $processing = $stmt->fetchColumn();
            $stats[] = ['Processing', number_format((int)$processing)];

            // Failed tasks (last 24h)
            $sql = "SELECT COUNT(*) FROM " . $this->table('background_tasks')
                . " WHERE status_id = " . StatusFormatter::TASK_FAILED
                . " AND added_date >= DATE_SUB(NOW(), INTERVAL " . Constants::RECENT_HOURS . " HOUR)";
            $stmt = $db->query($sql);
            if ($stmt === false) {
                throw new \Exception('Failed to query failed tasks');
            }
            $failed = $stmt->fetchColumn();
            $stats[] = ['Failed (24h)', number_format((int)$failed)];

            // Average processing time (completed tasks)
            // KVS stores duration in effective_duration column (seconds)
            $stmt = $db->query("
                SELECT AVG(effective_duration) as avg_time
                FROM " . $this->table('background_tasks') . "
                WHERE status_id = " . StatusFormatter::TASK_COMPLETED . " AND effective_duration > 0
                LIMIT " . Constants::STATS_SAMPLE_LIMIT . "
            ");
            if ($stmt === false) {
                throw new \Exception('Failed to query average time');
            }
            $avgTime = $stmt->fetchColumn();
            if ($avgTime !== false && $avgTime !== null && $avgTime !== 0) {
                $minutes = floor((float)$avgTime / 60);
                $seconds = (float)$avgTime % 60;
                $stats[] = ['Average Time', sprintf('%dm %ds', $minutes, $seconds)];
            } else {
                $stats[] = ['Average Time', 'N/A'];
            }

            $this->renderTable(['Metric', 'Value'], $stats);
        } catch (\Exception $e) {
            $this->io()->warning('Could not fetch conversion queue data: ' . $e->getMessage());
        }
    }

    private function showStorageBreakdown(): void
    {
        $this->io()->section('Storage Breakdown');

        $contentPath = $this->config->getContentPath();

        // In Docker mode, the content path is inside the container
        if ($this->isDockerMode()) {
            $this->showStorageBreakdownDocker($contentPath);
            return;
        }

        if ($contentPath === '' || !is_dir($contentPath)) {
            $this->io()->warning('Content directory not found: ' . ($contentPath !== '' ? $contentPath : 'not configured'));
            $this->io()->text('This is normal if content is stored on external storage servers.');
            $this->io()->text('Check KVS config for content_path_* settings.');
            return;
        }

        $storage = [];
        $totalSize = 0;

        // Define content directories to check (KVS naming convention)
        $directories = [
            'Videos Sources' => Constants::CONTENT_VIDEOS_SOURCES,
            'Screenshots' => Constants::CONTENT_VIDEOS_SCREENSHOTS,
            'Albums' => Constants::CONTENT_ALBUMS_SOURCES,
            'Categories' => Constants::CONTENT_CATEGORIES,
            'Models' => Constants::CONTENT_MODELS,
            'DVDs' => Constants::CONTENT_DVDS,
            'Avatars' => Constants::CONTENT_AVATARS,
        ];

        foreach ($directories as $label => $dir) {
            $path = $contentPath . '/' . $dir;
            if (is_dir($path)) {
                $size = $this->getDirectorySize($path);
                $totalSize += $size;

                // Count files
                $fileCount = $this->countFiles($path);

                $storage[] = [
                    $label,
                    format_bytes($size),
                    number_format($fileCount) . ' files'
                ];
            } else {
                $storage[] = [$label, 'Not found', '-'];
            }
        }

        // Add total
        $storage[] = ['---', '---', '---'];
        $storage[] = ['Total Content', format_bytes($totalSize), ''];

        $this->renderTable(['Type', 'Size', 'Files'], $storage);
    }

    /**
     * Show storage breakdown via Docker container.
     */
    private function showStorageBreakdownDocker(string $contentPath): void
    {
        if ($contentPath === '') {
            $this->io()->text('Content path not configured.');
            return;
        }

        // Check if path exists inside container
        $checkResult = $this->docker()->exec('php', 'test -d ' . escapeshellarg($contentPath) . ' && echo "EXISTS" || echo "MISSING"');
        if ($checkResult === null || trim($checkResult) !== 'EXISTS') {
            $this->io()->text("Content path: $contentPath (inside container)");
            $this->io()->text('Directory not accessible from PHP container.');
            return;
        }

        $storage = [];
        $totalSize = 0;

        $directories = [
            'Videos Sources' => Constants::CONTENT_VIDEOS_SOURCES,
            'Screenshots' => Constants::CONTENT_VIDEOS_SCREENSHOTS,
            'Albums' => Constants::CONTENT_ALBUMS_SOURCES,
            'Categories' => Constants::CONTENT_CATEGORIES,
            'Models' => Constants::CONTENT_MODELS,
            'DVDs' => Constants::CONTENT_DVDS,
            'Avatars' => Constants::CONTENT_AVATARS,
        ];

        foreach ($directories as $label => $dir) {
            $path = $contentPath . '/' . $dir;

            // Get size via du command in container
            $sizeResult = $this->docker()->exec('php', "du -sb " . escapeshellarg($path) . " 2>/dev/null | cut -f1");
            $countResult = $this->docker()->exec('php', "find " . escapeshellarg($path) . " -type f 2>/dev/null | wc -l");

            if ($sizeResult !== null && trim($sizeResult) !== '') {
                $size = (int) trim($sizeResult);
                $totalSize += $size;
                $fileCount = $countResult !== null ? (int) trim($countResult) : 0;

                $storage[] = [
                    $label,
                    format_bytes($size),
                    number_format($fileCount) . ' files'
                ];
            } else {
                $storage[] = [$label, 'Not found', '-'];
            }
        }

        // Add total
        $storage[] = ['---', '---', '---'];
        $storage[] = ['Total Content', format_bytes($totalSize), ''];

        $this->io()->text("Content path: $contentPath (Docker)");
        $this->renderTable(['Type', 'Size', 'Files'], $storage);
    }

    private function getDirectorySize(string $path): int
    {
        // Use du command for faster calculation on Linux
        if (PHP_OS_FAMILY === 'Linux') {
            $output = shell_exec("du -sb " . escapeshellarg($path) . " 2>/dev/null | cut -f1");
            if ($output !== null && $output !== false) {
                $trimmed = trim($output);
                if ($trimmed !== '') {
                    return (int)$trimmed;
                }
            }
        }

        // Fallback to PHP iteration
        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            // Directory might not exist or not readable
            return 0;
        }

        return $size;
    }

    private function countFiles(string $path): int
    {
        // Use find command for faster counting on Linux
        if (PHP_OS_FAMILY === 'Linux') {
            $output = shell_exec("find " . escapeshellarg($path) . " -type f 2>/dev/null | wc -l");
            if ($output !== null && $output !== false) {
                $trimmed = trim($output);
                if ($trimmed !== '') {
                    return (int)$trimmed;
                }
            }
        }

        // Fallback to PHP iteration
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $count++;
                }
            }
        } catch (\Exception $e) {
            return 0;
        }

        return $count;
    }

    private function showHealthChecks(): void
    {
        $this->io()->section('System Health');

        $health = [];

        // Database connectivity
        $db = $this->getDatabaseConnection();
        $health[] = $db !== null ? ['✓', 'Database connectivity', 'OK'] : ['✗', 'Database connectivity', 'FAILED'];

        // File permissions - admin/data directory
        $adminDataPath = $this->config->getAdminPath() . '/data';
        if (is_dir($adminDataPath)) {
            $writable = is_writable($adminDataPath);
            $health[] = [
                $writable ? '✓' : '✗',
                'Admin data directory',
                $writable ? 'Writable' : 'Not writable'
            ];
        }

        // File permissions - content directory
        $contentPath = $this->config->getContentPath();
        if (is_dir($contentPath)) {
            $writable = is_writable($contentPath);
            $health[] = [
                $writable ? '✓' : '✗',
                'Content directory',
                $writable ? 'Writable' : 'Not writable'
            ];
        }

        // Disk space check
        if (function_exists('disk_free_space')) {
            $kvsPath = $this->config->getKvsPath();
            $diskPath = $kvsPath !== '' ? $kvsPath : '.';
            $free = disk_free_space($diskPath);
            $total = disk_total_space($diskPath);

            if ($free !== false && $total !== false && $total > 0) {
                $usedPercent = (($total - $free) / $total) * 100;

                if ($usedPercent > Constants::DISK_CRITICAL_PERCENT) {
                    $health[] = ['⚠', 'Disk space', sprintf('%.1f%% used (CRITICAL)', $usedPercent)];
                } elseif ($usedPercent > Constants::DISK_WARNING_PERCENT) {
                    $health[] = ['⚠', 'Disk space', sprintf('%.1f%% used (WARNING)', $usedPercent)];
                } else {
                    $health[] = ['✓', 'Disk space', sprintf('%.1f%% used', $usedPercent)];
                }
            }
        }

        // PHP extensions (Docker-aware)
        $requiredExtensions = ['pdo', 'pdo_mysql', 'mysqli', 'gd', 'json', 'mbstring'];
        $missingExtensions = [];
        foreach ($requiredExtensions as $ext) {
            if (!$this->isExtensionLoaded($ext)) {
                $missingExtensions[] = $ext;
            }
        }

        $extSource = $this->isDockerMode() ? ' (Docker)' : '';
        if ($missingExtensions === []) {
            $health[] = ['✓', 'PHP extensions', 'All required extensions loaded' . $extSource];
        } else {
            $health[] = ['✗', 'PHP extensions', 'Missing: ' . implode(', ', $missingExtensions) . $extSource];
        }

        $this->renderTable(['Status', 'Check', 'Result'], $health);
    }

    private function showSecurityWarnings(): void
    {
        $this->io()->section('Security');

        $security = [];

        // Check if setup.php exists for config reading
        $setupFile = $this->config->getAdminPath() . '/include/setup.php';
        if (file_exists($setupFile)) {
            $setupContent = file_get_contents($setupFile);

            if ($setupContent !== false) {
                // Check maintenance mode
                if (preg_match('/\$config\[\'is_clone\'\]\s*=\s*1/', $setupContent) === 1) {
                    $security[] = ['⚠', 'Maintenance mode', 'ENABLED'];
                } else {
                    $security[] = ['✓', 'Maintenance mode', 'DISABLED'];
                }

                // Check debug mode
                if (
                    preg_match('/\$config\[\'debug_mode\'\]\s*=\s*[\'"]true[\'"]/i', $setupContent) === 1 ||
                    preg_match('/\$config\[\'debug_mode\'\]\s*=\s*1/', $setupContent) === 1
                ) {
                    $security[] = ['⚠', 'Debug mode', 'ENABLED (should be disabled in production)'];
                } else {
                    $security[] = ['✓', 'Debug mode', 'DISABLED'];
                }
            }
        }

        // Check for recent database backups
        $db = $this->getDatabaseConnection();
        if ($db !== null) {
            try {
                // Check if backup log table exists
                $stmt = $db->query("SHOW TABLES LIKE '" . $this->config->getTablePrefix() . "admin_system_log'");
                if ($stmt !== false && $stmt->rowCount() > 0) {
                    // Look for recent backup entries
                    $stmt = $db->query("
                        SELECT MAX(added_date) as last_backup
                        FROM " . $this->table('admin_system_log') . "
                        WHERE event_level = 'info'
                        AND event_message LIKE '%backup%'
                    ");
                    if ($stmt !== false) {
                        $lastBackup = $stmt->fetchColumn();

                        if ($lastBackup !== false && $lastBackup !== null) {
                            $backupTime = strtotime((string)$lastBackup);
                            if ($backupTime !== false) {
                                $hoursAgo = (int)floor((time() - $backupTime) / 3600);

                                if ($hoursAgo < Constants::BACKUP_WARNING_HOURS) {
                                    $security[] = ['✓', 'Database backups', "Last backup $hoursAgo hours ago"];
                                } else {
                                    $daysAgo = (int)floor($hoursAgo / 24);
                                    $security[] = ['⚠', 'Database backups', "Last backup $daysAgo days ago"];
                                }
                            }
                        } else {
                            $security[] = ['⚠', 'Database backups', 'No recent backups found'];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Backup check failed, not critical
            }
        }

        // Check PHP display_errors (should be off in production) - Docker-aware
        $displayErrors = $this->getPhpSetting('display_errors');
        if ($displayErrors !== false && $displayErrors !== '' && $displayErrors !== 'Off' && $displayErrors !== '0') {
            $security[] = ['⚠', 'PHP display_errors', 'ENABLED (should be disabled in production)'];
        } else {
            $security[] = ['✓', 'PHP display_errors', 'DISABLED'];
        }

        $this->renderTable(['Status', 'Item', 'Details'], $security);
    }
}
