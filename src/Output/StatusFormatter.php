<?php

namespace KVS\CLI\Output;

/**
 * StatusFormatter - Centralized status formatting for KVS entities
 *
 * Provides consistent status label formatting with colors across all commands.
 * Each entity type (video, album, user, etc.) has its own status codes.
 */
class StatusFormatter
{
    // Video status constants
    public const VIDEO_DISABLED = 0;
    public const VIDEO_ACTIVE = 1;
    public const VIDEO_ERROR = 2;

    // Album status constants
    public const ALBUM_DISABLED = 0;
    public const ALBUM_ACTIVE = 1;

    // User status constants
    public const USER_DISABLED = 0;
    public const USER_NOT_CONFIRMED = 1;
    public const USER_ACTIVE = 2;
    public const USER_PREMIUM = 3;
    public const USER_VIP = 4;
    public const USER_WEBMASTER = 6;

    // Category/Tag status constants
    public const CATEGORY_INACTIVE = 0;
    public const CATEGORY_ACTIVE = 1;
    public const TAG_INACTIVE = 0;
    public const TAG_ACTIVE = 1;

    // Model status constants
    public const MODEL_DISABLED = 0;
    public const MODEL_ACTIVE = 1;

    // DVD status constants
    public const DVD_DISABLED = 0;
    public const DVD_ACTIVE = 1;

    // Playlist status constants
    public const PLAYLIST_DISABLED = 0;
    public const PLAYLIST_ACTIVE = 1;

    // Video format status constants
    public const FORMAT_DISABLED = 0;
    public const FORMAT_ACTIVE = 1;
    public const FORMAT_PROCESSING = 2;

    // Background task status constants
    public const TASK_PENDING = 0;
    public const TASK_PROCESSING = 1;
    public const TASK_FAILED = 2;
    public const TASK_COMPLETED = 3;
    public const TASK_DELETED = 4;

    // Server status constants
    public const SERVER_DISABLED = 0;
    public const SERVER_ACTIVE = 1;

    // Server streaming type constants
    public const SERVER_STREAMING_NGINX = 0;
    public const SERVER_STREAMING_APACHE = 1;
    public const SERVER_STREAMING_CDN = 4;
    public const SERVER_STREAMING_BACKUP = 5;

    // Server connection type constants
    public const SERVER_CONNECTION_LOCAL = 0;
    public const SERVER_CONNECTION_MOUNT = 1;
    public const SERVER_CONNECTION_FTP = 2;
    public const SERVER_CONNECTION_S3 = 3;

