<?php

declare(strict_types=1);

namespace KVS\CLI\Service;

/**
 * Manages temporary files with automatic cleanup.
 *
 * Files created through this service are automatically deleted when the PHP process ends,
 * even if an exception is thrown. This prevents temp file accumulation and potential
 * security issues from leftover files containing sensitive data.
 */
final class TempFileManager
{
    /** @var array<string> Registered temp files for cleanup */
    private static array $registeredFiles = [];

    /** @var array<string> Registered temp directories for cleanup */
    private static array $registeredDirs = [];

    /** @var bool Whether shutdown handler is registered */
    private static bool $shutdownRegistered = false;

    /**
     * Create a temporary file path with automatic cleanup.
     *
     * The file is NOT created - only the path is generated and registered for cleanup.
     * Use createWithContent() if you need to write content immediately.
     *
     * @param string $prefix Prefix for the temp file name
     * @param string $suffix Suffix/extension for the temp file
     * @return string The temporary file path
     */
    public static function create(string $prefix = 'kvs_', string $suffix = ''): string
    {
        self::ensureShutdownHandler();

        $path = sys_get_temp_dir() . '/' . $prefix . uniqid('', true) . $suffix;
        self::$registeredFiles[] = $path;

        return $path;
    }

    /**
     * Create a temporary file with content and secure permissions.
     *
     * Creates the file with 0600 permissions (owner read/write only) before writing content.
     * Automatically cleaned up when the PHP process ends.
     *
     * @param string $content The content to write
     * @param string $prefix Prefix for the temp file name
     * @param string $suffix Suffix/extension for the temp file
     * @return string The temporary file path
     * @throws \RuntimeException If file creation fails
     */
    public static function createWithContent(string $content, string $prefix = 'kvs_', string $suffix = ''): string
    {
        $path = self::create($prefix, $suffix);

        // Create file with restrictive permissions BEFORE writing content
        if (touch($path) === false) {
            throw new \RuntimeException("Failed to create temporary file: $path");
        }

        if (chmod($path, 0600) === false) {
            @unlink($path);
            throw new \RuntimeException("Failed to set permissions on temporary file: $path");
        }

        if (file_put_contents($path, $content) === false) {
            @unlink($path);
            throw new \RuntimeException("Failed to write to temporary file: $path");
        }

        return $path;
    }

    /**
     * Create a temporary directory with automatic cleanup.
     *
     * @param string $prefix Prefix for the temp directory name
     * @return string The temporary directory path
     * @throws \RuntimeException If directory creation fails
     */
    public static function createDirectory(string $prefix = 'kvs_'): string
    {
        self::ensureShutdownHandler();

        $path = sys_get_temp_dir() . '/' . $prefix . uniqid('', true);

        if (!mkdir($path, 0700, true)) {
            throw new \RuntimeException("Failed to create temporary directory: $path");
        }

        self::$registeredDirs[] = $path;

        return $path;
    }

    /**
     * Manually cleanup a specific temp file before shutdown.
     *
     * @param string $path The file path to cleanup
     */
    public static function cleanup(string $path): void
    {
        if (file_exists($path)) {
            @unlink($path);
        }

        self::$registeredFiles = array_filter(
            self::$registeredFiles,
            fn(string $file): bool => $file !== $path
        );
    }

    /**
     * Cleanup all registered temp files and directories.
     *
     * Called automatically at shutdown, but can be called manually if needed.
     */
    public static function cleanupAll(): void
    {
        // Cleanup files
        foreach (self::$registeredFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        self::$registeredFiles = [];

        // Cleanup directories (in reverse order to handle nested dirs)
        foreach (array_reverse(self::$registeredDirs) as $dir) {
            self::removeDirectory($dir);
        }
        self::$registeredDirs = [];
    }

    /**
     * Register the shutdown handler if not already registered.
     */
    private static function ensureShutdownHandler(): void
    {
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'cleanupAll']);
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Directory path to remove
     */
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Get count of currently registered temp files (for testing/debugging).
     *
     * @return int Number of registered temp files
     */
    public static function getRegisteredCount(): int
    {
        return count(self::$registeredFiles) + count(self::$registeredDirs);
    }

    /**
     * Reset the manager state (primarily for testing).
     */
    public static function reset(): void
    {
        self::cleanupAll();
        self::$shutdownRegistered = false;
    }
}
