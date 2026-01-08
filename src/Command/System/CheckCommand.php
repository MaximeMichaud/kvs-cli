<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'system:check',
    description: 'Check KVS configuration and system health',
    aliases: ['check']
)]
class CheckCommand extends BaseCommand
{
    // Thresholds
    private const MEMCACHE_MIN_MB = 256;
    private const OPCACHE_MIN_MB = 128;
    private const OPCACHE_STRINGS_MIN_MB = 8;
    private const UPLOAD_MIN_MB = 512;
    private const MEMORY_LIMIT_MIN_MB = 128;
    private const INNODB_BUFFER_MIN_MB = 512;
    private const DISK_WARNING_PERCENT = 80;
    private const DISK_CRITICAL_PERCENT = 90;
    private const LOAD_WARNING_THRESHOLD = 0.7;
    private const LOAD_CRITICAL_THRESHOLD = 1.0;
    private const IOWAIT_WARNING_PERCENT = 10;
    private const IOWAIT_CRITICAL_PERCENT = 20;
    private const INTERNET_TIMEOUT = 5;

    // PHP version requirements per KVS version (min, max)
    private const KVS_PHP_REQUIREMENTS = [
        '6.4' => ['min' => '8.1', 'max' => '8.1.99'],
        '6.3' => ['min' => '8.1', 'max' => '8.1.99'],
        '6.2' => ['min' => '7.1', 'max' => '7.4.99'],
        '6.1' => ['min' => '7.1', 'max' => '7.4.99'],
        '6.0' => ['min' => '7.1', 'max' => '7.4.99'],
        '5.5' => ['min' => '7.2', 'max' => '7.4.99'],
    ];

    // MySQL/MariaDB minimum versions
    private const MYSQL_MIN_VERSION = '8.0';
    private const MARIADB_MIN_VERSION = '10.6';

    // Required PHP extensions
    private const REQUIRED_PHP_EXTENSIONS = [
        'mysqli' => 'MySQL Improved Extension',
        'curl' => 'Client URL Library',
        'zlib' => 'Zlib Compression',
        'simplexml' => 'SimpleXML',
        'gd' => 'Image Processing and GD',
        'mbstring' => 'Multibyte String',
    ];

    private int $errors = 0;
    private int $warnings = 0;
    private bool $silent = false;

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('quiet-ok', null, InputOption::VALUE_NONE, 'Only show warnings and errors');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonOutput = $this->getBoolOption($input, 'json');
        $quietOk = $this->getBoolOption($input, 'quiet-ok');

        // For JSON output, suppress all visual output
        $this->silent = $jsonOutput;

        if (!$this->silent) {
            $this->io()->title('KVS Configuration Check');
        }

        $results = [];

        // Check Docker environment first
        $results['docker'] = $this->checkDockerEnvironment($quietOk);

        $results['update'] = $this->checkKvsUpdate($quietOk);
        $results['php_kvs'] = $this->checkPhpKvsCompatibility($quietOk);
        $results['php_extensions'] = $this->checkPhpExtensions($quietOk);
        $results['tools'] = $this->checkTools($quietOk);
        $results['memcached'] = $this->checkMemcached($quietOk);
        $results['opcache'] = $this->checkOpcache($quietOk);
        $results['php_settings'] = $this->checkPhpSettings($quietOk);
        $results['cron'] = $this->checkCron($quietOk);
        $results['mysql'] = $this->checkMysql($quietOk);
        $results['system_load'] = $this->checkSystemLoad($quietOk);
        $results['disk_space'] = $this->checkDiskSpace($quietOk);
        $results['internet'] = $this->checkInternet($quietOk);
        $results['end_of_life'] = $this->checkEndOfLife($quietOk, $results);

