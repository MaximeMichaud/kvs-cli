<?php

/**
 * Utility functions for KVS CLI
 *
 * Inspired by WP-CLI's utils.php - provides reusable helper functions
 * that don't depend on WordPress/KVS code.
 *
 * @package KVS\CLI
 */

namespace KVS\CLI\Utils;

// ============================================================================
// STRING MANIPULATION
// ============================================================================

/**
 * Truncate string with UTF-8 support
 *
 * @param string $string String to truncate
 * @param int $length Max length
 * @param string $suffix Suffix to append (default '...')
 * @return string Truncated string
 */
function truncate(string $string, int $length, string $suffix = '...'): string
{
    if (mb_strlen($string) <= $length) {
        return $string;
    }

    return mb_substr($string, 0, $length - mb_strlen($suffix)) . $suffix;
}

/**
 * Pluralize word based on count (simplified English rules)
 *
 * @param string $word Word to pluralize
 * @param int $count Count
 * @return string Pluralized word
 */
function pluralize(string $word, int $count): string
{
    if ($count === 1) {
        return $word;
    }

    // Basic English rules
    if (preg_match('/(s|x|z|ch|sh)$/i', $word) === 1) {
        return $word . 'es';  // box→boxes, match→matches
    }

    if (preg_match('/[^aeiou]y$/i', $word) === 1) {
        return substr($word, 0, -1) . 'ies';  // category→categories
    }

    return $word . 's';  // video→videos
}

/**
 * Convert verb to past tense (basic rules)
 *
 * @param string $verb Verb in present tense
 * @return string Past tense verb
 */
function past_tense_verb(string $verb): string
{
    // Irregular verbs
    $irregular = [
        'reset' => 'reset',
        'set' => 'set',
        'put' => 'put',
        'cut' => 'cut',
        'hit' => 'hit',
    ];

    if (isset($irregular[$verb])) {
        return $irregular[$verb];
    }

    // Basic rule: add 'ed'
    if (preg_match('/e$/i', $verb) === 1) {
        return $verb . 'd';  // activate→activated
    }

    return $verb . 'ed';  // process→processed
}

/**
 * Escape SQL LIKE pattern (% and _)
 *
 * @param string $text Text to escape
 * @return string Escaped text
 */
function esc_like(string $text): string
{
    return str_replace(['%', '_'], ['\\%', '\\_'], $text);
}

/**
 * Escape CSV value (prevents injection, handles commas/quotes)
 *
 * @param string $value Value to escape
 * @return string Escaped CSV value
 */
function escape_csv_value(string $value): string
{
    // If contains comma, quote, or newline, wrap in quotes and escape existing quotes
    if (preg_match('/[,"\r\n]/', $value) === 1) {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    return $value;
}

// ============================================================================
// ARRAY/DATA MANIPULATION
// ============================================================================

/**
 * Extract specific fields from array or object
 * Based on WP-CLI's pick_fields()
 *
 * @param array<string, mixed>|object $item Item to extract from
 * @param list<string> $fields Fields to pick
 * @return array<string, mixed> Filtered array with only specified fields
 */
function pick_fields(array|object $item, array $fields): array
{
    $result = [];

    foreach ($fields as $field) {
        if (is_object($item) && property_exists($item, $field)) {
            /** @phpstan-ignore-next-line Variable property access is intentional */
            $result[$field] = $item->$field;
        } elseif (is_array($item) && array_key_exists($field, $item)) {
            $result[$field] = $item[$field];
        } else {
            $result[$field] = null;
        }
    }

    return $result;
}

/**
 * Group array of items by field value
 *
 * @param list<array<string, mixed>|object> $items Array of items (arrays or objects)
 * @param string $field Field to group by
 * @return array<string, list<array<string, mixed>|object>> Grouped array [fieldValue => [items]]
 */
function group_by(array $items, string $field): array
{
    /** @var array<string, list<array<string, mixed>|object>> $result */
    $result = [];

    foreach ($items as $item) {
        /** @phpstan-ignore-next-line Variable property access is intentional */
        $keyVal = is_array($item) ? ($item[$field] ?? 'null') : ($item->$field ?? 'null');
        $key = is_scalar($keyVal) ? (string) $keyVal : 'null';
        $result[$key][] = $item;
    }

    return $result;
}

// ============================================================================
// FORMAT/OUTPUT HELPERS
// ============================================================================

/**
 * Format bytes to human-readable size
 *
 * @param int $bytes Bytes
 * @param int $decimals Decimal places (default 2)
 * @return string Formatted size (e.g., "1.5 GB")
 */
function format_bytes(int $bytes, int $decimals = 2): string
{
    if ($bytes === 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $base = 1024;

    $i = (int) floor(log($bytes) / log($base));
    $i = min($i, count($units) - 1);

    return number_format($bytes / pow($base, $i), $decimals) . ' ' . $units[$i];
}

/**
 * Format duration in seconds to human-readable
 *
 * @param int $seconds Duration in seconds
 * @return string Formatted duration (e.g., "1h 30m 45s")
 */
function format_duration(int $seconds): string
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = "{$hours}h";
    }
    if ($minutes > 0) {
        $parts[] = "{$minutes}m";
    }
    if ($secs > 0 || $parts === []) {
        $parts[] = "{$secs}s";
    }

    return implode(' ', $parts);
}

