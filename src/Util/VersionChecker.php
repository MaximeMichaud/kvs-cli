<?php

declare(strict_types=1);

namespace KVS\CLI\Util;

use KVS\CLI\Application;

/**
 * Checks if the current KVS CLI version is up to date with GitHub releases.
 */
class VersionChecker
{
    private const GITHUB_API_URL = 'https://api.github.com/repos/MaximeMichaud/kvs-cli/releases/latest';
    private const CACHE_FILE = '/tmp/kvs-cli-version-check.json';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Check if current version is the latest.
     *
     * @return array{is_latest: bool, current: string, latest: string|null, error: string|null}
     */
    public function check(): array
    {
        /** @var string $current */
        $current = Application::VERSION;

        // Try to get from cache first
        $cached = $this->getFromCache();
        if ($cached !== null) {
            return [
                'is_latest' => $cached['is_latest'],
                'current' => $current,
                'latest' => $cached['latest'],
                'error' => null,
            ];
        }

        // Fetch latest version from GitHub
        $latest = $this->fetchLatestVersion();

        if ($latest === null) {
            // Network error or API issue - don't block the user
            return [
                'is_latest' => true, // Assume latest to avoid blocking
                'current' => $current,
                'latest' => null,
                'error' => 'Could not check for updates',
            ];
        }

        $isLatest = $this->compareVersions($current, $latest) >= 0;

        // Cache the result
        $this->saveToCache($isLatest, $latest);

        return [
            'is_latest' => $isLatest,
            'current' => $current,
            'latest' => $latest,
            'error' => null,
        ];
    }

    /**
     * Fetch latest version from GitHub API.
     */
    private function fetchLatestVersion(): ?string
    {
        /** @var string $version */
        $version = Application::VERSION;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: KVS-CLI/' . $version,
                    'Accept: application/vnd.github.v3+json',
                ],
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::GITHUB_API_URL, false, $context);

        if ($response === false) {
            return null;
        }

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data) || !isset($data['tag_name'])) {
                return null;
            }

            $tagName = $data['tag_name'];
            if (!is_string($tagName)) {
                return null;
            }

            // Remove 'v' prefix if present (v1.3.0 -> 1.3.0)
            return ltrim($tagName, 'v');
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Compare two semantic versions.
     *
     * @return int -1 if v1 < v2, 0 if equal, 1 if v1 > v2
     */
    private function compareVersions(string $v1, string $v2): int
    {
        return version_compare($v1, $v2);
    }

    /**
     * Get cached version check result.
     *
     * @return array{is_latest: bool, latest: string}|null
     */
    private function getFromCache(): ?array
    {
        if (!file_exists(self::CACHE_FILE)) {
            return null;
        }

        $content = @file_get_contents(self::CACHE_FILE);
        if ($content === false) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return null;
            }

            $timestamp = $data['timestamp'] ?? 0;
            $isLatest = $data['is_latest'] ?? true;
            $latest = $data['latest'] ?? null;

            // Check if cache is expired
            if (!is_int($timestamp) || (time() - $timestamp) > self::CACHE_TTL) {
                return null;
            }

            if (!is_bool($isLatest) || !is_string($latest)) {
                return null;
            }

            return [
                'is_latest' => $isLatest,
                'latest' => $latest,
            ];
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Save version check result to cache.
     */
    private function saveToCache(bool $isLatest, string $latest): void
    {
        $data = [
            'timestamp' => time(),
            'is_latest' => $isLatest,
            'latest' => $latest,
        ];

        @file_put_contents(
            self::CACHE_FILE,
            json_encode($data, JSON_THROW_ON_ERROR),
            LOCK_EX
        );
    }

    /**
     * Clear the version check cache.
     */
    public function clearCache(): void
    {
        if (file_exists(self::CACHE_FILE)) {
            @unlink(self::CACHE_FILE);
        }
    }
}
