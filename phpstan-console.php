<?php

/**
 * PHPStan Console Application Loader
 *
 * This file provides a fully configured Application instance to PHPStan
 * for analyzing Symfony Console command arguments and options types.
 *
 * @see https://github.com/phpstan/phpstan-symfony#console-command-analysis
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use KVS\CLI\Application;
use KVS\CLI\Config\Configuration;

// Create a mock Configuration for static analysis
// This doesn't require a real KVS installation
$mockConfig = new class extends Configuration {
    public function __construct()
    {
        // Skip parent constructor to avoid KVS path detection
    }

    public function getKvsPath(): string
    {
        return '/mock/kvs/path';
    }

    public function getAdminPath(): string
    {
        return '/mock/kvs/path/admin';
    }

    public function getContentPath(): string
    {
        return '/mock/content';
    }

    public function getVideoSourcesPath(): string
    {
        return '/mock/content/videos/sources';
    }

    public function getVideoScreenshotsPath(): string
    {
        return '/mock/content/videos/screenshots';
    }

    public function getAlbumSourcesPath(): string
    {
        return '/mock/content/albums/sources';
    }

    /** @return array<string, string> */
    public function getDatabaseConfig(): array
    {
        return [
            'host' => 'localhost',
            'user' => 'mock',
            'password' => 'mock',
            'database' => 'mock',
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function isKvsInstalled(): bool
    {
        return true;
    }

    public function getTablePrefix(): string
    {
        return 'ktvs_';
    }

    public function getKvsVersion(): string
    {
        return '6.3.2';
    }
};

// Create application and register all commands
$application = new Application();
$application->registerKvsCommands($mockConfig);

return $application;