/**
 * Format date with relative time if recent
 *
 * @param string $date Date string (parseable by strtotime)
 * @return string Formatted date with relative time
 */
function format_date(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $diff = time() - $timestamp;

    // Less than 1 minute
    if ($diff < 60) {
        return 'just now';
    }

    // Less than 1 hour
    if ($diff < 3600) {
        $minutes = (int) floor($diff / 60);
        return $minutes . ' ' . pluralize('minute', $minutes) . ' ago';
    }

    // Less than 24 hours
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' ' . pluralize('hour', $hours) . ' ago';
    }

    // Less than 7 days
    if ($diff < 604800) {
        $days = (int) floor($diff / 86400);
        return $days . ' ' . pluralize('day', $days) . ' ago';
    }

    // Full date
    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Report batch operation results (like WP-CLI)
 *
 * Examples:
 *   Success: Activated 8 videos.
 *   Error: Only activated 6 of 10 videos (4 failed).
 *
 * @param string $noun Noun (e.g., 'video', 'user')
 * @param string $verb Verb in present tense (e.g., 'activate', 'delete')
 * @param int $total Total items
 * @param int $successes Successful operations
 * @param int $failures Failed operations (default 0)
 * @param int $skips Skipped operations (default 0)
 * @return array{type: string, message: string}
 */
function report_batch_operation_results(
    string $noun,
    string $verb,
    int $total,
    int $successes,
    int $failures = 0,
    int $skips = 0
): array {
    $plural_noun = pluralize($noun, $total);
    $past_verb = past_tense_verb($verb);

    // All succeeded
    if ($successes === $total) {
        return [
            'type' => 'success',
            'message' => ucfirst($past_verb) . " $successes " . pluralize($noun, $successes) . '.'
        ];
    }

    // Partial success or complete failure
    $parts = [];
    if ($failures > 0) {
        $parts[] = "$failures failed";
    }
    if ($skips > 0) {
        $parts[] = "$skips skipped";
    }

    $detail = $parts !== [] ? ' (' . implode(', ', $parts) . ')' : '';

    return [
        'type' => 'error',
        'message' => "Only $past_verb $successes of $total $plural_noun$detail."
    ];
}

// ============================================================================
// DATABASE HELPERS
// ============================================================================

/**
 * Build WHERE clause from filters array
 *
 * Supports:
 *   - Simple equality: ['status_id' => 1] → "`status_id` = ?"
 *   - NULL values: ['deleted_at' => null] → "`deleted_at` IS NULL"
 *   - IN clause: ['status_id' => [1,2,3]] → "`status_id` IN (?,?,?)"
 *
 * @param array<string, mixed> $filters Associative array ['field' => 'value']
 * @param array<int, mixed> $params Output: parameter array for prepared statement
 * @return string WHERE clause without "WHERE" keyword
 */