        if ($jsonOutput === true) {
            $json = json_encode([
                'results' => $results,
                'summary' => [
                    'errors' => $this->errors,
                    'warnings' => $this->warnings,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                $output->writeln($json);
            }
        } else {
            $this->io()->newLine();
            $this->io()->writeln(str_repeat('─', 42));

            if ($this->errors === 0 && $this->warnings === 0) {
                $this->io()->success('All checks passed!');
            } else {
                $summary = sprintf(
                    'Result: %s, %s',
                    $this->errors === 0 ? 'no errors' : "<fg=red>{$this->errors} error(s)</>",
                    $this->warnings === 0 ? 'no warnings' : "<fg=yellow>{$this->warnings} warning(s)</>"
                );
                $this->io()->writeln($summary);
            }
        }

        if ($this->errors > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{current_version: string|null, latest_version: string|null, update_available: bool, status: string}
     */
    private function checkKvsUpdate(bool $quietOk): array
    {
        $this->printSection('KVS Update');

        $result = [
            'current_version' => null,
            'latest_version' => null,
            'update_available' => false,
            'status' => 'ok',
        ];

        // Get current KVS version
        $versionFile = $this->config->getAdminPath() . '/include/version.php';
        if (!file_exists($versionFile)) {
            $this->printStatus('Version', 'Could not detect', 'warning');
            $result['status'] = 'warning';
            return $result;
        }

        $content = file_get_contents($versionFile);
        if ($content === false || preg_match('/\$config\[\'project_version\'\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches) !== 1) {
            $this->printStatus('Version', 'Could not parse', 'warning');
            $result['status'] = 'warning';
            return $result;
        }

        $currentVersion = $matches[1];
        $result['current_version'] = $currentVersion;

        // Try to get latest version from kvs_news plugin cache
        $latestVersion = $this->getLatestKvsVersion();
        $result['latest_version'] = $latestVersion;

        if ($latestVersion === null) {
            if ($quietOk === false) {
                $this->printStatus('Current Version', $currentVersion, 'ok');
                $this->printStatus('Latest Version', 'Unknown (run cron to update)', 'info');
            }
            return $result;
        }

        // Compare versions
        $currentInt = (int) str_replace('.', '', $currentVersion);
        $latestInt = (int) str_replace('.', '', $latestVersion);

        if ($latestInt > $currentInt) {
            $result['update_available'] = true;
            $this->printStatus('Current Version', $currentVersion, 'warning');
            $this->printStatus(
                'Update Available',
                "$latestVersion (admin/plugins.php?plugin_id=kvs_update)",
                'warning'
            );
            $this->warnings++;
            $result['status'] = 'update_available';
        } else {
            if (!$quietOk) {
                $this->printStatus('Current Version', $currentVersion, 'ok');
                $this->printStatus('Latest Version', $latestVersion . ' (up to date)', 'ok');
            }
        }

        return $result;
    }

    private function getLatestKvsVersion(): ?string
    {
        // Read from kvs_news plugin cache
        $dataFile = $this->config->getAdminPath() . '/data/plugins/kvs_news/data.dat';

        if (!file_exists($dataFile)) {
            return null;
        }

        $contents = file_get_contents($dataFile);
        if ($contents === false) {
            return null;
        }

        $data = @unserialize($contents, ['allowed_classes' => false]);

        if (!is_array($data) || !isset($data['latest_version']) || $data['latest_version'] === '' || !is_string($data['latest_version'])) {
            return null;
        }

        return $data['latest_version'];
    }

    /**
     * Check Docker environment.
     *
     * @return array{docker_available: bool, kvs_in_docker: bool, containers: array<string, bool>, status: string}
     */
    private function checkDockerEnvironment(bool $quietOk): array
    {
        $this->printSection('Environment');

        $summary = $this->docker()->getSummary();

        if (!$summary['docker_available']) {
            if (!$quietOk) {
                $this->printStatus('Mode', 'Standalone (no Docker)', 'info');
            }
            return array_merge($summary, ['status' => 'standalone']);
        }

        if (!$summary['kvs_in_docker']) {
            if (!$quietOk) {
                $this->printStatus('Mode', 'Standalone (Docker available but no KVS containers)', 'info');
            }
            return array_merge($summary, ['status' => 'standalone']);
        }

        // KVS is running in Docker - show running containers
        $running = $this->docker()->getRunningContainers();
        $containerList = implode(', ', array_values($running));

        if (!$quietOk) {
            $this->printStatus('Mode', 'Docker', 'ok');
            $this->printStatus('Containers', $containerList, 'ok');

            // Show cache type
            $cacheContainer = $this->docker()->getCacheContainer();
            if ($cacheContainer !== null) {
                $cacheType = str_contains($cacheContainer, 'dragonfly') ? 'Dragonfly' : 'Memcached';
                $this->printStatus('Cache Backend', $cacheType, 'ok');
            }
        }

        return array_merge($summary, ['status' => 'docker']);
    }

    /**
     * @return array{php_cli_version: string, php_web_version: string|null, kvs_version: string|null,
     *               compatible: bool|null, status: string, required_php_min?: string, required_php_max?: string}
     */
    private function checkPhpKvsCompatibility(bool $quietOk): array
    {
        $this->printSection('PHP & KVS Compatibility');

        $result = [
            'php_cli_version' => PHP_VERSION,
            'php_web_version' => null,
            'kvs_version' => null,
            'compatible' => null,
            'status' => 'unknown',
        ];

        // Get KVS version
        $versionFile = $this->config->getAdminPath() . '/include/version.php';
        $kvsVersion = null;
        if (file_exists($versionFile)) {
            $content = file_get_contents($versionFile);
            if ($content !== false && preg_match('/\$config\[\'project_version\'\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches) === 1) {
                $kvsVersion = $matches[1];
                $result['kvs_version'] = $kvsVersion;
            }
        }

        if ($kvsVersion === null) {
            $this->printStatus('KVS Version', 'Could not detect', 'warning');
            $this->warnings++;
            $result['status'] = 'warning';
            return $result;
        }

        // Get PHP version - uses Docker automatically if KVS runs there
        $phpVersionToCheck = $this->getKvsPhpVersion();
        $result['php_web_version'] = $phpVersionToCheck;

        // Determine version source for display
        $versionSource = $this->isDockerMode() ? 'Docker' : 'CLI';

        // Get major.minor version of KVS
        $kvsMajorMinor = implode('.', array_slice(explode('.', $kvsVersion), 0, 2));
        $phpRequirements = self::KVS_PHP_REQUIREMENTS[$kvsMajorMinor] ?? null;

        if ($phpRequirements === null) {
            $this->printStatus("KVS $kvsVersion", "Unknown PHP requirement", 'warning');
            $this->warnings++;
            $result['status'] = 'warning';
            return $result;
        }

        $minPhp = $phpRequirements['min'];
        $maxPhp = $phpRequirements['max'];
        $result['required_php_min'] = $minPhp;
        $result['required_php_max'] = $maxPhp;

        // Check if PHP version is within range
        $meetsMinimum = version_compare($phpVersionToCheck, $minPhp, '>=');
        $meetsMaximum = version_compare($phpVersionToCheck, $maxPhp, '<=');
        $isCompatible = $meetsMinimum && $meetsMaximum;
        $result['compatible'] = $isCompatible;

        // Get max major.minor for display
        $maxDisplay = implode('.', array_slice(explode('.', $maxPhp), 0, 2));

        if (!$meetsMinimum) {
            $this->printStatus(
                "PHP $phpVersionToCheck ($versionSource) with KVS $kvsVersion",
                "TOO OLD (requires PHP $minPhp+)",
                'error'
            );
            $this->errors++;
            $result['status'] = 'error';
        } elseif ($meetsMaximum === false) {
            $phpParts = explode('.', $phpVersionToCheck);
            $phpMajorMinor = $phpParts[0] . '.' . $phpParts[1];
            $this->printStatus(
                "PHP $phpVersionToCheck ($versionSource) with KVS $kvsVersion",
                "TOO NEW (PHP $maxDisplay max, PHP $phpMajorMinor not yet supported!)",
                'error'
            );
            $this->errors++;
            $result['status'] = 'error';
        } else {
            $result['status'] = 'ok';
            if (!$quietOk) {
                $this->printStatus(
                    "PHP $phpVersionToCheck ($versionSource) with KVS $kvsVersion",
                    "OK (requires PHP $minPhp)",
                    'ok'
                );
            }
        }

        // Warn if CLI and Docker/KVS PHP versions differ
        if ($this->isDockerMode() && version_compare(PHP_VERSION, $phpVersionToCheck, '!=')) {
            $this->printStatus(
                'PHP Versions',
                "CLI: " . PHP_VERSION . ", Docker: $phpVersionToCheck (different)",
                'info'
            );
        }

        return $result;
    }

    /**
     * @return array{extensions: array<string, array{name: string, loaded: bool}>, exec_enabled?: bool, status: string}
     */
    private function checkPhpExtensions(bool $quietOk): array
    {
        $this->printSection('PHP Extensions');

        $result = [
            'extensions' => [],
            'status' => 'ok',
        ];

        $hasError = false;

        // Uses Docker automatically if KVS runs there
        foreach (self::REQUIRED_PHP_EXTENSIONS as $ext => $name) {
            $loaded = $this->isExtensionLoaded($ext);
            $result['extensions'][$ext] = [
                'name' => $name,
                'loaded' => $loaded,
            ];

            if (!$loaded) {
                $this->printStatus($ext, "MISSING ($name)", 'error');
                $this->errors++;
                $hasError = true;
            } elseif (!$quietOk) {
                $this->printStatus($ext, $name, 'ok');
            }
        }

        // Check IonCube Loader
        $ioncubeLoaded = $this->isExtensionLoaded('ionCube Loader');
        $result['extensions']['ioncube'] = ['name' => 'IonCube Loader', 'loaded' => $ioncubeLoaded];

        if (!$ioncubeLoaded) {
            // Check if it's in loaded extensions with different name
            $loadedExtensions = $this->getLoadedExtensions();
            $hasIoncube = false;
            foreach ($loadedExtensions as $e) {
                if (stripos($e, 'ioncube') !== false) {
                    $hasIoncube = true;
                    $result['extensions']['ioncube']['loaded'] = true;
                    break;
                }
            }

            if (!$hasIoncube) {
                $this->printStatus('IonCube Loader', 'MISSING (required for KVS)', 'error');
                $this->errors++;
                $hasError = true;
            } elseif (!$quietOk) {
                $this->printStatus('IonCube Loader', 'OK', 'ok');
            }
        } elseif (!$quietOk) {
            $this->printStatus('IonCube Loader', 'OK', 'ok');
        }

        // Check that exec is not in disable_functions
        $disableFunctions = $this->getPhpSetting('disable_functions');
        $disableFunctionsStr = $disableFunctions !== false ? $disableFunctions : '';
        $execDisabled = stripos($disableFunctionsStr, 'exec') !== false;
        $result['exec_enabled'] = !$execDisabled;

        if ($execDisabled) {
            $this->printStatus('exec()', 'DISABLED in php.ini (required)', 'error');
            $this->errors++;
            $hasError = true;
        } elseif (!$quietOk) {
            $this->printStatus('exec()', 'Available', 'ok');
        }

        $result['status'] = $hasError ? 'error' : 'ok';
        return $result;
    }

    /**
     * @return array{tools: array<string, array{path: string, available: bool, version: string|null,
     *               codecs?: array<string, bool>, webp_support?: bool, avif_support?: bool}>, status: string}
     */
    private function checkTools(bool $quietOk): array
    {
        $this->printSection('System Tools');

        $result = [
            'tools' => [],
            'status' => 'ok',
        ];

        // Get paths from KVS config
        $setupFile = $this->config->getAdminPath() . '/include/setup.php';
        $paths = [
            'ffmpeg' => '/usr/bin/ffmpeg',
            'imagemagick' => '/usr/bin/convert',
            'mysqldump' => '/usr/bin/mysqldump',
        ];

        if (file_exists($setupFile)) {
            $content = @file_get_contents($setupFile);
            if ($content !== false && $content !== '') {
                if (preg_match('/\$config\[\'ffmpeg_path\'\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m) === 1) {
                    $paths['ffmpeg'] = $m[1];
                }
                if (preg_match('/\$config\[\'image_magick_path\'\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m) === 1) {
                    $paths['imagemagick'] = $m[1];
                }
                if (preg_match('/\$config\[\'mysqldump_path\'\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m) === 1) {
                    $paths['mysqldump'] = $m[1];
                }
            }
        }

        $hasError = false;

        // Check FFmpeg
        $ffmpegVersion = $this->getToolVersion($paths['ffmpeg'], '-version');
        $ffmpegCodecs = $this->checkFfmpegCodecs($paths['ffmpeg']);
        $result['tools']['ffmpeg'] = [
            'path' => $paths['ffmpeg'],
            'available' => $ffmpegVersion !== null,
            'version' => $ffmpegVersion,
            'codecs' => $ffmpegCodecs,
        ];

        if ($ffmpegVersion !== null) {
            if ($quietOk === false) {
                $this->printStatus('FFmpeg', "$ffmpegVersion ({$paths['ffmpeg']})", 'ok');
            }

            // Check required codecs (libx264 and AAC are required)
            $missingCodecs = [];
            if ($ffmpegCodecs['libx264'] === false) {
                $missingCodecs[] = 'libx264';
            }
            if ($ffmpegCodecs['aac'] === false) {
                $missingCodecs[] = 'AAC';
            }

            if (count($missingCodecs) > 0) {
                $this->printStatus('FFmpeg Codecs', 'MISSING: ' . implode(', ', $missingCodecs), 'error');
                $this->errors++;
                $hasError = true;
            } else {
                $availableCodecs = ['libx264', 'AAC'];
                if ($ffmpegCodecs['av1'] === true) {
                    $availableCodecs[] = 'AV1';
                }
                if ($quietOk === false) {
                    $this->printStatus('FFmpeg Codecs', implode(', ', $availableCodecs) . ' available', 'ok');
                }
            }

            // Optional AV1 support info
            if ($ffmpegCodecs['av1'] === false && $quietOk === false) {
                $this->printStatus('FFmpeg AV1', 'Not available (optional, for modern encoding)', 'info');
            }
        } else {
            $this->printStatus('FFmpeg', "Not found at {$paths['ffmpeg']}", 'error');
            $this->errors++;
            $hasError = true;
        }

        // Check ImageMagick
        $imVersion = $this->getToolVersion($paths['imagemagick'], '-version');
        $webpSupport = $this->checkImageMagickFormat($paths['imagemagick'], 'webp');
        $avifSupport = $this->checkImageMagickFormat($paths['imagemagick'], 'avif');
        $result['tools']['imagemagick'] = [
            'path' => $paths['imagemagick'],
            'available' => $imVersion !== null,
            'version' => $imVersion,
            'webp_support' => $webpSupport,
            'avif_support' => $avifSupport,
        ];

        if ($imVersion !== null) {
            if ($quietOk === false) {
                $this->printStatus('ImageMagick', "$imVersion ({$paths['imagemagick']})", 'ok');
            }

            if ($webpSupport === false) {
                $this->printStatus('ImageMagick WebP', 'Not supported (recommended)', 'warning');
                $this->warnings++;
            } elseif ($quietOk === false) {
                $this->printStatus('ImageMagick WebP', 'Supported', 'ok');
            }

            // AVIF is optional (like AV1 for video)
            if ($avifSupport === false) {
                $this->printStatus('ImageMagick AVIF', 'Not supported (optional)', 'info');
            } elseif ($quietOk === false) {
                $this->printStatus('ImageMagick AVIF', 'Supported', 'ok');
            }
        } else {
            $this->printStatus('ImageMagick', "Not found at {$paths['imagemagick']}", 'error');
            $this->errors++;
            $hasError = true;
        }

        // Check mysqldump (warning only, not critical)
        $mysqldumpVersion = $this->getToolVersion($paths['mysqldump'], '--version');
        $result['tools']['mysqldump'] = [
            'path' => $paths['mysqldump'],
            'available' => $mysqldumpVersion !== null,
            'version' => $mysqldumpVersion,
        ];

        if ($mysqldumpVersion !== null) {
            if ($quietOk === false) {
                $this->printStatus('mysqldump', "$mysqldumpVersion", 'ok');
            }
        } else {
            $this->printStatus('mysqldump', "Not found (backups may fail)", 'warning');
            $this->warnings++;
        }

        $result['status'] = $hasError ? 'error' : 'ok';
        return $result;
    }

    private function getToolVersion(string $path, string $versionFlag): ?string
    {
        if (!is_executable($path)) {
            // Try with which
            if (function_exists('shell_exec')) {
                $which = @shell_exec("which " . escapeshellarg(basename($path)) . " 2>/dev/null");
                if ($which !== null && $which !== false && $which !== '') {
                    $path = trim($which);
                }
            }
        }

        if (!function_exists('shell_exec')) {
            return null;
        }

        // Get multiple lines for better version parsing
        $output = @shell_exec(escapeshellarg($path) . " $versionFlag 2>&1 | head -n 5");
        if ($output === null || $output === false || $output === '') {
            return null;
        }

        // For ImageMagick, look for "Version: ImageMagick X.Y.Z"
        if (preg_match('/Version:\s*ImageMagick\s*(\d+\.\d+(?:\.\d+)?(?:-\d+)?)/i', $output, $matches) === 1) {
            return $matches[1];
        }

        // For FFmpeg, look for "ffmpeg version X.Y.Z"
        if (preg_match('/ffmpeg\s+version\s+(\d+\.\d+(?:\.\d+)?)/i', $output, $matches) === 1) {
            return $matches[1];
        }

        // For mysqldump, look for "mysqldump Ver X.Y.Z"
        if (preg_match('/mysqldump.*?(\d+\.\d+(?:\.\d+)?)/i', $output, $matches) === 1) {
            return $matches[1];
        }

        // Generic version pattern from first non-warning line
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip warning lines
            if (stripos($line, 'warning') !== false || stripos($line, 'deprecated') !== false) {
                continue;
            }
            if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @return array{libx264: bool, aac: bool, av1: bool, libavfilter: bool}
     */
    private function checkFfmpegCodecs(string $ffmpegPath): array
    {
        $result = [
            'libx264' => false,
            'aac' => false,
            'av1' => false,
            'libavfilter' => false,
        ];

        if (!function_exists('shell_exec')) {
            return $result;
        }

        // Get all encoders once
        $encoders = @shell_exec(escapeshellarg($ffmpegPath) . " -encoders 2>&1");

        if ($encoders === null || $encoders === false || $encoders === '') {
            return $result;
        }

        // Check for libx264 encoder
        $result['libx264'] = stripos($encoders, 'libx264') !== false;

        // Check for AAC encoder (libfdk_aac, libfaac, or native aac)
        $result['aac'] = preg_match('/aac/i', $encoders) === 1;

        // Check for AV1 encoder (libsvtav1, libaom-av1, librav1e)
        $result['av1'] = preg_match('/libsvtav1|libaom-av1|librav1e|av1/i', $encoders) === 1;

        // Check for libavfilter (typically included, check for filter support)
        $filters = @shell_exec(escapeshellarg($ffmpegPath) . " -filters 2>&1 | head -5");
        $result['libavfilter'] = is_string($filters) && $filters !== '' && stripos($filters, 'filter') !== false;

        return $result;
    }

    private function checkImageMagickFormat(string $convertPath, string $format): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }

        // Check if format is in the list of supported formats
        $output = @shell_exec(
            escapeshellarg($convertPath) . " -list format 2>&1 | grep -i " . escapeshellarg($format)
        );

        return $output !== null && $output !== false && $output !== '' && stripos($output, $format) !== false;
    }

    /**
     * @return array{configured: bool, connected: bool, memory_mb: int|null, status: string, server?: string}
     */
    private function checkMemcached(bool $quietOk): array
    {
        $this->printSection('Memcached');

        $result = [
            'configured' => false,
            'connected' => false,
            'memory_mb' => null,
            'status' => 'unknown',
        ];

        $serverValue = $this->config->get('memcache_server', '127.0.0.1');
        $server = is_string($serverValue) ? $serverValue : '127.0.0.1';
        $portValue = $this->config->get('memcache_port', 11211);
        $port = is_int($portValue) ? $portValue : 11211;

        if ($server === '') {
            if ($quietOk === false) {
                $this->printStatus('Memcached', 'Not configured', 'info');
            }
            $result['status'] = 'not_configured';
            return $result;
        }

        $result['configured'] = true;
        $result['server'] = "$server:$port";

        // Try to connect and get stats
        $memoryMb = $this->getMemcachedMemory($server, $port);

        if ($memoryMb === null) {
            $this->printStatus("Connection ($server:$port)", 'Failed to connect', 'error');
            $this->errors++;
            $result['status'] = 'error';
            return $result;
        }

        $result['connected'] = true;
        $result['memory_mb'] = $memoryMb;

        if ($memoryMb < self::MEMCACHE_MIN_MB) {
            $this->printStatus(
                "Memory",
                "{$memoryMb}MB (recommend ≥" . self::MEMCACHE_MIN_MB . "MB)",
                'warning'
            );
            $this->warnings++;
            $result['status'] = 'warning';
        } else {
            if (!$quietOk) {
                $this->printStatus("Memory", "{$memoryMb}MB", 'ok');
            }
            $result['status'] = 'ok';
        }

        if (!$quietOk || $result['status'] !== 'ok') {
            $this->printStatus("Connection", "OK ($server:$port)", 'ok');
        }

        return $result;
    }

    private function getMemcachedMemory(string $server, int $port): ?int
    {
        // In Docker mode, use centralized cache check
        if ($this->isDockerMode()) {
            $cacheInfo = $this->docker()->checkCache();
            if ($cacheInfo['available']) {
                return $cacheInfo['memory_mb'];
            }
            // Fallback: try via PHP container with custom server/port
            return $this->docker()->getCacheMemoryViaPhp($server, $port);
        }

        // Non-Docker: Use Memcached extension
        if (class_exists('Memcached')) {
            try {
                $m = new \Memcached();
                $m->addServer($server, $port);
                $stats = $m->getStats();
                $key = "$server:$port";
                if (isset($stats[$key]['limit_maxbytes'])) {
                    return (int) ($stats[$key]['limit_maxbytes'] / 1024 / 1024);
                }
            } catch (\Exception $e) {
                // Fall through to socket method
            }
        }

        // Non-Docker: Raw socket connection
        $fp = @fsockopen($server, $port, $errno, $errstr, 2);
        if ($fp === false) {
            return null;
        }

        fwrite($fp, "stats\r\n");
        $response = '';
        while (!feof($fp)) {
            $line = fgets($fp, 256);
            if ($line !== false) {
                $response .= $line;
                if (trim($line) === 'END') {
                    break;
                }
            }
        }
        fclose($fp);

        if (preg_match('/STAT limit_maxbytes (\d+)/', $response, $matches) === 1) {
            return (int) ($matches[1] / 1024 / 1024);
        }

        return null;
    }

    /**
     * @return array{enabled: bool, memory_consumption: int|null, interned_strings_buffer: int|null,
     *               jit_enabled: bool, status: string, jit_buffer_size?: int}
     */
    private function checkOpcache(bool $quietOk): array
    {
        $this->printSection('OPcache');

        $result = [
            'enabled' => false,
            'memory_consumption' => null,
            'interned_strings_buffer' => null,
            'jit_enabled' => false,
            'status' => 'unknown',
        ];

        // Uses Docker automatically if KVS runs there
        $config = $this->getOpcacheConfig();
        if ($config === false) {
            $this->printStatus('OPcache', 'Extension not loaded', 'warning');
            $this->warnings++;
            $result['status'] = 'warning';
            return $result;
        }

        /** @var array<string, mixed> $directives */
        $directives = is_array($config['directives'] ?? null) ? $config['directives'] : [];

        $enabled = (bool) ($directives['opcache.enable'] ?? false);
        $result['enabled'] = $enabled;

        if ($enabled === false) {
            $this->printStatus('OPcache', 'Disabled', 'warning');
            $this->warnings++;
            $result['status'] = 'warning';
            return $result;
        }

        $hasWarning = false;

        // Memory consumption (value is in bytes from opcache config)
        $memoryRaw = $directives['opcache.memory_consumption'] ?? 0;
        $memoryBytes = is_numeric($memoryRaw) ? (int) $memoryRaw : 0;
        $memoryMb = (int) ($memoryBytes / 1024 / 1024);
        $result['memory_consumption'] = $memoryMb;
        if ($memoryMb < self::OPCACHE_MIN_MB) {
            $this->printStatus(
                'memory_consumption',
                "{$memoryMb}MB (recommend ≥" . self::OPCACHE_MIN_MB . "MB)",
                'warning'
            );
            $this->warnings++;
            $hasWarning = true;
        } elseif (!$quietOk) {
            $this->printStatus('memory_consumption', "{$memoryMb}MB", 'ok');
        }

        // Interned strings buffer
        $stringsRaw = $directives['opcache.interned_strings_buffer'] ?? 0;
        $stringsMb = is_numeric($stringsRaw) ? (int) $stringsRaw : 0;
        $result['interned_strings_buffer'] = $stringsMb;
        if ($stringsMb < self::OPCACHE_STRINGS_MIN_MB) {
            $this->printStatus(
                'interned_strings_buffer',
                "{$stringsMb}MB (recommend ≥" . self::OPCACHE_STRINGS_MIN_MB . "MB)",
                'warning'
            );
            $this->warnings++;
            $hasWarning = true;
        } elseif ($quietOk === false) {
            $this->printStatus('interned_strings_buffer', "{$stringsMb}MB", 'ok');
        }

        // JIT (PHP 8+)
        // @phpstan-ignore greaterOrEqual.alwaysTrue (forward-compatible check)
        if (PHP_MAJOR_VERSION >= 8) {
            $jitRaw = $directives['opcache.jit_buffer_size'] ?? 0;
            $jitBuffer = is_numeric($jitRaw) ? (int) $jitRaw : 0;
            $result['jit_buffer_size'] = $jitBuffer;
            $result['jit_enabled'] = $jitBuffer > 0;

            if ($jitBuffer === 0) {
                $this->printStatus('JIT', 'Disabled (optional but recommended for PHP 8+)', 'info');
            } elseif ($quietOk === false) {
                $this->printStatus('JIT', format_bytes($jitBuffer), 'ok');
            }
        }

        $result['status'] = $hasWarning ? 'warning' : 'ok';
        return $result;
    }

    /**
     * @return array{settings: array<string, string|false>, status: string}
     */
    private function checkPhpSettings(bool $quietOk): array
    {
        $this->printSection('PHP Settings');

        $result = [
            'settings' => [],
            'status' => 'ok',
        ];

        $checks = [
            'upload_max_filesize' => [
                'min' => self::UPLOAD_MIN_MB * 1024 * 1024,
                'format' => 'bytes',
                'recommend' => '≥' . self::UPLOAD_MIN_MB . 'M for video uploads',
            ],
            'post_max_size' => [
                'min' => self::UPLOAD_MIN_MB * 1024 * 1024,
                'format' => 'bytes',
                'recommend' => '≥upload_max_filesize',
            ],
            'memory_limit' => [
                'min' => self::MEMORY_LIMIT_MIN_MB * 1024 * 1024,
                'format' => 'bytes',
                'recommend' => '≥' . self::MEMORY_LIMIT_MIN_MB . 'M',
                'allow_unlimited' => true,
            ],
            'max_input_vars' => [
                'min' => 10000,
                'format' => 'number',
                'recommend' => '≥10000 for admin panel',
            ],
            'max_execution_time' => [
                'min' => 300,
                'format' => 'seconds',
                'recommend' => '≥300 for conversions',
                'allow_unlimited' => true,
            ],
        ];

        // Uses Docker automatically if KVS runs there
        foreach ($checks as $name => $check) {
            $value = $this->getPhpSetting($name);
            $bytes = $this->parseIniSize($value);
            $valueStr = $value !== false ? $value : '';
            $result['settings'][$name] = $valueStr;

            // Handle unlimited values (-1 or 0 for certain settings)
            $isUnlimited = ($bytes === -1) || ($check['allow_unlimited'] ?? false) && $bytes === 0;

            if ($isUnlimited) {
                if (!$quietOk) {
                    $display = $check['format'] === 'bytes' ? 'Unlimited' : $valueStr;
                    $this->printStatus($name, $display, 'ok');
                }
                continue;
            }

            $displayValue = $check['format'] === 'bytes' ? format_bytes($bytes) : $valueStr;

            if ($bytes < $check['min']) {
                $this->printStatus(
                    $name,
                    "$displayValue ({$check['recommend']})",
                    'warning'
                );
                $this->warnings++;
                $result['status'] = 'warning';
            } elseif (!$quietOk) {
                $this->printStatus($name, $displayValue, 'ok');
            }
        }

        return $result;
    }

    /**
     * @return array{processes: array<string, array{label: string, found: bool, last_run: int|null,
     *               status: string, status_id?: int, age_minutes?: int}>, status: string, error?: string}
     */
    private function checkCron(bool $quietOk): array
    {
        $this->printSection('Cron Status');

        $result = [
            'processes' => [],
            'status' => 'ok',
        ];

        $db = $this->getDatabaseConnection($this->silent);
        if ($db === null) {
            $this->printStatus('Cron', 'Database not available', 'warning');
            $result['status'] = 'warning';
            return $result;
        }

        $hasWarning = false;
        $hasError = false;

        // Check main cron processes from admin_processes table
        $cronProcesses = [
            'cron' => ['label' => 'Main Cron', 'critical' => true, 'max_age_minutes' => 5],
            'cron_optimize' => ['label' => 'Optimize', 'critical' => false, 'max_age_minutes' => 1440], // 24h
            'cron_conversion' => ['label' => 'Conversion', 'critical' => true, 'max_age_minutes' => 60],
            'cron_check_db' => ['label' => 'DB Check', 'critical' => false, 'max_age_minutes' => 1440],
        ];

        try {
            $stmt = $db->query("
                SELECT process_name, process_data, status_id
                FROM ktvs_admin_processes
                WHERE process_name IN ('cron', 'cron_optimize', 'cron_conversion', 'cron_check_db')
            ");
            if ($stmt === false) {
                throw new \RuntimeException('Failed to query admin_processes table');
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $foundProcesses = [];
            foreach ($rows as $row) {
                $foundProcesses[$row['process_name']] = $row;
            }

            foreach ($cronProcesses as $name => $config) {
                $result['processes'][$name] = [
                    'label' => $config['label'],
                    'found' => isset($foundProcesses[$name]),
                    'last_run' => null,
                    'status' => 'unknown',
                ];

                if (!isset($foundProcesses[$name])) {
                    // Process never ran
                    if ($config['critical']) {
                        $this->printStatus($config['label'], 'Never executed', 'error');
                        $this->errors++;
                        $hasError = true;
                        $result['processes'][$name]['status'] = 'error';
                    } else {
                        if (!$quietOk) {
                            $this->printStatus($config['label'], 'Not configured', 'info');
                        }
                        $result['processes'][$name]['status'] = 'not_configured';
                    }
                    continue;
                }

                $processData = $foundProcesses[$name]['process_data'];
                $statusId = (int) $foundProcesses[$name]['status_id'];

                // Parse last run time from process_data (usually JSON or serialized)
                $lastRun = null;
                if ($processData) {
                    // Try JSON first
                    $data = @json_decode($processData, true);
                    if (is_array($data) && isset($data['last_run']) && is_int($data['last_run'])) {
                        $lastRun = $data['last_run'];
                    } elseif (is_numeric($processData)) {
                        $lastRun = (int) $processData;
                    }
                }

                $result['processes'][$name]['last_run'] = $lastRun;
                $result['processes'][$name]['status_id'] = $statusId;

                // Check if cron is stuck (status_id = 1 means running)
                if ($statusId === 1 && $config['critical']) {
                    $this->printStatus($config['label'], 'Currently running (or stuck)', 'warning');
                    $this->warnings++;
                    $hasWarning = true;
                    $result['processes'][$name]['status'] = 'running';
                    continue;
                }

                // Check last run age
                if ($lastRun !== null) {
                    $age = time() - $lastRun;
                    $ageMinutes = (int) ($age / 60);
                    $result['processes'][$name]['age_minutes'] = $ageMinutes;

                    if ($ageMinutes > $config['max_age_minutes'] && $config['critical']) {
                        $ageDisplay = $this->formatAge($age);
                        $this->printStatus($config['label'], "Last run: $ageDisplay ago (STALE)", 'error');
                        $this->errors++;
                        $hasError = true;
                        $result['processes'][$name]['status'] = 'stale';
                    } elseif (!$quietOk) {
                        $ageDisplay = $this->formatAge($age);
                        $this->printStatus($config['label'], "Last run: $ageDisplay ago", 'ok');
                        $result['processes'][$name]['status'] = 'ok';
                    } else {
                        $result['processes'][$name]['status'] = 'ok';
                    }
                } elseif (!$quietOk) {
                    $this->printStatus($config['label'], 'Active', 'ok');
                    $result['processes'][$name]['status'] = 'ok';
                }
            }
        } catch (\Exception $e) {
            $this->printStatus('Cron', 'Could not query process table', 'warning');
            $this->warnings++;
            $result['status'] = 'warning';
            $result['error'] = $e->getMessage();
            return $result;
        }

        $result['status'] = $hasError ? 'error' : ($hasWarning ? 'warning' : 'ok');
        return $result;
    }

    private function formatAge(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return (int) ($seconds / 60) . 'm';
        }
        if ($seconds < 86400) {
            $hours = (int) ($seconds / 3600);
            $mins = (int) (($seconds % 3600) / 60);
            return $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
        }
        $days = (int) ($seconds / 86400);
        return "{$days}d";
    }

    /**
     * @return array{connected: bool, version: string|null, variables: array<string, int>, status: string,
     *               is_mariadb?: bool, version_number?: string, meets_requirement?: bool}
     */
    private function checkMysql(bool $quietOk): array
    {
        $this->printSection('MySQL/MariaDB');

        $result = [
            'connected' => false,
            'version' => null,
            'variables' => [],
            'status' => 'unknown',
        ];

        $db = $this->getDatabaseConnection($this->silent);
        if ($db === null) {
            $this->printStatus('Connection', 'Failed', 'error');
            $this->errors++;
            $result['status'] = 'error';
            return $result;
        }

        $result['connected'] = true;

        // Check important variables
        $hasWarning = false;

        // Version check with requirements validation
        try {
            $stmt = $db->query("SELECT VERSION() as version");
            if ($stmt === false) {
                throw new \RuntimeException('Failed to query database version');
            }
            $row = $stmt->fetch();
            $versionString = '';
            if (is_array($row) && isset($row['version']) && (is_string($row['version']) || is_numeric($row['version']))) {
                $versionString = (string) $row['version'];
            }
            $result['version'] = $versionString;

            // Parse version - detect if MariaDB or MySQL
            $isMariaDB = stripos($versionString, 'mariadb') !== false;
            $result['is_mariadb'] = $isMariaDB;

            // Extract version number
            if (preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $versionString, $matches) === 1) {
                $versionNum = $matches[1];
                $result['version_number'] = $versionNum;

                if ($isMariaDB) {
                    $minVersion = self::MARIADB_MIN_VERSION;
                    $meetsRequirement = version_compare($versionNum, $minVersion, '>=');
                    $dbType = 'MariaDB';
                } else {
                    $minVersion = self::MYSQL_MIN_VERSION;
                    $meetsRequirement = version_compare($versionNum, $minVersion, '>=');
                    $dbType = 'MySQL';
                }

                $result['meets_requirement'] = $meetsRequirement;

                if (!$meetsRequirement) {
                    $this->printStatus(
                        "$dbType Version",
                        "$versionNum (requires $dbType $minVersion+)",
                        'error'
                    );
                    $this->errors++;
                    $hasWarning = true;
                } elseif (!$quietOk) {
                    $this->printStatus("$dbType Version", $versionString, 'ok');
                }
            } else {
                if (!$quietOk) {
                    $this->printStatus('Version', $versionString, 'ok');
                }
            }
        } catch (\Exception $e) {
            $this->printStatus('Version', 'Could not detect', 'warning');
        }

        try {
            $stmt = $db->query("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
            if ($stmt === false) {
                throw new \RuntimeException('Failed to query innodb_buffer_pool_size');
            }
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['Value']) && (is_int($row['Value']) || is_numeric($row['Value']))) {
                $bytes = (int) $row['Value'];
                $mb = $bytes / 1024 / 1024;
                $result['variables']['innodb_buffer_pool_size'] = $bytes;

                if ($mb < self::INNODB_BUFFER_MIN_MB) {
                    $this->printStatus(
                        'innodb_buffer_pool_size',
                        format_bytes($bytes) . ' (recommend ≥' . self::INNODB_BUFFER_MIN_MB . 'MB)',
                        'warning'
                    );
                    $this->warnings++;
                    $hasWarning = true;
                } elseif (!$quietOk) {
                    $this->printStatus('innodb_buffer_pool_size', format_bytes($bytes), 'ok');
                }
            }
        } catch (\Exception $e) {
            // Skip
        }

        try {
            $stmt = $db->query("SHOW VARIABLES LIKE 'max_connections'");
            if ($stmt === false) {
                throw new \RuntimeException('Failed to query max_connections');
            }
            $row = $stmt->fetch();
            if (is_array($row) && isset($row['Value']) && $quietOk === false && (is_int($row['Value']) || is_numeric($row['Value']))) {
                $result['variables']['max_connections'] = (int) $row['Value'];
                $this->printStatus('max_connections', (string) $row['Value'], 'ok');
            }
        } catch (\Exception $e) {
            // Skip
        }

        $result['status'] = $hasWarning ? 'warning' : 'ok';
        return $result;
    }

    /**
     * @return array{load_average: array<int, float>|null, cpu_cores: int|null, load_per_core: float|null, io_wait: float|null, status: string}
     */
    private function checkSystemLoad(bool $quietOk): array
    {
        $this->printSection('System Load');

        $result = [
            'load_average' => null,
            'cpu_cores' => null,
            'load_per_core' => null,
            'io_wait' => null,
            'status' => 'unknown',
        ];

        // Get load average
        $load = $this->getLoadAverage();
        if ($load === null) {
            $this->printStatus('Load Average', 'N/A (not available)', 'info');
            $result['status'] = 'not_available';
            return $result;
        }

        $result['load_average'] = $load;

        // Get CPU cores
        $cores = $this->getCpuCores();
        $result['cpu_cores'] = $cores;

        $hasWarning = false;

        if ($cores !== null && $cores > 0) {
            $loadPerCore = $load[0] / $cores;
            $result['load_per_core'] = round($loadPerCore, 2);

            $loadDisplay = sprintf('%.2f, %.2f, %.2f (1/5/15 min)', $load[0], $load[1], $load[2]);

            if ($loadPerCore > self::LOAD_CRITICAL_THRESHOLD) {
                $this->printStatus('Load Average', $loadDisplay, 'error');
                $this->printStatus(
                    'Load per Core',
                    sprintf('%.2f (%d cores) - OVERLOADED', $loadPerCore, $cores),
                    'error'
                );
                $this->errors++;
                $hasWarning = true;
            } elseif ($loadPerCore > self::LOAD_WARNING_THRESHOLD) {
                $this->printStatus('Load Average', $loadDisplay, 'warning');
                $this->printStatus(
                    'Load per Core',
                    sprintf('%.2f (%d cores) - HIGH', $loadPerCore, $cores),
                    'warning'
                );
                $this->warnings++;
                $hasWarning = true;
            } elseif (!$quietOk) {
                $this->printStatus('Load Average', $loadDisplay, 'ok');
                $this->printStatus('Load per Core', sprintf('%.2f (%d cores)', $loadPerCore, $cores), 'ok');
            }
        } else {
            $loadDisplay = sprintf('%.2f, %.2f, %.2f', $load[0], $load[1], $load[2]);
            if (!$quietOk) {
                $this->printStatus('Load Average', $loadDisplay, 'ok');
                $this->printStatus('CPU Cores', 'Could not detect', 'info');
            }
        }

        // IO Wait
        $ioWait = $this->getIoWait();
        $result['io_wait'] = $ioWait;

        if ($ioWait !== null) {
            if ($ioWait > self::IOWAIT_CRITICAL_PERCENT) {
                $this->printStatus('IO Wait', "{$ioWait}% - DISK BOTTLENECK", 'error');
                $this->errors++;
                $hasWarning = true;
            } elseif ($ioWait > self::IOWAIT_WARNING_PERCENT) {
                $this->printStatus('IO Wait', "{$ioWait}% - elevated", 'warning');
                $this->warnings++;
                $hasWarning = true;
            } elseif (!$quietOk) {
                $this->printStatus('IO Wait', "{$ioWait}%", 'ok');
            }
        } else {
            if (!$quietOk) {
                $this->printStatus('IO Wait', 'N/A (restricted)', 'info');
            }
        }

        $result['status'] = $hasWarning ? ($this->errors > 0 ? 'error' : 'warning') : 'ok';
        return $result;
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function getLoadAverage(): ?array
    {
        // Method 1: Built-in PHP
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            if ($load !== false) {
                return $load;
            }
        }

        // Method 2: /proc/loadavg
        if (is_readable('/proc/loadavg')) {
            $data = @file_get_contents('/proc/loadavg');
            if ($data !== false && $data !== '') {
                $parts = explode(' ', $data);
                if (count($parts) >= 3) {
                    return [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
                }
            }
        }

        // Method 3: uptime command
        if (function_exists('shell_exec')) {
            $uptime = @shell_exec('uptime 2>/dev/null');
            $pattern = '/load average[s]?:\s*([\d.]+),?\s*([\d.]+),?\s*([\d.]+)/i';
            if ($uptime !== null && $uptime !== false && $uptime !== '' && preg_match($pattern, $uptime, $m) === 1) {
                return [(float) $m[1], (float) $m[2], (float) $m[3]];
            }
        }

        return null;
    }

    private function getCpuCores(): ?int
    {
        // Method 1: /proc/cpuinfo
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false && $cpuinfo !== '') {
                $count = substr_count($cpuinfo, 'processor');
                if ($count > 0) {
                    return $count;
                }
            }
        }

        // Method 2: nproc command
        if (function_exists('shell_exec')) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if ($nproc !== null && $nproc !== false && $nproc !== '') {
                return (int) trim($nproc);
            }
        }

        // Method 3: sysctl (macOS/BSD)
        if (function_exists('shell_exec')) {
            $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($sysctl !== null && $sysctl !== false && $sysctl !== '') {
                return (int) trim($sysctl);
            }
        }

        return null;
    }

    private function getIoWait(): ?float
    {
        if (!is_readable('/proc/stat')) {
            return null;
        }

        // Read first sample
        $stat1 = @file_get_contents('/proc/stat');
        if ($stat1 === false || $stat1 === '') {
            return null;
        }

        usleep(100000); // 100ms

        // Read second sample
        $stat2 = @file_get_contents('/proc/stat');
        if ($stat2 === false || $stat2 === '') {
            return null;
        }

        // Parse cpu line: cpu user nice system idle iowait irq softirq steal guest guest_nice
        if (preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat1, $m1) === false) {
            return null;
        }
        if (preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat2, $m2) === false) {
            return null;
        }

        $total1 = (int) $m1[1] + (int) $m1[2] + (int) $m1[3] + (int) $m1[4] + (int) $m1[5] + (int) $m1[6] + (int) $m1[7];
        $total2 = (int) $m2[1] + (int) $m2[2] + (int) $m2[3] + (int) $m2[4] + (int) $m2[5] + (int) $m2[6] + (int) $m2[7];
        $iowait1 = (int) $m1[5];
        $iowait2 = (int) $m2[5];

        $totalDelta = $total2 - $total1;
        $iowaitDelta = $iowait2 - $iowait1;

        if ($totalDelta === 0) {
            return 0.0;
        }

        return round(($iowaitDelta / $totalDelta) * 100, 1);
    }

    /**
     * @return array{paths: array<string, array{path: string, free: float, total: float, used_percent: float}>, status: string}
     */
    private function checkDiskSpace(bool $quietOk): array
    {
        $this->printSection('Disk Space');

        $result = [
            'paths' => [],
            'status' => 'ok',
        ];

        $pathsToCheck = [
            'KVS Root' => $this->config->getKvsPath(),
            'Content' => $this->config->getContentPath(),
        ];

        $hasWarning = false;

        foreach ($pathsToCheck as $label => $path) {
            if ($path === '' || !is_dir($path)) {
                continue;
            }

            $free = @disk_free_space($path);
            $total = @disk_total_space($path);

            if ($free === false || $total === false) {
                continue;
            }

            $usedPercent = round((($total - $free) / $total) * 100, 1);
            $result['paths'][$label] = [
                'path' => $path,
                'free' => $free,
                'total' => $total,
                'used_percent' => $usedPercent,
            ];

            $display = sprintf('%s%% used (%s free)', $usedPercent, format_bytes((int) $free));

            if ($usedPercent > self::DISK_CRITICAL_PERCENT) {
                $this->printStatus($label, "$display - CRITICAL", 'error');
                $this->errors++;
                $hasWarning = true;
            } elseif ($usedPercent > self::DISK_WARNING_PERCENT) {
                $this->printStatus($label, "$display - getting full", 'warning');
                $this->warnings++;
                $hasWarning = true;
            } elseif (!$quietOk) {
                $this->printStatus($label, $display, 'ok');
            }
        }

        $result['status'] = $hasWarning ? ($this->errors > 0 ? 'error' : 'warning') : 'ok';
        return $result;
    }

    /**
     * @return array{online: bool, latency_ms: float|null, status: string, http_code?: int, error?: string}
     */
    private function checkInternet(bool $quietOk): array
    {
        $this->printSection('Internet Connectivity');

        $result = [
            'online' => false,
            'latency_ms' => null,
            'status' => 'unknown',
        ];

        if (!extension_loaded('curl')) {
            $this->printStatus('Status', 'SKIP (curl not available)', 'comment');
            $result['status'] = 'skip';
            return $result;
        }

        $url = 'https://cloudflare.com/cdn-cgi/trace';
        $start = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::INTERNET_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::INTERNET_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $latency = round((microtime(true) - $start) * 1000);
        $error = curl_error($ch);
        curl_close($ch);

        $result['http_code'] = $httpCode;
        $result['latency_ms'] = $latency;
        $result['online'] = ($httpCode >= 200 && $httpCode < 400);

        if ($result['online']) {
            if (!$quietOk) {
                $this->printStatus('Cloudflare', "OK ({$latency}ms)", 'ok');
            }
            $result['status'] = 'ok';
        } else {
            $errorMsg = $error !== '' ? $error : "HTTP $httpCode";
            $this->printStatus('Cloudflare', "FAILED ($errorMsg)", 'error');
            $this->errors++;
            $result['status'] = 'error';
            $result['error'] = $errorMsg;
        }

        return $result;
    }

    private function printSection(string $title): void
    {
        if (!$this->silent) {
            $this->io()->section($title);
        }
    }

    private function printStatus(string $label, string $value, string $status): void
    {
        if ($this->silent) {
            return;
        }

        $icon = match ($status) {
            'ok' => '<fg=green>✓</>',
            'warning' => '<fg=yellow>⚠</>',
            'error' => '<fg=red>✗</>',
            'info' => '<fg=blue>ℹ</>',
            default => ' ',
        };

        $valueFormatted = match ($status) {
            'ok' => "<fg=green>$value</>",
            'warning' => "<fg=yellow>$value</>",
            'error' => "<fg=red>$value</>",
            default => $value,
        };

        $this->io()->writeln("  $icon $label: $valueFormatted");
    }

    private function parseIniSize(string|false $size): int
    {
        if ($size === false) {
            return 0;
        }

        $size = trim($size);

        if ($size === '-1') {
            return -1;
        }

        $unit = strtolower(substr($size, -1));
        $value = (int) $size;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Check End of Life status for PHP, MySQL/MariaDB.
     *
     * @param array<string, mixed> $results Previous check results
     * @return array{php: array<string, mixed>|null, mysql: array<string, mixed>|null, status: string}
     */
    private function checkEndOfLife(bool $quietOk, array $results): array
    {
        $this->printSection('End of Life Status');

        $result = [
            'php' => null,
            'mysql' => null,
            'status' => 'ok',
        ];

        $now = new \DateTime();
        $warnDate = (new \DateTime())->modify('+' . Constants::EOL_WARNING_MONTHS . ' months');
        $hasWarning = false;

        // Check PHP EOL
        $phpVersion = PHP_VERSION;
        $phpMajorMinor = implode('.', array_slice(explode('.', $phpVersion), 0, 2));
        $phpEolData = $this->fetchEolData('php');

        $result['php'] = $this->checkVersionEol(
            'PHP',
            $phpMajorMinor,
            $phpEolData,
            $now,
            $warnDate,
            $quietOk,
            $hasWarning,
            'Sury/Remi repos may extend support'
        );

        // Check MySQL/MariaDB EOL
        $mysqlResult = $results['mysql'] ?? [];
        if (is_array($mysqlResult) && isset($mysqlResult['version_number']) && is_string($mysqlResult['version_number'])) {
            $isMariaDB = (bool) ($mysqlResult['is_mariadb'] ?? false);
            $dbVersion = $mysqlResult['version_number'];
            $dbMajorMinor = implode('.', array_slice(explode('.', $dbVersion), 0, 2));
            $product = $isMariaDB ? 'mariadb' : 'mysql';
            $productName = $isMariaDB ? 'MariaDB' : 'MySQL';

            $dbEolData = $this->fetchEolData($product);
            $result['mysql'] = $this->checkVersionEol(
                $productName,
                $dbMajorMinor,
                $dbEolData,
                $now,
                $warnDate,
                $quietOk,
                $hasWarning
            );
        }

        $result['status'] = $hasWarning ? 'warning' : 'ok';
        return $result;
    }

    /**
     * Fetch EOL data from endoflife.date API with caching.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchEolData(string $product): array
    {
        $cacheDir = '/tmp/kvs-cli-eol-cache';
        $cacheFile = "$cacheDir/$product.json";

        // Check cache
        if (file_exists($cacheFile)) {
            $mtime = filemtime($cacheFile);
            if ($mtime !== false && (time() - $mtime) < Constants::EOL_CACHE_TTL) {
                $cached = @file_get_contents($cacheFile);
                if ($cached !== false && $cached !== '') {
                    $data = json_decode($cached, true);
                    if (is_array($data)) {
                        // Ensure it's a list of arrays
                        /** @var list<array<string, mixed>> $result */
                        $result = array_values($data);
                        return $result;
                    }
                }
            }
        }

        // Fetch from API (requires curl)
        if (!extension_loaded('curl')) {
            return [];
        }

        $url = Constants::EOL_API_BASE . '/' . $product . '.json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'kvs-cli/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $httpCode !== 200) {
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [];
        }

        // Ensure it's a list of arrays
        /** @var list<array<string, mixed>> $result */
        $result = array_values($data);

        // Cache the result
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, $response);

        return $result;
    }

    /**
     * Check if a specific version is EOL or EOL soon.
     *
     * @param list<array<string, mixed>> $eolData
     * @return array{version: string, eol_date: string|null, status: string}
     */
    private function checkVersionEol(
        string $productName,
        string $version,
        array $eolData,
        \DateTime $now,
        \DateTime $warnDate,
        bool $quietOk,
        bool &$hasWarning,
        string $note = ''
    ): array {
        $result = [
            'version' => $version,
            'eol_date' => null,
            'status' => 'unknown',
        ];

        // Find matching cycle in EOL data
        $eolDateStr = null;
        foreach ($eolData as $entry) {
            if (!isset($entry['cycle']) || (!is_string($entry['cycle']) && !is_numeric($entry['cycle']))) {
                continue;
            }
            $cycle = (string) $entry['cycle'];
            if ($cycle === $version) {
                $eol = $entry['eol'] ?? null;
                if (is_string($eol)) {
                    $eolDateStr = $eol;
                } elseif ($eol === true) {
                    $eolDateStr = 'true';
                }
                break;
            }
        }

        if ($eolDateStr === null && count($eolData) === 0) {
            if (!$quietOk) {
                $this->printStatus("$productName $version", 'EOL data unavailable', 'info');
            }
            $result['status'] = 'unknown';
            return $result;
        }

        if ($eolDateStr === null) {
            if (!$quietOk) {
                $this->printStatus("$productName $version", 'Supported (no EOL date)', 'ok');
            }
            $result['status'] = 'supported';
            return $result;
        }

        $result['eol_date'] = $eolDateStr;

        if ($eolDateStr === 'true') {
            $noteText = $note !== '' ? " ($note)" : '';
            $this->printStatus("$productName $version", "END OF LIFE$noteText", 'warning');
            $this->warnings++;
            $hasWarning = true;
            $result['status'] = 'eol';
            return $result;
        }

        try {
            $eolDate = new \DateTime($eolDateStr);
        } catch (\Exception $e) {
            if (!$quietOk) {
                $this->printStatus("$productName $version", "Invalid EOL date: $eolDateStr", 'info');
            }
            $result['status'] = 'unknown';
            return $result;
        }

        if ($now > $eolDate) {
            $noteText = $note !== '' ? " ($note)" : '';
            $this->printStatus(
                "$productName $version",
                "END OF LIFE since " . $eolDate->format('Y-m-d') . "$noteText",
                'warning'
            );
            $this->warnings++;
            $hasWarning = true;
            $result['status'] = 'eol';
        } elseif ($warnDate > $eolDate) {
            $noteText = $note !== '' ? " ($note)" : '';
            $this->printStatus(
                "$productName $version",
                "EOL on " . $eolDate->format('Y-m-d') . " (within " . Constants::EOL_WARNING_MONTHS . " months)$noteText",
                'warning'
            );
            $this->warnings++;
            $hasWarning = true;
            $result['status'] = 'eol_soon';
        } else {
            if (!$quietOk) {
                $this->printStatus(
                    "$productName $version",
                    "Supported until " . $eolDate->format('Y-m-d'),
                    'ok'
                );
            }
            $result['status'] = 'supported';
        }

        return $result;
    }
}
