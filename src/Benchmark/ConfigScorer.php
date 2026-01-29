<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

/**
 * Config Score calculator for KVS installations
 *
 * Checks PHP settings, OPcache, and cache memory against
 * KVS recommended values from KVS-install repository.
 */
class ConfigScorer
{
    // KVS recommended thresholds (from KVS-install)
    public const UPLOAD_MIN_MB = 1024;           // upload_max_filesize & post_max_size (1G+)
    public const MEMORY_LIMIT_MIN_MB = 256;      // Minimum (256M - 2048M optimal)
    public const MAX_INPUT_VARS_MIN = 10000;

    public const OPCACHE_MIN_MB = 256;           // opcache.memory_consumption
    public const OPCACHE_STRINGS_MIN_MB = 16;    // opcache.interned_strings_buffer

    public const MEMCACHE_MIN_MB = 256;          // Bare metal minimum
    public const MEMCACHE_GOOD_MB = 512;         // Recommended

    // Score weights (total = 100) - No database since KVS uses MyISAM
    private const WEIGHT_PHP_SETTINGS = 40;
    private const WEIGHT_OPCACHE = 30;
    private const WEIGHT_CACHE = 30;

    /**
     * Calculate config score from check results
     *
     * @param array{
     *     php_settings?: array<string, string|int>,
     *     opcache?: array{enabled: bool, memory_mb: int, strings_mb: int},
     *     cache?: array{connected: bool, memory_mb: int|null, type: string}
     * } $checks
     * @return array{
     *     total: int,
     *     php_settings: array{score: int, max: int, issues: list<string>, recommendations: list<string>},
     *     opcache: array{score: int, max: int, issues: list<string>, recommendations: list<string>},
     *     cache: array{score: int, max: int, issues: list<string>, recommendations: list<string>},
     *     rating: string
     * }
     */
    public function calculate(array $checks): array
    {
        $phpResult = $this->scorePhpSettings($checks['php_settings'] ?? []);
        $opcacheResult = $this->scoreOpcache($checks['opcache'] ?? null);
        $cacheResult = $this->scoreCache($checks['cache'] ?? null);

        // Calculate weighted total
        $total = (int) (
            ($phpResult['score'] / $phpResult['max']) * self::WEIGHT_PHP_SETTINGS +
            ($opcacheResult['score'] / $opcacheResult['max']) * self::WEIGHT_OPCACHE +
            ($cacheResult['score'] / $cacheResult['max']) * self::WEIGHT_CACHE
        );

        return [
            'total' => $total,
            'php_settings' => $phpResult,
            'opcache' => $opcacheResult,
            'cache' => $cacheResult,
            'rating' => $this->getRating($total),
        ];
    }