function build_where_clause(array $filters, array &$params = []): string
{
    if ($filters === []) {
        return '1=1';
    }

    $conditions = [];

    foreach ($filters as $field => $value) {
        if ($value === null) {
            $conditions[] = "`$field` IS NULL";
        } elseif (is_array($value)) {
            // IN clause
            if ($value === []) {
                // Empty array = no results
                $conditions[] = '0=1';
            } else {
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $conditions[] = "`$field` IN ($placeholders)";
                $params = array_values(array_merge($params, $value));
            }
        } else {
            $conditions[] = "`$field` = ?";
            $params[] = $value;
        }
    }

    return implode(' AND ', $conditions);
}

/**
 * Sanitize ORDER BY clause against whitelist
 *
 * @param string $orderBy ORDER BY string (e.g., "created_at DESC")
 * @param list<string> $allowedFields Whitelist of allowed fields
 * @param string $default Default ORDER BY if invalid
 * @return string Sanitized ORDER BY clause
 */
function sanitize_order_by(
    string $orderBy,
    array $allowedFields,
    string $default = 'id ASC'
): string {
    $parts = preg_split('/\s+/', trim($orderBy));
    if ($parts === false) {
        return $default;
    }
    $field = $parts[0] ?? '';
    $direction = strtoupper($parts[1] ?? 'ASC');

    // Validate field
    if (!in_array($field, $allowedFields, true)) {
        return $default;
    }

    // Validate direction
    if (!in_array($direction, ['ASC', 'DESC'], true)) {
        $direction = 'ASC';
    }

    return "`$field` $direction";
}

// ============================================================================
// PATH/FILE HELPERS
// ============================================================================

/**
 * Check if path is absolute
 *
 * @param string $path Path to check
 * @return bool True if absolute, false if relative
 */
function is_path_absolute(string $path): bool
{
    // Unix: starts with /
    if (str_starts_with($path, '/')) {
        return true;
    }

    // Windows: starts with drive letter (C:\)
    if (preg_match('/^[A-Z]:\\\\/i', $path) === 1) {
        return true;
    }

    return false;
}

/**
 * Normalize path (replace backslashes, remove trailing slash)
 *
 * @param string $path Path to normalize
 * @return string Normalized path
 */
function normalize_path(string $path): string
{
    // Convert backslashes to forward slashes
    $path = str_replace('\\', '/', $path);

    // Remove trailing slash (except for root /)
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    return $path;
}

/**
 * Add trailing slash if missing
 *
 * @param string $string String to modify
 * @return string String with trailing slash
 */
function trailingslashit(string $string): string
{
    return rtrim($string, '/\\') . '/';
}

// ============================================================================
// VALIDATION HELPERS
// ============================================================================

/**
 * Check if string is valid JSON
 *
 * @param string $string String to check
 * @return bool True if valid JSON, false otherwise
 */
function is_json(string $string): bool
{
    if ($string === '') {
        return false;
    }

    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

/**
 * Get flag value with support for negation (--no-flag)
 * Based on WP-CLI's get_flag_value()
 *
 * Examples:
 *   get_flag_value(['verbose' => true], 'verbose', false) → true
 *   get_flag_value(['no-verbose' => true], 'verbose', true) → false
 *   get_flag_value([], 'verbose', false) → false
 *
 * @param array<string, mixed> $assoc_args Associative args from command
 * @param string $flag Flag name
 * @param mixed $default Default value if flag not present
 * @return mixed Flag value or default
 */
function get_flag_value(array $assoc_args, string $flag, mixed $default = null): mixed
{
    // Check for explicit flag
    if (isset($assoc_args[$flag])) {
        return $assoc_args[$flag];
    }

    // Check for negation (--no-flag)
    $negation = 'no-' . $flag;
    if (isset($assoc_args[$negation])) {
        return $assoc_args[$negation] !== true;
    }

    return $default;
}
