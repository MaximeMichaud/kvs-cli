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
    private const SCORE_ACTIVE = 100;      // Active support
    private const SCORE_SECURITY = 70;     // Security fixes only
    private const SCORE_EOL_SOON = 40;     // EOL within 6 months
    private const SCORE_EOL = 0;           // End of life

    /** @var array<string, list<array<string, mixed>>> */
    private array $eolCache = [];

    private ?\PDO $db = null;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db;
    }

    /**
     * Calculate stack score for the current system
     *
     * @return array{
     *     total: int,
     *     php: array{version: string, status: string, score: int, eol_date: ?string},
     *     database: array{version: string, type: string, status: string, score: int, eol_date: ?string},
     *     os: array{version: string, name: string, status: string, score: int, eol_date: ?string},
     *     rating: string
     * }
     */
    public function calculate(): array
    {
        $phpScore = $this->scorePhp();
        $dbScore = $this->scoreDatabase();
        $osScore = $this->scoreOs();

        // Weighted average: PHP 40%, Database 40%, OS 20%
        $totalScore = (int) (
            ($phpScore['score'] * 0.4) +
            ($dbScore['score'] * 0.4) +
            ($osScore['score'] * 0.2)
        );

        return [
            'total' => $totalScore,
            'php' => $phpScore,
            'database' => $dbScore,
            'os' => $osScore,
            'rating' => $this->getRating($totalScore),
        ];
    }

    /**
     * Score PHP version
     *
     * @return array{version: string, status: string, score: int, eol_date: ?string}
     */
    private function scorePhp(): array
    {
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $eolData = $this->fetchEolData('php');

        return $this->scoreVersion('php', $version, $eolData);
    }

    /**
     * Score database version
     *
     * @return array{version: string, type: string, status: string, score: int, eol_date: ?string}
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
                'score' => 50, // Neutral score if can't detect
                'eol_date' => null,
            ];
        }

        $product = $dbInfo['type'] === 'mariadb' ? 'mariadb' : 'mysql';
        $eolData = $this->fetchEolData($product);
        $scored = $this->scoreVersion($product, $dbInfo['version'], $eolData);

        return [
            'version' => $dbInfo['full_version'],
            'type' => $dbInfo['type'],
            'status' => $scored['status'],
            'score' => $scored['score'],
            'eol_date' => $scored['eol_date'],
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
     * @return array{version: string, name: string, status: string, score: int, eol_date: ?string}
     */
    private function scoreOs(): array
    {
        $osInfo = $this->detectOs();

        if ($osInfo === null) {
            return [
                'version' => 'unknown',
                'name' => 'unknown',
                'status' => 'unknown',
                'score' => 50,
                'eol_date' => null,
            ];
        }

        $eolData = $this->fetchEolData($osInfo['product']);
        $scored = $this->scoreVersion($osInfo['product'], $osInfo['version'], $eolData);

        return [
            'version' => $osInfo['version'],
            'name' => $osInfo['name'],
            'status' => $scored['status'],
            'score' => $scored['score'],
            'eol_date' => $scored['eol_date'],
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
                ];

                $product = $productMap[$id] ?? null;
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
     * @return array{version: string, status: string, score: int, eol_date: ?string}
     */
    private function scoreVersion(string $product, string $version, array $eolData): array
    {
        $result = [
            'version' => $version,
            'status' => 'unknown',
            'score' => 50,
            'eol_date' => null,
        ];

        if ($eolData === []) {
            return $result;
        }

        // Find matching cycle
        $matchedEntry = null;
        foreach ($eolData as $entry) {
            if (!isset($entry['cycle']) || !is_scalar($entry['cycle'])) {
                continue;
            }
            $cycle = (string) $entry['cycle'];

            // Try exact match first, then prefix match
            if ($cycle === $version || str_starts_with($version, $cycle . '.')) {
                $matchedEntry = $entry;
                break;
            }
        }

        // Try matching just major version for some products
        if ($matchedEntry === null && str_contains($version, '.')) {
            $majorVersion = explode('.', $version)[0];
            foreach ($eolData as $entry) {
                if (!isset($entry['cycle']) || !is_scalar($entry['cycle'])) {
                    continue;
                }
                if ((string) $entry['cycle'] === $majorVersion) {
                    $matchedEntry = $entry;
                    break;
                }
            }
        }

        if ($matchedEntry === null) {
            $result['status'] = 'current';
            $result['score'] = self::SCORE_ACTIVE;
            return $result;
        }

        $now = new \DateTime();
        $eol = $matchedEntry['eol'] ?? null;
        $support = $matchedEntry['support'] ?? null;

        // Check EOL status
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

        $result['status'] = 'active';
        $result['score'] = self::SCORE_ACTIVE;
        return $result;
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
            'security' => '<fg=yellow>Security Only</>',
            'eol_soon' => '<fg=yellow>EOL Soon</>',
            'eol' => '<fg=red>End of Life</>',
            default => '<fg=gray>Unknown</>',
        };
    }
}
