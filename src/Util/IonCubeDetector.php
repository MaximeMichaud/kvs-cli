<?php

declare(strict_types=1);

namespace KVS\CLI\Util;

use KVS\CLI\Config\Configuration;
use KVS\CLI\Docker\DockerDetector;

/**
 * IonCube Loader detection and compatibility checks
 *
 * Detects whether KVS installation uses IonCube encoded files
 * and whether the IonCube Loader extension is properly installed.
 */
class IonCubeDetector
{
    private Configuration $config;
    private ?DockerDetector $docker;

    /**
     * Cached detection results
     * @var array{
     *     loader_installed: bool,
     *     loader_version: string|null,
     *     files_encoded: bool,
     *     jit_compatible: bool,
     *     status: string,
     *     issues: list<string>,
     *     recommendations: list<string>
     * }|null
     */
    private ?array $cache = null;

    // Minimum recommended version (fixes PHP 8.1/8.2 static property bugs)
    public const MIN_RECOMMENDED_VERSION = '13.0.4';

    // Files to check for IonCube encoding (in order of preference)
    private const CHECK_FILES = [
        'admin/include/functions_base.php',
        'admin/index.php',
        'admin/include/setup.php',
    ];

    public function __construct(Configuration $config, ?DockerDetector $docker = null)
    {
        $this->config = $config;
        $this->docker = $docker;
    }

    /**
     * Get complete IonCube status
     *
     * @return array{
     *     loader_installed: bool,
     *     loader_version: string|null,
     *     files_encoded: bool,
     *     jit_compatible: bool,
     *     status: string,
     *     issues: list<string>,
     *     recommendations: list<string>
     * }
     */
    public function getStatus(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $loaderInstalled = $this->isLoaderInstalled();
        $loaderVersion = $this->getLoaderVersion();
        $filesEncoded = $this->hasEncodedFiles();
        $jitCompatible = !$loaderInstalled;

        $issues = [];
        $recommendations = [];
        $status = 'ok';

        // Check for problems
        if ($filesEncoded && !$loaderInstalled) {
            $status = 'error';
            $issues[] = 'IonCube encoded files detected but Loader not installed';
            $recommendations[] = 'Install IonCube Loader extension';
        } elseif ($loaderInstalled && $loaderVersion !== null) {
            if (version_compare($loaderVersion, self::MIN_RECOMMENDED_VERSION, '<')) {
                $status = 'warning';
                $issues[] = "IonCube Loader {$loaderVersion} has known bugs with PHP 8.1/8.2";
                $recommendations[] = 'Update IonCube Loader to ' . self::MIN_RECOMMENDED_VERSION . '+';
            }
        }

        // JIT recommendation if no IonCube needed
        if (!$filesEncoded && !$loaderInstalled) {
            // This is fine - no IonCube needed, JIT can be enabled
        } elseif (!$filesEncoded && $loaderInstalled) {
            $issues[] = 'IonCube Loader installed but no encoded files detected';
            $recommendations[] = 'Consider removing IonCube Loader to enable JIT';
        }

        $this->cache = [
            'loader_installed' => $loaderInstalled,
            'loader_version' => $loaderVersion,
            'files_encoded' => $filesEncoded,
            'jit_compatible' => $jitCompatible,
            'status' => $status,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];

        return $this->cache;
    }

    /**
     * Check if IonCube Loader extension is installed
     */
    public function isLoaderInstalled(): bool
    {
        if ($this->docker !== null) {
            return $this->checkLoaderViaDocker();
        }

        return extension_loaded('ionCube Loader');
    }

    /**
     * Get IonCube Loader version
     */
    public function getLoaderVersion(): ?string
    {
        if ($this->docker !== null) {
            return $this->getVersionViaDocker();
        }

        if (!extension_loaded('ionCube Loader')) {
            return null;
        }

        // Get version from ioncube_loader_version() if available
        if (function_exists('ioncube_loader_version')) {
            $version = ioncube_loader_version();
            return is_string($version) ? $version : null;
        }

        // Fallback: parse from phpinfo
        ob_start();
        phpinfo(INFO_MODULES);
        $info = ob_get_clean();

        if ($info !== false && preg_match('/ionCube.*?Loader.*?v?(\d+\.\d+(?:\.\d+)?)/i', $info, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if KVS installation has IonCube encoded files
     */
    public function hasEncodedFiles(): bool
    {
        $kvsPath = $this->config->getKvsPath();

        foreach (self::CHECK_FILES as $file) {
            $fullPath = $kvsPath . '/' . $file;

            if (!file_exists($fullPath) || !is_readable($fullPath)) {
                continue;
            }

            if ($this->isFileEncoded($fullPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a specific file is IonCube encoded
     *
     * Detection methods (100% reliable):
     * 1. extension_loaded('ionCube Loader') - present in ALL IonCube files
     * 2. _il_exec - IonCube Loader execution function
     * 3. <?php //[hex] - IonCube file header pattern
     */
    public function isFileEncoded(string $filePath): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        // Read first 4KB for efficiency
        $content = file_get_contents($filePath, false, null, 0, 4096);
        if ($content === false) {
            return false;
        }

        // Check for IonCube signatures
        // 1. extension_loaded check (present in all IonCube files)
        if (strpos($content, "extension_loaded('ionCube Loader')") !== false) {
            return true;
        }

        // 2. _il_exec function (IonCube internal)
        if (strpos($content, '_il_exec') !== false) {
            return true;
        }

        // 3. IonCube header pattern: <?php //[5-6 hex digits]
        $firstLine = strtok($content, "\n");
        if ($firstLine !== false && preg_match('/^\s*<\?php\s+\/\/[0-9a-f]{5,6}/i', $firstLine) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Check if JIT can be enabled (incompatible with IonCube Loader)
     */
    public function isJitCompatible(): bool
    {
        return !$this->isLoaderInstalled();
    }

    /**
     * Check IonCube Loader via Docker container
     */
    private function checkLoaderViaDocker(): bool
    {
        if ($this->docker === null) {
            return false;
        }

        $loaded = $this->docker->isPhpExtensionLoaded('ionCube Loader');
        return $loaded === true;
    }

    /**
     * Get IonCube version via Docker container
     */
    private function getVersionViaDocker(): ?string
    {
        if ($this->docker === null) {
            return null;
        }

        // Try ioncube_loader_version() first
        $result = $this->docker->execPhp("echo function_exists('ioncube_loader_version') ? ioncube_loader_version() : '';");
        if ($result !== null) {
            $version = trim($result);
            if ($version !== '') {
                return $version;
            }
        }

        // Fallback: parse from php -v
        $result = $this->docker->exec('php', 'php -v 2>&1 | grep -i ioncube');
        if ($result !== null && preg_match('/v?(\d+\.\d+(?:\.\d+)?)/i', $result, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
