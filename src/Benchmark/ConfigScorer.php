<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

/**
 * Config Score calculator for KVS installations
 *
 * Checks PHP settings, OPcache, cache memory, and database config
 * against recommended values for KVS. Uses same thresholds as system:check.
 */
class ConfigScorer
{
    // Same thresholds as CheckCommand
    public const MEMCACHE_MIN_MB = 256;
    public const OPCACHE_MIN_MB = 128;
    public const OPCACHE_STRINGS_MIN_MB = 8;
    public const UPLOAD_MIN_MB = 512;
    public const MEMORY_LIMIT_MIN_MB = 128;
    public const MEMORY_LIMIT_GOOD_MB = 256;
    public const MAX_EXECUTION_TIME_MIN = 300;
    public const MAX_INPUT_VARS_MIN = 10000;
    public const INNODB_BUFFER_MIN_MB = 512;

    // Score weights (total = 100)
    private const WEIGHT_PHP_SETTINGS = 25;
    private const WEIGHT_OPCACHE = 25;
    private const WEIGHT_CACHE = 25;
    private const WEIGHT_DATABASE = 25;

    /**
     * Calculate config score from check results
     *
     * @param array{
     *     php_settings?: array<string, string|int>,
     *     opcache?: array{enabled: bool, memory_mb: int, strings_mb: int, jit_enabled: bool},
     *     cache?: array{connected: bool, memory_mb: int|null, type: string},
     *     database?: array{innodb_buffer_mb: int|null}
     * } $checks
     * @return array{
     *     total: int,
     *     php_settings: array{score: int, max: int, issues: list<string>, recommendations: list<string>},
     *     opcache: array{score: int, max: int, issues: list<string>, recommendations: list<string>},
     *     cache: array{score: int, max: int, issues: list<string>, recommendations: list<string>},
     *     database: array{score: int, max: int, issues: list<string>, recommendations: list<string>},
     *     rating: string
     * }
     */
    public function calculate(array $checks): array
    {
        $phpResult = $this->scorePhpSettings($checks['php_settings'] ?? []);
        $opcacheResult = $this->scoreOpcache($checks['opcache'] ?? null);
        $cacheResult = $this->scoreCache($checks['cache'] ?? null);
        $dbResult = $this->scoreDatabase($checks['database'] ?? null);

        // Calculate weighted total
        $total = (int) (
            ($phpResult['score'] / $phpResult['max']) * self::WEIGHT_PHP_SETTINGS +
            ($opcacheResult['score'] / $opcacheResult['max']) * self::WEIGHT_OPCACHE +
            ($cacheResult['score'] / $cacheResult['max']) * self::WEIGHT_CACHE +
            ($dbResult['score'] / $dbResult['max']) * self::WEIGHT_DATABASE
        );

        return [
            'total' => $total,
            'php_settings' => $phpResult,
            'opcache' => $opcacheResult,
            'cache' => $cacheResult,
            'database' => $dbResult,
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
        $max = 5;
        $issues = [];
        $recommendations = [];

        // upload_max_filesize (1 point)
        $uploadBytes = $this->parseSize($settings['upload_max_filesize'] ?? '2M');
        if ($uploadBytes >= self::UPLOAD_MIN_MB * 1024 * 1024) {
            $score++;
        } else {
            $issues[] = 'upload_max_filesize < ' . self::UPLOAD_MIN_MB . 'M';
            $recommendations[] = 'upload_max_filesize = ' . self::UPLOAD_MIN_MB . 'M';
        }

        // post_max_size (1 point)
        $postBytes = $this->parseSize($settings['post_max_size'] ?? '8M');
        if ($postBytes >= self::UPLOAD_MIN_MB * 1024 * 1024) {
            $score++;
        } else {
            $issues[] = 'post_max_size < ' . self::UPLOAD_MIN_MB . 'M';
            $recommendations[] = 'post_max_size = ' . self::UPLOAD_MIN_MB . 'M';
        }

        // memory_limit (1 point for min, +1 for good)
        $memoryBytes = $this->parseSize($settings['memory_limit'] ?? '128M');
        if ($memoryBytes === -1 || $memoryBytes >= self::MEMORY_LIMIT_GOOD_MB * 1024 * 1024) {
            $score += 2;
        } elseif ($memoryBytes >= self::MEMORY_LIMIT_MIN_MB * 1024 * 1024) {
            $score++;
            $recommendations[] = 'memory_limit = ' . self::MEMORY_LIMIT_GOOD_MB . 'M (currently ' . $this->formatBytes($memoryBytes) . ')';
        } else {
            $issues[] = 'memory_limit < ' . self::MEMORY_LIMIT_MIN_MB . 'M';
            $recommendations[] = 'memory_limit = ' . self::MEMORY_LIMIT_GOOD_MB . 'M';
        }

        // max_execution_time (1 point)
        $maxExec = (int) ($settings['max_execution_time'] ?? 30);
        if ($maxExec === 0 || $maxExec >= self::MAX_EXECUTION_TIME_MIN) {
            $score++;
        } else {
            $issues[] = 'max_execution_time < ' . self::MAX_EXECUTION_TIME_MIN;
            $recommendations[] = 'max_execution_time = ' . self::MAX_EXECUTION_TIME_MIN;
        }

        return [
            'score' => $score,
            'max' => $max,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @param array{enabled: bool, memory_mb: int, strings_mb: int, jit_enabled: bool}|null $opcache
     * @return array{score: int, max: int, issues: list<string>, recommendations: list<string>}
     */
    private function scoreOpcache(?array $opcache): array
    {
        $score = 0;
        $max = 4;
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

        // Memory (1 point)
        if ($opcache['memory_mb'] >= self::OPCACHE_MIN_MB) {
            $score++;
        } else {
            $issues[] = 'opcache.memory_consumption < ' . self::OPCACHE_MIN_MB . 'M';
            $recommendations[] = 'opcache.memory_consumption = ' . self::OPCACHE_MIN_MB;
        }

        // Interned strings (1 point)
        if ($opcache['strings_mb'] >= self::OPCACHE_STRINGS_MIN_MB) {
            $score++;
        } else {
            $issues[] = 'opcache.interned_strings_buffer < ' . self::OPCACHE_STRINGS_MIN_MB . 'M';
            $recommendations[] = 'opcache.interned_strings_buffer = ' . self::OPCACHE_STRINGS_MIN_MB;
        }

        // JIT (1 point for PHP 8+)
        if ($opcache['jit_enabled']) {
            $score++;
        } else {
            $recommendations[] = 'opcache.jit_buffer_size = 64M (optional)';
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
        $max = 2;
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

        // Memory >= 256MB (1 point)
        $memoryMb = $cache['memory_mb'];
        if ($memoryMb !== null && $memoryMb >= self::MEMCACHE_MIN_MB) {
            $score++;
        } else {
            $currentMb = $memoryMb ?? 0;
            $cacheType = $cache['type'];
            $issues[] = "{$cacheType} memory < " . self::MEMCACHE_MIN_MB . "MB (current: {$currentMb}MB)";
            $recommendations[] = "{$cacheType}: -m " . self::MEMCACHE_MIN_MB;
        }

        return [
            'score' => $score,
            'max' => $max,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @param array{innodb_buffer_mb: int|null}|null $database
     * @return array{score: int, max: int, issues: list<string>, recommendations: list<string>}
     */
    private function scoreDatabase(?array $database): array
    {
        $score = 0;
        $max = 1;
        $issues = [];
        $recommendations = [];

        if ($database === null || $database['innodb_buffer_mb'] === null) {
            return [
                'score' => 0,
                'max' => $max,
                'issues' => ['Database not available'],
                'recommendations' => [],
            ];
        }

        // innodb_buffer_pool_size >= 512MB (1 point)
        if ($database['innodb_buffer_mb'] >= self::INNODB_BUFFER_MIN_MB) {
            $score++;
        } else {
            $current = $database['innodb_buffer_mb'];
            $issues[] = "innodb_buffer_pool_size < " . self::INNODB_BUFFER_MIN_MB . "MB (current: {$current}MB)";
            $recommendations[] = "innodb_buffer_pool_size = " . self::INNODB_BUFFER_MIN_MB . "M";
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
