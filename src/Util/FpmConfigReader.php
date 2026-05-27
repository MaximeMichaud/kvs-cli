<?php

declare(strict_types=1);

namespace KVS\CLI\Util;

use KVS\CLI\Config\Configuration;
use KVS\CLI\Docker\DockerDetector;

/**
 * Retrieves real PHP-FPM configuration values
 *
 * CLI and FPM use separate php.ini files. This class handles both:
 * - Docker mode: Uses docker exec to get values from PHP-FPM container
 * - Non-Docker: Creates temp PHP file, fetches via HTTP, then deletes
 *
 * Security (HTTP mode):
 * - Random 32-char filename (impossible to guess)
 * - Random token required in query string
 * - File exists for < 1 second
 * - Fallback to CLI values if HTTP fails
 */
class FpmConfigReader
{
    private Configuration $config;
    private ?DockerDetector $docker = null;
    private ?string $lastError = null;
    private bool $usedFallback = false;

    public function __construct(Configuration $config, ?DockerDetector $docker = null)
    {
        $this->config = $config;
        $this->docker = $docker;
    }

    /**
     * Get PHP-FPM configuration values
     *
     * @return array{
     *     upload_max_filesize: string,
     *     post_max_size: string,
     *     memory_limit: string,
     *     max_execution_time: int,
     *     max_input_vars: int,
     *     display_errors: string,
     *     server_software: string,
     *     opcache: array{enable: bool, memory_consumption: int, interned_strings_buffer: int}|null,
     *     source: string
     * }
     */
    public function getConfig(): array
    {
        $this->lastError = null;
        $this->usedFallback = false;

        // Try Docker first if available
        if ($this->docker !== null) {
            $dockerConfig = $this->fetchDockerConfig();
            if ($dockerConfig !== null) {
                $dockerConfig['source'] = 'fpm-docker';
                return $dockerConfig;
            }
        }

        // Try HTTP temp file method
        $fpmConfig = $this->fetchFpmConfig();
        if ($fpmConfig !== null) {
            $fpmConfig['source'] = 'fpm';
            return $fpmConfig;
        }

        // Fallback to CLI values
        $this->usedFallback = true;
        return $this->getCliConfig();
    }

    /**
     * Fetch config from Docker container via docker exec
     *
     * @return array{
     *     upload_max_filesize: string,
     *     post_max_size: string,
     *     memory_limit: string,
     *     max_execution_time: int,
     *     max_input_vars: int,
     *     display_errors: string,
     *     server_software: string,
     *     opcache: array{enable: bool, memory_consumption: int, interned_strings_buffer: int}|null
     * }|null
     */
    private function fetchDockerConfig(): ?array
    {
        if ($this->docker === null) {
            return null;
        }

        $uploadMax = $this->docker->getPhpIni('upload_max_filesize');
        $postMax = $this->docker->getPhpIni('post_max_size');
        $memLimit = $this->docker->getPhpIni('memory_limit');
        $maxExec = $this->docker->getPhpIni('max_execution_time');
        $maxInputVars = $this->docker->getPhpIni('max_input_vars');
        $displayErrors = $this->docker->getPhpIni('display_errors');

        // If we can't get basic settings, Docker isn't working
        if ($uploadMax === null || $memLimit === null) {
            $this->lastError = 'Failed to get PHP settings from Docker container';
            return null;
        }

        // Get OPcache config
        $opcache = null;
        $opcacheEnabled = $this->docker->getPhpIni('opcache.enable');
        if ($opcacheEnabled !== null) {
            $opcacheMemory = $this->docker->getPhpIni('opcache.memory_consumption');
            $opcacheStrings = $this->docker->getPhpIni('opcache.interned_strings_buffer');
            $opcache = [
                'enable' => strtolower($opcacheEnabled) === 'on',
                'memory_consumption' => $opcacheMemory !== null ? (int) $opcacheMemory : 0,
                'interned_strings_buffer' => $opcacheStrings !== null ? (int) $opcacheStrings : 0,
            ];
        }

        return [
            'upload_max_filesize' => $uploadMax,
            'post_max_size' => $postMax ?? '8M',
            'memory_limit' => $memLimit,
            'max_execution_time' => $maxExec !== null ? (int) $maxExec : 0,
            'max_input_vars' => $maxInputVars !== null ? (int) $maxInputVars : 1000,
            'display_errors' => $displayErrors ?? '0',
            'server_software' => $this->detectDockerWebServerSoftware()
                ?? $this->detectHttpServerSoftware()
                ?? 'Unknown',
            'opcache' => $opcache,
        ];
    }

