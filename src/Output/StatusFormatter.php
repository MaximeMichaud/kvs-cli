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
            0 => ['text' => 'Disabled', 'color' => 'yellow'],
            1 => ['text' => 'Active', 'color' => 'green'],
            2 => ['text' => 'Error', 'color' => 'red'],
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
            0 => ['text' => 'Disabled', 'color' => 'yellow'],
            1 => ['text' => 'Active', 'color' => 'green'],
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
            0 => ['text' => 'Disabled', 'color' => 'red'],
            1 => ['text' => 'Not Confirmed', 'color' => 'yellow'],
            2 => ['text' => 'Active', 'color' => 'green'],
            3 => ['text' => 'Premium', 'color' => 'cyan'],
            4 => ['text' => 'VIP', 'color' => 'magenta'],
            6 => ['text' => 'Webmaster', 'color' => 'blue'],
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
            0 => ['text' => 'Inactive', 'color' => 'yellow'],
            1 => ['text' => 'Active', 'color' => 'green'],
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
            0 => ['text' => 'Inactive', 'color' => 'yellow'],
            1 => ['text' => 'Active', 'color' => 'green'],
        ];

        return self::format($statusId, $labels, $withColor);
    }

    /**
     * Generic formatter - applies color if requested
     *
     * @param int $statusId Status ID to format
     * @param array $labels Array of status definitions [id => ['text' => '...', 'color' => '...']]
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