    /**
     * @param array<string, string|int> $settings
     * @return array{score: int, max: int, issues: list<string>, recommendations: list<string>}
     */
    private function scorePhpSettings(array $settings): array
    {
        $score = 0;
        $max = 3;
        $issues = [];
        $recommendations = [];

        // upload_max_filesize (1 point)
        $uploadBytes = $this->parseSize($settings['upload_max_filesize'] ?? '2M');
        if ($uploadBytes >= self::UPLOAD_MIN_MB * 1024 * 1024) {
            $score++;
        } else {
            $current = $this->formatBytes($uploadBytes);
            $issues[] = "upload_max_filesize = {$current} (need " . self::UPLOAD_MIN_MB . 'M)';
            $recommendations[] = 'upload_max_filesize = ' . self::UPLOAD_MIN_MB . 'M';
        }

        // post_max_size (1 point)
        $postBytes = $this->parseSize($settings['post_max_size'] ?? '8M');
        if ($postBytes >= self::UPLOAD_MIN_MB * 1024 * 1024) {
            $score++;
        } else {
            $current = $this->formatBytes($postBytes);
            $issues[] = "post_max_size = {$current} (need " . self::UPLOAD_MIN_MB . 'M)';
            $recommendations[] = 'post_max_size = ' . self::UPLOAD_MIN_MB . 'M';
        }

        // memory_limit (1 point) - 256M minimum, up to 2048M optimal
        $memoryBytes = $this->parseSize($settings['memory_limit'] ?? '128M');
        if ($memoryBytes === -1 || $memoryBytes >= self::MEMORY_LIMIT_MIN_MB * 1024 * 1024) {
            $score++;
        } else {
            $current = $this->formatBytes($memoryBytes);
            $issues[] = "memory_limit = {$current} (need " . self::MEMORY_LIMIT_MIN_MB . 'M+)';
            $recommendations[] = 'memory_limit = ' . self::MEMORY_LIMIT_MIN_MB . 'M';
        }

        return [
            'score' => $score,
            'max' => $max,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @param array{enabled: bool, memory_mb: int, strings_mb: int}|null $opcache
     * @return array{score: int, max: int, issues: list<string>, recommendations: list<string>}
     */
    private function scoreOpcache(?array $opcache): array
    {
        $score = 0;
        $max = 3;
        $issues = [];
        $recommendations = [];

        if ($opcache === null) {
            return [
                'score' => 0,
                'max' => $max,
                'issues' => ['OPcache not available'],
                'recommendations' => ['Enable OPcache extension'],
            ];
        }

        // Enabled (1 point)
        if ($opcache['enabled']) {
            $score++;
        } else {
            $issues[] = 'OPcache disabled';
            $recommendations[] = 'opcache.enable = 1';
        }

        // Memory >= 256MB (1 point)
        if ($opcache['memory_mb'] >= self::OPCACHE_MIN_MB) {
            $score++;
        } else {
            $issues[] = "opcache.memory_consumption = {$opcache['memory_mb']}M (need " . self::OPCACHE_MIN_MB . 'M)';
            $recommendations[] = 'opcache.memory_consumption = ' . self::OPCACHE_MIN_MB;
        }

        // Interned strings >= 16MB (1 point)
        if ($opcache['strings_mb'] >= self::OPCACHE_STRINGS_MIN_MB) {
            $score++;
        } else {
            $issues[] = "opcache.interned_strings_buffer = {$opcache['strings_mb']}M (need " . self::OPCACHE_STRINGS_MIN_MB . 'M)';
            $recommendations[] = 'opcache.interned_strings_buffer = ' . self::OPCACHE_STRINGS_MIN_MB;
        }

        return [
            'score' => $score,
            'max' => $max,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @param array{connected: bool, memory_mb: int|null, type: string}|null $cache
     * @return array{score: int, max: int, issues: list<string>, recommendations: list<string>}
     */
    private function scoreCache(?array $cache): array
    {
        $score = 0;
        $max = 3;
        $issues = [];
        $recommendations = [];

        if ($cache === null || !$cache['connected']) {
            return [
                'score' => 0,
                'max' => $max,
                'issues' => ['Cache not connected'],
                'recommendations' => ['Configure Memcached or Dragonfly'],
            ];
        }

        // Connected (1 point)
        $score++;

        $memoryMb = $cache['memory_mb'];
        $cacheType = $cache['type'];

        // Memory >= 256MB (1 point)
        if ($memoryMb !== null && $memoryMb >= self::MEMCACHE_MIN_MB) {
            $score++;
            // Memory >= 512MB (1 point bonus) - no recommendation if >= 256MB
            if ($memoryMb >= self::MEMCACHE_GOOD_MB) {
                $score++;
            }
            // 256MB is sufficient, 512MB is just a bonus - no recommendation needed
        } else {
            $currentMb = $memoryMb ?? 0;
            $issues[] = sprintf(
                '%s memory = %dMB (need %dMB minimum, %dMB recommended)',
                $cacheType,
                $currentMb,
                self::MEMCACHE_MIN_MB,
                self::MEMCACHE_GOOD_MB
            );
            $recommendations[] = "{$cacheType}: -m " . self::MEMCACHE_GOOD_MB;
        }

        return [
            'score' => $score,
            'max' => $max,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    private function getRating(int $score): string
    {
        return match (true) {
            $score >= 90 => '★★★★★ Excellent',
            $score >= 70 => '★★★★☆ Good',
            $score >= 50 => '★★★☆☆ Fair',
            $score >= 30 => '★★☆☆☆ Needs Work',
            default => '★☆☆☆☆ Critical',
        };
    }

    private function parseSize(string|int $size): int
    {
        if (is_int($size)) {
            return $size;
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024 / 1024, 1) . 'G';
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024) . 'M';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . 'K';
        }
        return $bytes . 'B';
    }

    /**
     * Get status label with color
     */
    public static function getScoreLabel(int $score, int $max): string
    {
        $percent = ($max > 0) ? ($score / $max) * 100 : 0;

        return match (true) {
            $percent >= 80 => '<fg=green>' . $score . '/' . $max . '</>',
            $percent >= 50 => '<fg=yellow>' . $score . '/' . $max . '</>',
            default => '<fg=red>' . $score . '/' . $max . '</>',
        };
    }
}