    /**
     * Check if last getConfig() call used CLI fallback
     */
    public function usedFallback(): bool
    {
        return $this->usedFallback;
    }

    /**
     * Get last error message if any
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Fetch config via HTTP to KVS installation
     *
     * @return array{
     *     upload_max_filesize: string,
     *     post_max_size: string,
     *     memory_limit: string,
     *     max_execution_time: int,
     *     max_input_vars: int,
     *     display_errors: string,
     *     server_software: string,
     *     opcache: array{enable: bool, memory_consumption: int, interned_strings_buffer: int}|null
     * }|null
     */
    private function fetchFpmConfig(): ?array
    {
        $kvsPath = $this->config->getKvsPath();
        $projectUrl = $this->config->get('project_url');

        if (!is_string($projectUrl) || $projectUrl === '') {
            $this->lastError = 'project_url not configured in KVS';
            return null;
        }

        // Generate secure random filename and token
        try {
            $randomBytes = random_bytes(16);
            $filename = 'kvs_cli_' . bin2hex($randomBytes) . '.php';
            $token = bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            $this->lastError = 'Failed to generate random token: ' . $e->getMessage();
            return null;
        }

        $filepath = $kvsPath . '/' . $filename;

        // Create temp PHP file
        $phpCode = $this->generatePhpCode($token);
        if (file_put_contents($filepath, $phpCode) === false) {
            $this->lastError = 'Failed to write temp file: ' . $filepath;
            return null;
        }

        // Make sure file is readable by web server
        chmod($filepath, 0644);

        try {
            // Fetch via HTTP
            $url = rtrim($projectUrl, '/') . '/' . $filename . '?t=' . $token;
            $response = $this->httpGet($url);

            if ($response === null) {
                return null;
            }

            // Parse JSON response
            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                $this->lastError = 'Invalid JSON response from FPM';
                return null;
            }

            /** @var array<string, mixed> $data */
            $data = $decoded;
            return $this->normalizeResponse($data);
        } finally {
            // Always delete temp file
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }

    /**
     * Generate the temporary PHP file content
     */
    private function generatePhpCode(string $token): string
    {
        return <<<PHP
<?php
// KVS CLI temporary file - auto-deleted after use
if ((\$_GET['t'] ?? '') !== '{$token}') {
    http_response_code(403);
    echo 'Forbidden';
    return;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');

\$result = [
    'upload_max_filesize' => ini_get('upload_max_filesize') ?: '2M',
    'post_max_size' => ini_get('post_max_size') ?: '8M',
    'memory_limit' => ini_get('memory_limit') ?: '128M',
    'max_execution_time' => (int) ini_get('max_execution_time'),
    'max_input_vars' => (int) ini_get('max_input_vars'),
    'display_errors' => ini_get('display_errors') ?: '0',
    'server_software' => \$_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'opcache' => null,
];

if (function_exists('opcache_get_configuration')) {
    \$oc = opcache_get_configuration();
    if (is_array(\$oc) && isset(\$oc['directives'])) {
        \$d = \$oc['directives'];
        \$result['opcache'] = [
            'enable' => (bool) (\$d['opcache.enable'] ?? false),
            'memory_consumption' => (int) (\$d['opcache.memory_consumption'] ?? 0),
            'interned_strings_buffer' => (int) (\$d['opcache.interned_strings_buffer'] ?? 0),
        ];
    }
}

echo json_encode(\$result);
PHP;
    }

    /**
     * Make HTTP GET request
     */
    private function httpGet(string $url): ?string
    {
        // Try cURL first
        if (function_exists('curl_init') && $url !== '') {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_USERAGENT, 'KVS-CLI/1.0');
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($response === false || $httpCode !== 200) {
                $this->lastError = "HTTP request failed (code {$httpCode}): {$error}";
                return null;
            }

            return is_string($response) ? $response : null;
        }

        // Fallback to file_get_contents
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'KVS-CLI/1.0',
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->lastError = 'HTTP request failed (file_get_contents)';
            return null;
        }

