<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

use KVS\CLI\Constants;

/**
 * Stack Score calculator based on software versions and EOL status
 *
 * Checks PHP, MariaDB/MySQL, and OS versions against endoflife.date API
 * to calculate a "freshness" score for the software stack.
 */
class StackScorer
{
    private const SCORE_LATEST = 100;      // Latest active version
    private const SCORE_ACTIVE = 100;      // Active support (for compatibility)
    private const SCORE_OUTDATED = 80;     // Active but not latest
    private const SCORE_SECURITY = 70;     // Security fixes only
    private const SCORE_EOL_SOON = 40;     // EOL within 6 months
    private const SCORE_EOL = 0;           // End of life

    /** @var array<string, list<array<string, mixed>>> */
    private array $eolCache = [];

    private ?\PDO $db = null;

    /** @var array<string, mixed> */
    private array $systemInfo = [];

    /**
     * @param array<string, mixed> $systemInfo
     */
    public function __construct(?\PDO $db = null, array $systemInfo = [])
    {
        $this->db = $db;
        $this->systemInfo = $systemInfo;
    }

    /**
     * Calculate stack score for the current system
     *
     * @return array{
     *     total: int,
     *     php: array{version: string, status: string, score: int, eol_date: ?string, recommendation: ?string},
     *     php_config: array{opcache: bool, memory_limit: string, max_execution_time: string, score: int, issues: list<string>},
     *     ffmpeg: array{installed: bool, version: string, score: int, issues: list<string>},
     *     database: array{version: string, type: string, status: string, score: int, eol_date: ?string, recommendation: ?string},
     *     os: array{version: string, name: string, status: string, score: int, eol_date: ?string, recommendation: ?string},
     *     web_server: array{name: string, type: string, score: int, recommendation: ?string},
     *     rating: string,
     *     recommendations: list<string>
     * }
     */
    public function calculate(): array
    {
        $phpScore = $this->scorePhp();
        $phpConfigScore = $this->scorePhpConfig();
        $ffmpegScore = $this->scoreFfmpeg();
        $dbScore = $this->scoreDatabase();
        $osScore = $this->scoreOs();
        $webServerScore = $this->scoreWebServer();

        // Weighted average: PHP 25%, PHP Config 15%, FFmpeg 17.5%, DB 22.5%, OS 10%, WebServer 10%
        $totalScore = (int) (
            ($phpScore['score'] * 0.25) +
            ($phpConfigScore['score'] * 0.15) +
            ($ffmpegScore['score'] * 0.175) +
            ($dbScore['score'] * 0.225) +
            ($osScore['score'] * 0.10) +
            ($webServerScore['score'] * 0.10)
        );

        // Collect recommendations
        $recommendations = [];
        if ($phpScore['recommendation'] !== null) {
            $recommendations[] = $phpScore['recommendation'];
        }
        foreach ($phpConfigScore['issues'] as $issue) {
            $recommendations[] = $issue;
        }
        foreach ($ffmpegScore['issues'] as $issue) {
            $recommendations[] = $issue;
        }
        if ($dbScore['recommendation'] !== null) {
            $recommendations[] = $dbScore['recommendation'];
        }
        if ($osScore['recommendation'] !== null) {
            $recommendations[] = $osScore['recommendation'];
        }
        if ($webServerScore['recommendation'] !== null) {
            $recommendations[] = $webServerScore['recommendation'];
        }

        return [
            'total' => $totalScore,
            'php' => $phpScore,
            'php_config' => $phpConfigScore,
            'ffmpeg' => $ffmpegScore,
            'database' => $dbScore,
            'os' => $osScore,
            'web_server' => $webServerScore,
            'rating' => $this->getRating($totalScore),
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Score PHP version
     *
     * KVS 6.x only supports PHP 8.1, so we consider 8.1 as "optimal for KVS"
     * even though it may be EOL from PHP's perspective.
     *
     * @return array{version: string, status: string, score: int, eol_date: ?string, recommendation: ?string}
     */
    private function scorePhp(): array
    {
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $eolData = $this->fetchEolData('php');

        // KVS 6.x only supports PHP 8.1 - this is the best users can do
        // Don't penalize users for using the only supported version
        if ($version === '8.1') {
            return [
                'version' => $version,
                'status' => 'kvs_optimal',
                'score' => self::SCORE_ACTIVE,
                'eol_date' => $this->getEolDateForVersion($eolData, $version),
                'recommendation' => null, // No upgrade possible for KVS
            ];
        }

        $scored = $this->scoreVersion('php', $version, $eolData);
        $scored['recommendation'] = null; // PHP upgrades depend on KVS support

        return $scored;
    }

    /**
     * Get EOL date for a specific version from EOL data
     *
     * @param list<array<string, mixed>> $eolData
     */
    private function getEolDateForVersion(array $eolData, string $version): ?string
    {
        foreach ($eolData as $entry) {
            if (!isset($entry['cycle']) || !is_scalar($entry['cycle'])) {
                continue;
            }
            $cycle = (string) $entry['cycle'];
            if ($cycle === $version || str_starts_with($version, $cycle . '.')) {
                $eol = $entry['eol'] ?? null;
                if (is_string($eol)) {
                    return $eol;
                }
            }
        }
        return null;
    }

    /**
     * Score database version
     *
     * @return array{version: string, type: string, status: string, score: int, eol_date: ?string, recommendation: ?string}
     */
    private function scoreDatabase(): array
    {
        // Try to detect database version from environment or connection
        $dbInfo = $this->detectDatabaseVersion();

        if ($dbInfo === null) {
            return [
                'version' => 'unknown',
                'type' => 'unknown',
                'status' => 'unknown',
                'score' => 90, // Assume recent DB if can't detect
                'eol_date' => null,
                'recommendation' => null,
            ];
        }

        $product = $dbInfo['type'] === 'mariadb' ? 'mariadb' : 'mysql';
        $eolData = $this->fetchEolData($product);
        $scored = $this->scoreVersion($product, $dbInfo['version'], $eolData);

        // Generate recommendation if not on latest LTS
        $recommendation = null;
        if ($scored['status'] !== 'latest') {
            $latestLts = $this->findLatestLts($eolData);
            if ($latestLts !== null && $latestLts !== $dbInfo['version']) {
                $productName = $dbInfo['type'] === 'mariadb' ? 'MariaDB' : 'MySQL';
                $recommendation = "Latest LTS: {$productName} {$latestLts}";
            }
        }

        return [
            'version' => $dbInfo['full_version'],
            'type' => $dbInfo['type'],
            'status' => $scored['status'],
            'score' => $scored['score'],
            'eol_date' => $scored['eol_date'],
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Detect database type and version
     *
     * @return array{type: string, version: string, full_version: string}|null
     */
    private function detectDatabaseVersion(): ?array
    {
        // Try PDO connection first (passed from CLI)
        if ($this->db !== null) {
            try {
                $stmt = $this->db->query('SELECT VERSION()');
                if ($stmt !== false) {
                    $version = $stmt->fetchColumn();
                    if (is_string($version) && $version !== '') {
                        $type = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';

                        // Extract major.minor version
                        if (preg_match('/(\d+\.\d+)/', $version, $m) === 1) {
                            return [
                                'type' => $type,
                                'version' => $m[1],
                                'full_version' => $version,
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors
            }
        }

        // Fallback: Try mysqli extension with constants
        if (defined('DB_HOST') && defined('DB_LOGIN') && defined('DB_PASS')) {
            try {
                /** @var string $host */
                $host = DB_HOST;
                /** @var string $login */
                $login = DB_LOGIN;
                /** @var string $pass */
                $pass = DB_PASS;

                $mysqli = @new \mysqli($host, $login, $pass);
                if ($mysqli->connect_error === null) {
                    $version = $mysqli->server_info;
                    $mysqli->close();

                    $type = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';

                    // Extract major.minor version
                    if (preg_match('/(\d+\.\d+)/', $version, $m) === 1) {
                        return [
                            'type' => $type,
                            'version' => $m[1],
                            'full_version' => $version,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Ignore connection errors
            }
        }

        return null;
    }

    /**
     * Score OS version
     *
     * @return array{version: string, name: string, status: string, score: int, eol_date: ?string, recommendation: ?string}
     */
    private function scoreOs(): array
    {
        $osInfo = $this->detectOs();

        if ($osInfo === null) {
            return [
                'version' => 'unknown',
                'name' => 'unknown',
                'status' => 'unknown',
                'score' => 90, // Assume recent OS if can't detect
                'eol_date' => null,
                'recommendation' => null,
            ];
        }

        $eolData = $this->fetchEolData($osInfo['product']);
        $scored = $this->scoreVersion($osInfo['product'], $osInfo['version'], $eolData);

        // Generate recommendation if not on latest
        $recommendation = null;
        if ($scored['status'] !== 'latest') {
            $latestVersion = $this->findLatestStable($eolData);
            if ($latestVersion !== null && $latestVersion !== $osInfo['version']) {
                $osName = $this->getOsDisplayName($osInfo['product']);
                $recommendation = "Latest stable: {$osName} {$latestVersion}";
            }
        }

        return [
            'version' => $osInfo['version'],
            'name' => $osInfo['name'],
            'status' => $scored['status'],
            'score' => $scored['score'],
            'eol_date' => $scored['eol_date'],
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Score PHP configuration (OPcache, memory_limit, max_execution_time)
     *
     * @return array{opcache: bool, memory_limit: string, max_execution_time: string, score: int, issues: list<string>}
     */
    private function scorePhpConfig(): array
    {
        $opcacheEnabled = isset($this->systemInfo['opcache']) && $this->systemInfo['opcache'] === true;
        $memoryLimit = $this->systemInfo['memory_limit'] ?? 'unknown';
        $maxExecTime = $this->systemInfo['max_execution_time'] ?? 'unknown';

        $score = 100;
        $issues = [];

        // OPcache check (-15 pts if disabled)
        if ($opcacheEnabled === false) {
            $score -= 15;
            $issues[] = 'OPcache is disabled (performance impact: -15%)';
        }

        // Memory limit check (-15 pts if < 256M)
        if (is_string($memoryLimit) && $memoryLimit !== 'unknown' && $memoryLimit !== '-1') {
            $memoryBytes = $this->parseMemoryLimit($memoryLimit);
            if ($memoryBytes > 0 && $memoryBytes < 256 * 1024 * 1024) {
                $score -= 15;
                $issues[] = "memory_limit is {$memoryLimit} (recommended: 256M+)";
            }
        }

        // Max execution time check (-10 pts if < 30)
        if (is_numeric($maxExecTime)) {
            $maxExecTimeInt = (int) $maxExecTime;
            if ($maxExecTimeInt > 0 && $maxExecTimeInt < 30) {
                $score -= 10;
                $issues[] = "max_execution_time is {$maxExecTime}s (recommended: 30s+)";
            }
        }

        return [
            'opcache' => $opcacheEnabled,
            'memory_limit' => is_string($memoryLimit) ? $memoryLimit : 'unknown',
            'max_execution_time' => is_string($maxExecTime) || is_int($maxExecTime) ? (string) $maxExecTime : 'unknown',
            'score' => max(0, $score),
            'issues' => $issues,
        ];
    }

    /**
     * Score web server type
     *
     * @return array{name: string, type: string, score: int, recommendation: ?string}
     */
    private function scoreWebServer(): array
    {
        $serverSoftwareRaw = $this->systemInfo['server_software'] ?? '';
        $serverSoftware = is_string($serverSoftwareRaw) ? $serverSoftwareRaw : '';
        $phpSapiRaw = $this->systemInfo['php_sapi'] ?? PHP_SAPI;
        $phpSapi = is_string($phpSapiRaw) ? $phpSapiRaw : PHP_SAPI;

        if ($serverSoftware === '') {
            return [
                'name' => 'Unknown',
                'type' => 'unknown',
                'score' => 80,
                'recommendation' => null,
            ];
        }

        $serverLower = strtolower($serverSoftware);

        // nginx, Caddy, LiteSpeed = 100
        if (str_contains($serverLower, 'nginx')) {
            return [
                'name' => 'nginx',
                'type' => 'nginx',
                'score' => 100,
                'recommendation' => null,
            ];
        }

        if (str_contains($serverLower, 'caddy')) {
            return [
                'name' => 'Caddy',
                'type' => 'caddy',
                'score' => 100,
                'recommendation' => null,
            ];
        }

        if (str_contains($serverLower, 'litespeed')) {
            return [
                'name' => 'LiteSpeed',
                'type' => 'litespeed',
                'score' => 100,
                'recommendation' => null,
            ];
        }

        // Apache detection
        if (str_contains($serverLower, 'apache')) {
            // Check if using PHP-FPM or mod_php
            $isFpm = str_contains(strtolower($phpSapi), 'fpm');

            if ($isFpm) {
                return [
                    'name' => 'Apache + PHP-FPM',
                    'type' => 'apache-fpm',
                    'score' => 50,
                    'recommendation' => 'Consider nginx for better performance with video streaming',
                ];
            }

            return [
                'name' => 'Apache + mod_php',
                'type' => 'apache-modphp',
                'score' => 20,
                'recommendation' => 'Switch to nginx or use PHP-FPM for significant performance improvement',
            ];
        }

        return [
            'name' => $serverSoftware,
            'type' => 'other',
            'score' => 80,
            'recommendation' => null,
        ];
    }

    /**
     * Score FFmpeg installation and version
     *
     * @return array{installed: bool, version: string, score: int, issues: list<string>}
     */
    private function scoreFfmpeg(): array
    {
        $ffmpegInfo = $this->detectFfmpeg();

        if ($ffmpegInfo === null) {
            return [
                'installed' => false,
                'version' => 'not installed',
                'score' => 0,
                'issues' => ['FFmpeg is not installed (required for video processing)'],
            ];
        }

        $version = $ffmpegInfo['version'];
        $major = $ffmpegInfo['major'];
        $issues = [];
        $score = 100;

        // Score based on version
        if ($major >= 7) {
            $score = 100;
        } elseif ($major >= 6) {
            $score = 100;
        } elseif ($major >= 5) {
            $score = 90;
        } elseif ($major >= 4) {
            $score = 70;
            $issues[] = "FFmpeg {$version} is outdated (recommend 5.x or 6.x)";
        } else {
            $score = 40;
            $issues[] = "FFmpeg {$version} is very outdated (recommend upgrading to 6.x)";
        }

        // Check for critical codecs
        $missingCodecs = [];
        if (!in_array('libx264', $ffmpegInfo['codecs'], true)) {
            $missingCodecs[] = 'libx264';
            $score -= 10;
        }
        if (!in_array('libx265', $ffmpegInfo['codecs'], true)) {
            $missingCodecs[] = 'libx265';
            $score -= 10;
        }
        if (!in_array('aac', $ffmpegInfo['codecs'], true)) {
            $missingCodecs[] = 'aac';
            $score -= 10;
        }

        if ($missingCodecs !== []) {
            $issues[] = 'Missing codecs: ' . implode(', ', $missingCodecs);
        }

        return [
            'installed' => true,
            'version' => $version,
            'score' => max(0, $score),
            'issues' => $issues,
        ];
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $unit = strtoupper(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $limit,
        };
    }

    /**
     * Detect FFmpeg installation and version
     *
     * @return array{version: string, major: int, codecs: list<string>}|null
     */
    private function detectFfmpeg(): ?array
    {
        $output = [];
        $returnCode = 0;
        @exec('ffmpeg -version 2>&1', $output, $returnCode);

        if ($returnCode !== 0 || $output === []) {
            return null;
        }

        $version = 'unknown';
        $major = 0;

        // Parse version from first line
        // Handles: "ffmpeg version 6.0", "ffmpeg version n8.0.1", "ffmpeg version N-12345-g..."
        if (preg_match('/ffmpeg version [nN]?(\d+\.\d+(?:\.\d+)?)/', $output[0], $matches) === 1) {
            $version = $matches[1];
            $major = (int) explode('.', $version)[0];
        }

        // Detect codecs
        $codecOutput = [];
        @exec('ffmpeg -codecs 2>&1', $codecOutput);

        $codecs = [];
        foreach ($codecOutput as $line) {
            if (str_contains($line, 'libx264')) {
                $codecs[] = 'libx264';
            }
            if (str_contains($line, 'libx265')) {
                $codecs[] = 'libx265';
            }
            if (str_contains($line, 'aac') && !str_contains($line, 'aac_fixed')) {
                $codecs[] = 'aac';
            }
        }

        return [
            'version' => $version,
            'major' => $major,
            'codecs' => array_values(array_unique($codecs)),
        ];
    }

    /**
     * Detect OS name and version
     *
     * @return array{product: string, name: string, version: string}|null
     */
    private function detectOs(): ?array
    {
        // Try /etc/os-release first
        if (file_exists('/etc/os-release')) {
            $content = file_get_contents('/etc/os-release');
            if ($content !== false) {
                $lines = explode("\n", $content);
                $info = [];
                foreach ($lines as $line) {
                    if (str_contains($line, '=')) {
                        [$key, $value] = explode('=', $line, 2);
                        $info[$key] = trim($value, '"');
                    }
                }

                $id = strtolower($info['ID'] ?? '');
                $versionId = $info['VERSION_ID'] ?? '';
                $buildId = $info['BUILD_ID'] ?? '';
                $name = $info['PRETTY_NAME'] ?? $info['NAME'] ?? 'Linux';

                // Map to endoflife.date product names
                $productMap = [
                    'ubuntu' => 'ubuntu',
                    'debian' => 'debian',
                    'centos' => 'centos',
                    'rhel' => 'rhel',
                    'fedora' => 'fedora',
                    'rocky' => 'rocky-linux',
                    'almalinux' => 'almalinux',
                    'alpine' => 'alpine',
                    'arch' => 'arch-linux',
                    'opensuse' => 'opensuse',
                    'opensuse-leap' => 'opensuse',
                    'cachyos' => 'arch-linux', // CachyOS is Arch-based
                ];

                $product = $productMap[$id] ?? null;

                // Handle rolling releases (Arch, CachyOS, etc.)
                if ($product !== null && $versionId === '' && $buildId === 'rolling') {
                    return [
                        'product' => $product,
                        'name' => $name,
                        'version' => 'rolling',
                    ];
                }

                if ($product !== null && $versionId !== '') {
                    return [
                        'product' => $product,
                        'name' => $name,
                        'version' => $versionId,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Score a specific version against EOL data
     *
     * @param list<array<string, mixed>> $eolData
     * @return array{version: string, status: string, score: int, eol_date: ?string, recommendation: ?string}
     */
    private function scoreVersion(string $product, string $version, array $eolData): array
    {
        // Handle rolling releases (always current by definition)
        if ($version === 'rolling') {
            return [
                'version' => 'rolling',
                'status' => 'rolling',
                'score' => self::SCORE_LATEST,
                'eol_date' => null,
                'recommendation' => null,
            ];
        }

        $result = [
            'version' => $version,
            'status' => 'unknown',
            'score' => 90, // Assume recent version if can't verify
            'eol_date' => null,
            'recommendation' => null,
        ];

        if ($eolData === []) {
            return $result;
        }

        $now = new \DateTime();

        // Find matching cycle for current version
        $matchedEntry = $this->findMatchingEntry($version, $eolData);

        // Try matching just major version for some products
        if ($matchedEntry === null && str_contains($version, '.')) {
            $majorVersion = explode('.', $version)[0];
            $matchedEntry = $this->findMatchingEntry($majorVersion, $eolData);
        }

        if ($matchedEntry === null) {
            $result['status'] = 'current';
            $result['score'] = self::SCORE_ACTIVE;
            return $result;
        }

        $eol = $matchedEntry['eol'] ?? null;
        $support = $matchedEntry['support'] ?? null;

        // Check EOL status first
        if ($eol === true || $eol === 'true') {
            $result['status'] = 'eol';
            $result['score'] = self::SCORE_EOL;
            $result['eol_date'] = 'EOL';
            return $result;
        }

        if (is_string($eol)) {
            try {
                $eolDate = new \DateTime($eol);
                $result['eol_date'] = $eol;

                if ($now > $eolDate) {
                    $result['status'] = 'eol';
                    $result['score'] = self::SCORE_EOL;
                    return $result;
                }

                // Check if EOL within 6 months
                $sixMonths = (clone $now)->modify('+6 months');
                if ($sixMonths > $eolDate) {
                    $result['status'] = 'eol_soon';
                    $result['score'] = self::SCORE_EOL_SOON;
                    return $result;
                }
            } catch (\Exception $e) {
                // Invalid date, treat as unknown
            }
        }

        // Check if in security-only support
        if (is_string($support)) {
            try {
                $supportDate = new \DateTime($support);
                if ($now > $supportDate) {
                    $result['status'] = 'security';
                    $result['score'] = self::SCORE_SECURITY;
                    return $result;
                }
            } catch (\Exception $e) {
                // Invalid date
            }
        }

        // Version is active - now check if it's the latest
        $cycleVal = $matchedEntry['cycle'] ?? '';
        $matchedCycle = is_scalar($cycleVal) ? (string) $cycleVal : '';

        // Check if current version is LTS
        $isCurrentLts = isset($matchedEntry['lts']) &&
            ($matchedEntry['lts'] === true || $matchedEntry['lts'] === 'true');

        // For LTS versions, compare against latest LTS (not latest rolling release)
        if ($isCurrentLts) {
            $latestLts = $this->findLatestLts($eolData);
            if ($latestLts !== null && $matchedCycle === $latestLts) {
                $result['status'] = 'lts_current';
                $result['score'] = self::SCORE_LATEST;
                return $result;
            }
            if ($latestLts !== null && $matchedCycle !== $latestLts) {
                $result['status'] = 'outdated';
                $result['score'] = self::SCORE_OUTDATED;
                return $result;
            }
        }

        // For non-LTS versions, compare against latest active version
        $latestActive = $this->findLatestActiveVersion($eolData, $now);

        if ($latestActive !== null && $matchedCycle !== $latestActive) {
            $result['status'] = 'outdated';
            $result['score'] = self::SCORE_OUTDATED;
            return $result;
        }

        $result['status'] = 'latest';
        $result['score'] = self::SCORE_LATEST;
        return $result;
    }

    /**
     * Find matching entry for a version in EOL data
     *
     * @param list<array<string, mixed>> $eolData
     * @return array<string, mixed>|null
     */
    private function findMatchingEntry(string $version, array $eolData): ?array
    {
        foreach ($eolData as $entry) {
            if (!isset($entry['cycle']) || !is_scalar($entry['cycle'])) {
                continue;
            }
            $cycle = (string) $entry['cycle'];
            if ($cycle === $version || str_starts_with($version, $cycle . '.')) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Find the latest active version from EOL data
     *
     * @param list<array<string, mixed>> $eolData
     */
    private function findLatestActiveVersion(array $eolData, \DateTime $now): ?string
    {
        foreach ($eolData as $entry) {
            if (!isset($entry['cycle']) || !is_scalar($entry['cycle'])) {
                continue;
            }

            $eol = $entry['eol'] ?? null;

            // Skip if already EOL
            if ($eol === true || $eol === 'true') {
                continue;
            }

            if (is_string($eol)) {
                try {
                    $eolDate = new \DateTime($eol);
                    if ($now > $eolDate) {
                        continue;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            // First active version found = latest
            return (string) $entry['cycle'];
        }

        return null;
    }

    /**
     * Find the latest LTS version from EOL data
     *
     * @param list<array<string, mixed>> $eolData
     */
    private function findLatestLts(array $eolData): ?string
    {
        $now = new \DateTime();

        foreach ($eolData as $entry) {
            if (!isset($entry['cycle']) || !is_scalar($entry['cycle'])) {
                continue;
            }

            // Check if it's an LTS version
            $isLts = isset($entry['lts']) && ($entry['lts'] === true || $entry['lts'] === 'true');
            if (!$isLts) {
                continue;
            }

            // Check if not EOL
            $eol = $entry['eol'] ?? null;
            if ($eol === true || $eol === 'true') {
                continue;
            }

            if (is_string($eol)) {
                try {
                    $eolDate = new \DateTime($eol);
                    if ($now > $eolDate) {
                        continue;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            // First active LTS version found = latest LTS
            return (string) $entry['cycle'];
        }

        return null;
    }

    /**
     * Find the latest stable version from EOL data (for OS)
     *
     * @param list<array<string, mixed>> $eolData
     */
    private function findLatestStable(array $eolData): ?string
    {
        $now = new \DateTime();

        foreach ($eolData as $entry) {
            if (!isset($entry['cycle']) || !is_scalar($entry['cycle'])) {
                continue;
            }

            // Check if not EOL
            $eol = $entry['eol'] ?? null;
            if ($eol === true || $eol === 'true') {
                continue;
            }

            if (is_string($eol)) {
                try {
                    $eolDate = new \DateTime($eol);
                    if ($now > $eolDate) {
                        continue;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            // First active version found = latest stable
            return (string) $entry['cycle'];
        }

        return null;
    }

    /**
     * Get display name for OS product
     */
    private function getOsDisplayName(string $product): string
    {
        return match ($product) {
            'ubuntu' => 'Ubuntu',
            'debian' => 'Debian',
            'centos' => 'CentOS',
            'rhel' => 'RHEL',
            'fedora' => 'Fedora',
            'rocky-linux' => 'Rocky Linux',
            'almalinux' => 'AlmaLinux',
            'alpine' => 'Alpine',
            'arch-linux' => 'Arch Linux',
            'opensuse' => 'openSUSE',
            'cachyos' => 'CachyOS',
            default => ucfirst($product),
        };
    }

    /**
     * Get rating string based on score
     */
    private function getRating(int $score): string
    {
        return match (true) {
            $score >= 90 => '★★★★★ Excellent',
            $score >= 70 => '★★★★☆ Good',
            $score >= 50 => '★★★☆☆ Fair',
            $score >= 30 => '★★☆☆☆ Outdated',
            default => '★☆☆☆☆ Critical',
        };
    }

    /**
     * Fetch EOL data from endoflife.date API with caching
     *
     * @return list<array<string, mixed>>
     */
    private function fetchEolData(string $product): array
    {
        if (isset($this->eolCache[$product])) {
            return $this->eolCache[$product];
        }

        $cacheDir = Constants::EOL_CACHE_DIR;
        $cacheFile = "$cacheDir/$product.json";

        // Check file cache
        if (file_exists($cacheFile)) {
            $mtime = filemtime($cacheFile);
            if ($mtime !== false && (time() - $mtime) < Constants::EOL_CACHE_TTL) {
                $cached = @file_get_contents($cacheFile);
                if ($cached !== false && $cached !== '') {
                    $data = json_decode($cached, true);
                    if (is_array($data)) {
                        /** @var list<array<string, mixed>> $result */
                        $result = array_values($data);
                        $this->eolCache[$product] = $result;
                        return $result;
                    }
                }
            }
        }

        // Fetch from API
        if (!extension_loaded('curl')) {
            return [];
        }

        $url = Constants::EOL_API_BASE . '/' . $product . '.json';
        $ch = curl_init($url);
        if ($ch === false) {
            return [];
        }

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

        /** @var list<array<string, mixed>> $result */
        $result = array_values($data);

        // Cache result
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, $response);

        $this->eolCache[$product] = $result;
        return $result;
    }

    /**
     * Get status label with color
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'active', 'current' => '<fg=green>Active</>',
            'latest' => '<fg=green>Latest</>',
            'rolling' => '<fg=green>Rolling Release</>',
            'lts_current' => '<fg=green>LTS Current</>',
            'kvs_optimal' => '<fg=green>KVS Optimal</>',
            'outdated' => '<fg=yellow>Outdated</>',
            'security' => '<fg=yellow>Security Only</>',
            'eol_soon' => '<fg=yellow>EOL Soon</>',
            'eol' => '<fg=red>End of Life</>',
            default => '<fg=gray>Unknown</>',
        };
    }
}
