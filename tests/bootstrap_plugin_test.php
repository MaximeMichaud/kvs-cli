<?php

/**
 * Bootstrap file for password_hash plugin tests
 *
 * This file defines stub functions for KVS dependencies so we can test
 * the plugin logic without requiring a full KVS installation.
 */

// Track SQL queries for testing
global $test_sql_queries, $test_sql_results;
$test_sql_queries = [];
$test_sql_results = [];

/**
 * Stub for sql_update() - KVS database update function
 */
if (!function_exists('sql_update')) {
    function sql_update(string $query, ...$params): bool
    {
        global $test_sql_queries;
        $test_sql_queries[] = [
            'type' => 'update',
            'query' => $query,
            'params' => $params
        ];
        return true; // Always succeed in tests
    }
}

/**
 * Stub for sql_pr() - KVS database query function
 */
if (!function_exists('sql_pr')) {
    function sql_pr(string $query, ...$params)
    {
        global $test_sql_queries, $test_sql_results;
        $test_sql_queries[] = [
            'type' => 'query',
            'query' => $query,
            'params' => $params
        ];

        // Return mock result
        if (isset($test_sql_results[count($test_sql_queries) - 1])) {
            return $test_sql_results[count($test_sql_queries) - 1];
        }

        // Default: return empty array
        return [];
    }
}

/**
 * Stub for mr2number() - KVS function to get number from query result
 */
if (!function_exists('mr2number')) {
    function mr2number($result): int
    {
        if (is_array($result) && isset($result[0])) {
            return (int)$result[0];
        }
        if (is_numeric($result)) {
            return (int)$result;
        }
        return 0;
    }
}

/**
 * Stub for mkdir_recursive() - KVS recursive mkdir function
 */
if (!function_exists('mkdir_recursive')) {
    function mkdir_recursive(string $path): bool
    {
        if (!is_dir($path)) {
            return mkdir($path, 0777, true);
        }
        return true;
    }
}

/**
 * Helper to reset test state between tests
 */
function reset_test_state(): void
{
    global $test_sql_queries, $test_sql_results;
    $test_sql_queries = [];
    $test_sql_results = [];
}

/**
 * Helper to set next SQL query result
 */
function set_next_sql_result($result): void
{
    global $test_sql_results;
    $test_sql_results[] = $result;
}

/**
 * Helper to get all SQL queries executed
 */
function get_sql_queries(): array
{
    global $test_sql_queries;
    return $test_sql_queries;
}