    /**
     * Get formatted status label for videos
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function video(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::VIDEO_DISABLED => ['text' => 'Disabled', 'color' => 'yellow'],
            self::VIDEO_ACTIVE => ['text' => 'Active', 'color' => 'green'],
            self::VIDEO_ERROR => ['text' => 'Error', 'color' => 'red'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted status label for albums
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function album(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::ALBUM_DISABLED => ['text' => 'Disabled', 'color' => 'yellow'],
            self::ALBUM_ACTIVE => ['text' => 'Active', 'color' => 'green'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted status label for users
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function user(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::USER_DISABLED => ['text' => 'Disabled', 'color' => 'red'],
            self::USER_NOT_CONFIRMED => ['text' => 'Not Confirmed', 'color' => 'yellow'],
            self::USER_ACTIVE => ['text' => 'Active', 'color' => 'green'],
            self::USER_PREMIUM => ['text' => 'Premium', 'color' => 'cyan'],
            self::USER_VIP => ['text' => 'VIP', 'color' => 'magenta'],
            self::USER_WEBMASTER => ['text' => 'Webmaster', 'color' => 'blue'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted status label for categories
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function category(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::CATEGORY_INACTIVE => ['text' => 'Inactive', 'color' => 'yellow'],
            self::CATEGORY_ACTIVE => ['text' => 'Active', 'color' => 'green'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted status label for tags
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function tag(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::TAG_INACTIVE => ['text' => 'Inactive', 'color' => 'yellow'],
            self::TAG_ACTIVE => ['text' => 'Active', 'color' => 'green'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted status label for models (performers)
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function model(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::MODEL_DISABLED => ['text' => 'Disabled', 'color' => 'yellow'],
            self::MODEL_ACTIVE => ['text' => 'Active', 'color' => 'green'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted status label for DVDs (channels/series)
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function dvd(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::DVD_DISABLED => ['text' => 'Disabled', 'color' => 'yellow'],
            self::DVD_ACTIVE => ['text' => 'Active', 'color' => 'green'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted status label for playlists
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function playlist(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::PLAYLIST_DISABLED => ['text' => 'Disabled', 'color' => 'yellow'],
            self::PLAYLIST_ACTIVE => ['text' => 'Active', 'color' => 'green'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted status label for video formats
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function videoFormat(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::FORMAT_DISABLED => ['text' => 'Disabled', 'color' => 'yellow'],
            self::FORMAT_ACTIVE => ['text' => 'Active', 'color' => 'green'],
            self::FORMAT_PROCESSING => ['text' => 'Processing', 'color' => 'cyan'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted status label for background tasks
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function task(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::TASK_PENDING => ['text' => 'Pending', 'color' => 'yellow'],
            self::TASK_PROCESSING => ['text' => 'Processing', 'color' => 'cyan'],
            self::TASK_FAILED => ['text' => 'Failed', 'color' => 'red'],
            self::TASK_COMPLETED => ['text' => 'Completed', 'color' => 'green'],
            self::TASK_DELETED => ['text' => 'Deleted', 'color' => 'gray'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted status label for servers
     *
     * @param int $statusId Status ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted status label
     */
    public static function server(int $statusId, bool $withColor = true): string
    {
        $labels = [
            self::SERVER_DISABLED => ['text' => 'Disabled', 'color' => 'yellow'],
            self::SERVER_ACTIVE => ['text' => 'Active', 'color' => 'green'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Get formatted streaming type label for servers
     *
     * @param int $typeId Streaming type ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted type label
     */
    public static function serverStreaming(int $typeId, bool $withColor = true): string
    {
        $labels = [
            self::SERVER_STREAMING_NGINX => ['text' => 'Nginx', 'color' => 'cyan'],
            self::SERVER_STREAMING_APACHE => ['text' => 'Apache', 'color' => 'cyan'],
            self::SERVER_STREAMING_CDN => ['text' => 'CDN', 'color' => 'magenta'],
            self::SERVER_STREAMING_BACKUP => ['text' => 'Backup', 'color' => 'yellow'],
        ];

        return self::format($typeId, $labels, $withColor);
    }

    /**
     * Get formatted connection type label for servers
     *
     * @param int $typeId Connection type ID from database
     * @param bool $withColor Include color formatting (default: true)
     * @return string Formatted type label
     */
    public static function serverConnection(int $typeId, bool $withColor = true): string
    {
        $labels = [
            self::SERVER_CONNECTION_LOCAL => ['text' => 'Local', 'color' => 'green'],
            self::SERVER_CONNECTION_MOUNT => ['text' => 'Mount', 'color' => 'cyan'],
            self::SERVER_CONNECTION_FTP => ['text' => 'FTP', 'color' => 'yellow'],
            self::SERVER_CONNECTION_S3 => ['text' => 'S3', 'color' => 'magenta'],
        ];

        return self::format($typeId, $labels, $withColor);
    }

    /**
     * Generic formatter - applies color if requested
     *
     * @param int $statusId Status ID to format
     * @param array<int, array{text: string, color: string}> $labels Array of status definitions
     * @param bool $withColor Include Symfony Console color tags
     * @return string Formatted status string
     */
    private static function format(int $statusId, array $labels, bool $withColor): string
    {
        if (!isset($labels[$statusId])) {
            return $withColor ? '<fg=gray>Unknown</>' : 'Unknown';
        }

        $label = $labels[$statusId];

        if ($withColor) {
            return "<fg={$label['color']}>{$label['text']}</>";
        }

        return $label['text'];
    }
}