        return $response;
    }

    /**
     * Normalize the response data
     *
     * @param array<string, mixed> $data
     * @return array{
     *     upload_max_filesize: string,
     *     post_max_size: string,
     *     memory_limit: string,
     *     max_execution_time: int,
     *     max_input_vars: int,
     *     display_errors: string,
     *     server_software: string,
     *     opcache: array{enable: bool, memory_consumption: int, interned_strings_buffer: int}|null
     * }
     */
    private function normalizeResponse(array $data): array
    {
        $opcache = null;
        if (isset($data['opcache']) && is_array($data['opcache'])) {
            $oc = $data['opcache'];
            $opcache = [
                'enable' => isset($oc['enable']) && $oc['enable'] === true,
                'memory_consumption' => isset($oc['memory_consumption']) && is_int($oc['memory_consumption'])
                    ? $oc['memory_consumption'] : 0,
                'interned_strings_buffer' => isset($oc['interned_strings_buffer']) && is_int($oc['interned_strings_buffer'])
                    ? $oc['interned_strings_buffer'] : 0,
            ];
        }

        $maxExec = $data['max_execution_time'] ?? 30;
        $maxInputVars = $data['max_input_vars'] ?? 1000;

        return [
            'upload_max_filesize' => is_string($data['upload_max_filesize'] ?? null)
                ? $data['upload_max_filesize'] : '2M',
            'post_max_size' => is_string($data['post_max_size'] ?? null)
                ? $data['post_max_size'] : '8M',
            'memory_limit' => is_string($data['memory_limit'] ?? null)
                ? $data['memory_limit'] : '128M',
            'max_execution_time' => is_int($maxExec) ? $maxExec : 30,
            'max_input_vars' => is_int($maxInputVars) ? $maxInputVars : 1000,
            'display_errors' => is_string($data['display_errors'] ?? null) ? $data['display_errors'] : '0',
            'server_software' => $this->normalizeServerSoftware($data['server_software'] ?? null),
            'opcache' => $opcache,
        ];
    }

    /**
     * Get CLI config as fallback
     *
     * @return array{
     *     upload_max_filesize: string,
     *     post_max_size: string,
     *     memory_limit: string,
     *     max_execution_time: int,
     *     max_input_vars: int,
     *     display_errors: string,
     *     server_software: string,
     *     opcache: array{enable: bool, memory_consumption: int, interned_strings_buffer: int}|null,
     *     source: string
     * }
     */
    private function getCliConfig(): array
    {
        $opcache = null;
        if (function_exists('opcache_get_configuration')) {
            $oc = opcache_get_configuration();
            if (is_array($oc) && isset($oc['directives']) && is_array($oc['directives'])) {
                $d = $oc['directives'];
                $memConsumption = $d['opcache.memory_consumption'] ?? 0;
                $stringsBuffer = $d['opcache.interned_strings_buffer'] ?? 0;
                $opcache = [
                    'enable' => isset($d['opcache.enable']) && $d['opcache.enable'] === true,
                    'memory_consumption' => is_int($memConsumption) ? $memConsumption : 0,
                    'interned_strings_buffer' => is_int($stringsBuffer) ? $stringsBuffer : 0,
                ];
            }
        }

        $uploadMax = ini_get('upload_max_filesize');
        $postMax = ini_get('post_max_size');
        $memLimit = ini_get('memory_limit');
        $displayErrors = ini_get('display_errors');

        return [
            'upload_max_filesize' => is_string($uploadMax) && $uploadMax !== '' ? $uploadMax : '2M',
            'post_max_size' => is_string($postMax) && $postMax !== '' ? $postMax : '8M',
            /** @phpstan-ignore notIdentical.alwaysTrue */
            'memory_limit' => $memLimit !== '' && $memLimit !== false ? $memLimit : '128M',
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'max_input_vars' => (int) ini_get('max_input_vars'),
            'display_errors' => is_string($displayErrors) && $displayErrors !== '' ? $displayErrors : '0',
            'server_software' => $this->normalizeServerSoftware($_SERVER['SERVER_SOFTWARE'] ?? 'CLI'),
            'opcache' => $opcache,
            'source' => 'cli',
        ];
    }

    private function detectDockerWebServerSoftware(): ?string
    {
        if ($this->docker === null || !$this->docker->isRunning('nginx')) {
            return null;
        }

        $output = $this->docker->exec('nginx', 'nginx -v 2>&1');
        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        if (preg_match('/nginx version:\s*(.+)/i', $output, $matches) !== 1) {
            return null;
        }

        return $this->normalizeServerSoftware($matches[1]);
    }

    private function detectHttpServerSoftware(): ?string
    {
        $projectUrl = $this->config->get('project_url');
        if (!is_string($projectUrl) || trim($projectUrl) === '') {
            return null;
        }

        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init(rtrim($projectUrl, '/') . '/');
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'KVS-CLI/1.0',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!is_string($response)) {
            return null;
        }

        if (preg_match('/^server:\s*([^\r\n]+)/im', $response, $matches) !== 1) {
            return null;
        }

        return $this->normalizeServerSoftware($matches[1]);
    }

    private function normalizeServerSoftware(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return 'Unknown';
        }

        $serverSoftware = trim((string) $value);

        return $serverSoftware !== '' ? $serverSoftware : 'Unknown';
    }
}
