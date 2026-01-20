<?php

namespace KVS\CLI\Compat;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * KVS version compatibility checker.
 *
 * Handles:
 * - Version range validation (min/max supported)
 * - Unknown keys detection (new KVS options we don't know about)
 * - Backwards compatibility mappings (renamed keys)
 *
 * Inspired by WP-CLI's Configurator pattern.
 */
class KvsCompatibility
{
    /**
     * Minimum supported KVS version.
     */
    public const MIN_VERSION = '6.3.2';

    /**
     * Maximum tested KVS version.
     * Versions newer than this trigger a warning - we can't guarantee compatibility.
     * Update this constant after testing with new KVS releases.
     */
    public const MAX_TESTED_VERSION = '6.4.0';

    /**
     * Known configuration keys for each config type.
     * Keys not in this list trigger an "unknown key" warning.
     *
     * @var array<string, list<string>>
     */
    private const KNOWN_KEYS = [
        'stats_params' => [
            // Traffic stats
            'collect_traffic_stats',
            'collect_traffic_stats_countries',
            'collect_traffic_stats_devices',
            'collect_traffic_stats_embed_domains',
            'keep_traffic_stats_period',
            // Player stats
            'collect_player_stats',
            'collect_player_stats_countries',
            'collect_player_stats_devices',
            'collect_player_stats_embed_profiles',
            'keep_player_stats_period',
            'player_stats_reporting',
            // Videos stats
            'collect_videos_stats',
            'collect_videos_stats_unique',
            'collect_videos_embeds_unique',
            'collect_videos_stats_video_plays',
            'collect_videos_stats_video_files',
            'keep_videos_stats_period',
            'videos_stats_limit_countries_option',
            'videos_stats_limit_countries',
            // Albums stats
            'collect_albums_stats',
            'collect_albums_stats_unique',
            'collect_albums_stats_album_images',
            'keep_albums_stats_period',
            'albums_stats_limit_countries_option',
            'albums_stats_limit_countries',
            // Memberzone stats
            'collect_memberzone_stats',
            'collect_memberzone_stats_video_files',
            'collect_memberzone_stats_album_images',
            'keep_memberzone_stats_period',
            // Search stats
            'collect_search_stats',
            'keep_search_stats_period',
            'search_inactive',
            'search_to_lowercase',
            'search_max_length',
            'search_stop_symbols',
            'search_stats_limit_countries_option',
            'search_stats_limit_countries',
            // Performance stats
            'collect_performance_stats',
        ],
        'antispam' => [
            'antispam_enable',
            'antispam_videos_enabled',
            'antispam_albums_enabled',
            'antispam_posts_enabled',
            'antispam_playlists_enabled',
            'antispam_dvds_enabled',
            'antispam_comments_enabled',
            'antispam_messages_enabled',
            'antispam_feedbacks_enabled',
            'antispam_banned_words',
            'antispam_banned_domains',
            'antispam_banned_ips',
        ],
    ];

    /**
     * Backwards compatibility mappings.
     * Maps old key names to new key names when KVS renames settings.
     *
     * Format: 'config_type' => ['old_key' => 'new_key']
     *
     * @var array<string, array<string, string>>
     */
    private static array $keyMappings = [
        'stats_params' => [
            // Example: if KVS 6.5 renames 'collect_traffic_stats' to 'enable_traffic_stats'
            // 'collect_traffic_stats' => 'enable_traffic_stats',
        ],
        'antispam' => [
            // No mappings yet
        ],
    ];

    /**
     * Deprecated keys that should trigger a warning.
     *
     * Format: 'config_type' => ['key' => 'message']
     *
     * @var array<string, array<string, string>>
     */
    private static array $deprecatedKeys = [
        'stats_params' => [
            // Example: 'old_option' => 'Use --new-option instead.',
        ],
        'antispam' => [
            // No deprecations yet
        ],
    ];

    private string $kvsVersion;
    private ?SymfonyStyle $io;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(string $kvsVersion, ?SymfonyStyle $io = null)
    {
        $this->kvsVersion = $kvsVersion;
        $this->io = $io;
    }

    /**
     * Check if KVS version is within supported range.
     *
     * @return bool True if version is supported, false otherwise
     */
    public function checkVersion(): bool
    {
        if ($this->kvsVersion === '') {
            $this->addWarning('Could not detect KVS version. Some features may not work correctly.');
            return true; // Continue anyway
        }

        // Check minimum version
        if (version_compare($this->kvsVersion, self::MIN_VERSION, '<')) {
            $this->addWarning(sprintf(
                'KVS version %s is older than minimum supported version %s. Some features may not work.',
                $this->kvsVersion,
                self::MIN_VERSION
            ));
            return false;
        }

        // Check maximum tested version
        if (version_compare($this->kvsVersion, self::MAX_TESTED_VERSION, '>')) {
            $this->addWarning(sprintf(
                'KVS version %s is newer than tested version %s. New options may be available - consider updating kvs-cli.',
                $this->kvsVersion,
                self::getMaxTestedVersionDisplay()
            ));
        }

        return true;
    }

    /**
     * Get the maximum tested KVS version.
     */
    public static function getMaxTestedVersionDisplay(): string
    {
        return self::MAX_TESTED_VERSION;
    }

    /**
     * Check for unknown keys in configuration.
     *
     * @param string $configType The config type (e.g., 'stats_params', 'antispam')
     * @param array<string, mixed> $params The configuration parameters to check
     * @return list<string> List of unknown keys found
     */
    public function checkUnknownKeys(string $configType, array $params): array
    {
        if (!isset(self::KNOWN_KEYS[$configType])) {
            return []; // Unknown config type, skip check
        }

        $knownKeys = self::KNOWN_KEYS[$configType];
        $actualKeys = array_keys($params);
        $unknownKeys = array_diff($actualKeys, $knownKeys);

        // Filter out empty values (some KVS versions may have unset keys)
        $unknownKeys = array_filter($unknownKeys, static fn($key) => $key !== '');

        if ($unknownKeys !== []) {
            $this->addWarning(sprintf(
                'Unknown %s option(s) detected: %s. KVS may have added new features - consider updating kvs-cli.',
                $configType,
                implode(', ', $unknownKeys)
            ));
        }

        return array_values($unknownKeys);
    }

    /**
     * Apply backwards compatibility mappings.
     *
     * Transforms old key names to new key names.
     *
     * @param string $configType The config type
     * @param array<string, mixed> $params The parameters (modified in place)
     * @return list<string> List of keys that were remapped
     */
    public function applyBackwardsCompat(string $configType, array &$params): array
    {
        $mappings = self::$keyMappings[$configType] ?? [];
        if ($mappings === []) {
            return [];
        }

        $remapped = [];
        foreach ($mappings as $oldKey => $newKey) {
            if (array_key_exists($oldKey, $params) && !array_key_exists($newKey, $params)) {
                $params[$newKey] = $params[$oldKey];
                unset($params[$oldKey]);
                $remapped[] = "$oldKey -> $newKey";
            }
        }

        if ($remapped !== []) {
            $this->addWarning(sprintf(
                'Applied backwards compatibility mappings: %s',
                implode(', ', $remapped)
            ));
        }

        return $remapped;
    }

    /**
     * Check for deprecated keys and warn.
     *
     * @param string $configType The config type
     * @param array<string, mixed> $params The parameters to check
     * @return list<string> List of deprecated keys found
     */
    public function checkDeprecatedKeys(string $configType, array $params): array
    {
        $deprecations = self::$deprecatedKeys[$configType] ?? [];
        if ($deprecations === []) {
            return [];
        }

        $deprecated = [];
        foreach ($deprecations as $key => $message) {
            if (array_key_exists($key, $params)) {
                $deprecated[] = $key;
                $this->addWarning(sprintf('The %s option is deprecated. %s', $key, $message));
            }
        }

        return $deprecated;
    }

    /**
     * Run all compatibility checks.
     *
     * @param string $configType The config type
     * @param array<string, mixed> $params The parameters (may be modified for backwards compat)
     * @return array{version_ok: bool, unknown_keys: list<string>, remapped: list<string>, deprecated: list<string>}
     */
    public function runAllChecks(string $configType, array &$params): array
    {
        $versionOk = $this->checkVersion();
        $remapped = $this->applyBackwardsCompat($configType, $params);
        $unknownKeys = $this->checkUnknownKeys($configType, $params);
        $deprecated = $this->checkDeprecatedKeys($configType, $params);

        return [
            'version_ok' => $versionOk,
            'unknown_keys' => $unknownKeys,
            'remapped' => $remapped,
            'deprecated' => $deprecated,
        ];
    }

    /**
     * Add a warning message.
     */
    private function addWarning(string $message): void
    {
        $this->warnings[] = $message;

        if ($this->io !== null) {
            $this->io->warning($message);
        }
    }

    /**
     * Get all warnings (useful when no SymfonyStyle is provided).
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if any warnings were generated.
     */
    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * Get the KVS version being checked.
     */
    public function getKvsVersion(): string
    {
        return $this->kvsVersion;
    }

    /**
     * Get supported version range as a string.
     */
    public static function getSupportedVersionRange(): string
    {
        return sprintf('%s - %s', self::MIN_VERSION, self::getMaxTestedVersionDisplay());
    }

    /**
     * Check if a specific config type is known.
     */
    public static function isKnownConfigType(string $configType): bool
    {
        return isset(self::KNOWN_KEYS[$configType]);
    }

    /**
     * Get all known keys for a config type.
     *
     * @return list<string>
     */
    public static function getKnownKeys(string $configType): array
    {
        return self::KNOWN_KEYS[$configType] ?? [];
    }

    /**
     * Register additional known keys for a config type.
     * Useful for plugins or extensions.
     *
     * Note: This modifies static state, use with caution.
     *
     * @param string $configType The config type
     * @param list<string> $keys Additional keys to register
     */
    public static function registerKnownKeys(string $configType, array $keys): void
    {
        // This would require making KNOWN_KEYS non-const
        // For now, this is a placeholder for future extensibility
    }
}
