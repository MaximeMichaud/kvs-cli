<?php

declare(strict_types=1);

namespace KVS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Display various details about the KVS CLI environment.
 *
 * Similar to WP-CLI's `wp cli info` command.
 */
class CliInfoCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('cli:info')
            ->setAliases(['info'])
            ->setDescription('Display information about the KVS CLI environment')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (list or json)', 'list')
            ->setHelp(<<<'HELP'
Prints various details about the KVS CLI environment.

Helpful for diagnostic purposes, this command shares:
  - OS information
  - Shell information
  - PHP binary used
  - PHP version
  - php.ini configuration file used
  - MySQL/MariaDB client binary (if found)
  - KVS CLI root dir
  - KVS CLI PHAR path (if running from PHAR)
  - KVS installation path (if detected)
  - KVS version (if detected)
  - KVS CLI version

<info>Examples:</info>
  <comment>kvs cli:info</comment>          Display environment information
  <comment>kvs cli:info --format=json</comment>  Output as JSON
  <comment>kvs info</comment>              Alias for cli:info
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format');

        $info = $this->gatherInfo();

        if ($format === 'json') {
            $output->writeln((string) json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->displayList($io, $info);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    private function gatherInfo(): array
    {
        $info = [];

        // OS Information
        $info['os'] = sprintf(
            '%s %s %s %s',
            php_uname('s'),
            php_uname('r'),
            php_uname('v'),
            php_uname('m')
        );

        // Shell
        $shell = getenv('SHELL');
        if ($shell === false && $this->isWindows()) {
            $shell = getenv('ComSpec');
        }
        $info['shell'] = $shell !== false && $shell !== '' ? $shell : null;

        // PHP binary
        $info['php_binary'] = PHP_BINARY;
        $info['php_version'] = PHP_VERSION;

        // php.ini
        $phpIni = php_ini_loaded_file();
        $info['php_ini'] = $phpIni !== false ? $phpIni : null;

        // MySQL/MariaDB binary
        $info['mysql_binary'] = $this->findMysqlBinary();
        $info['mysql_version'] = $this->getMysqlVersion($info['mysql_binary']);

        // KVS CLI paths
        $info['kvs_cli_root'] = $this->getCliRoot();
        $info['kvs_cli_phar_path'] = $this->getPharPath();
        $info['kvs_cli_version'] = defined('KVS_CLI_VERSION') ? KVS_CLI_VERSION : 'unknown';

        // KVS installation info (if detected)
        $kvsPath = $this->detectKvsPath();
        $info['kvs_path'] = $kvsPath;
        $info['kvs_version'] = $kvsPath !== null ? $this->getKvsVersion($kvsPath) : null;

        return $info;
    }

    /**
     * @param array<string, string|null> $info
     */
    private function displayList(SymfonyStyle $io, array $info): void
    {
        $io->writeln(sprintf('OS:              %s', $info['os']));
        $io->writeln(sprintf('Shell:           %s', $info['shell'] ?? '<not detected>'));
        $io->writeln(sprintf('PHP binary:      %s', $info['php_binary']));
        $io->writeln(sprintf('PHP version:     %s', $info['php_version']));
        $io->writeln(sprintf('php.ini used:    %s', $info['php_ini'] ?? '<none>'));
        $io->writeln(sprintf('MySQL binary:    %s', $info['mysql_binary'] ?? '<not found>'));
        $io->writeln(sprintf('MySQL version:   %s', $info['mysql_version'] ?? '<not detected>'));
        $io->writeln(sprintf('KVS CLI root:    %s', $info['kvs_cli_root']));
        $io->writeln(sprintf('KVS CLI PHAR:    %s', $info['kvs_cli_phar_path'] ?? '<not running as PHAR>'));
        $io->writeln(sprintf('KVS CLI version: %s', $info['kvs_cli_version']));
        $io->writeln(sprintf('KVS path:        %s', $info['kvs_path'] ?? '<not detected>'));
        $io->writeln(sprintf('KVS version:     %s', $info['kvs_version'] ?? '<not detected>'));
    }

    private function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\' || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    private function findMysqlBinary(): ?string
    {
        $binaries = ['mysql', 'mariadb'];

        foreach ($binaries as $binary) {
            $path = $this->which($binary);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    private function which(string $command): ?string
    {
        if ($this->isWindows()) {
            $output = [];
            exec('where ' . escapeshellarg($command) . ' 2>NUL', $output);
            return isset($output[0]) && $output[0] !== '' ? trim($output[0]) : null;
        }

        $output = [];
        exec('which ' . escapeshellarg($command) . ' 2>/dev/null', $output);
        return isset($output[0]) && $output[0] !== '' ? trim($output[0]) : null;
    }

    private function getMysqlVersion(?string $mysqlBinary): ?string
    {
        if ($mysqlBinary === null) {
            return null;
        }

        $output = [];
        exec(escapeshellarg($mysqlBinary) . ' --version 2>/dev/null', $output);

        if (isset($output[0]) && $output[0] !== '') {
            // Parse version from output like "mysql  Ver 8.0.35 for Linux..."
            // or "mariadb  Ver 15.1 Distrib 10.11.6-MariaDB..."
            $line = $output[0];

            // Try to extract MariaDB version
            if (preg_match('/(\d+\.\d+\.\d+)-MariaDB/', $line, $matches)) {
                return $matches[1] . ' (MariaDB)';
            }

            // Try to extract MySQL version
            if (preg_match('/Ver\s+(\d+\.\d+\.\d+)/', $line, $matches)) {
                return $matches[1];
            }

            // Try distrib version for MariaDB
            if (preg_match('/Distrib\s+(\d+\.\d+\.\d+)/', $line, $matches)) {
                return $matches[1] . ' (MariaDB)';
            }

            // Return full line if parsing fails
            return $line;
        }

        return null;
    }

    private function getCliRoot(): string
    {
        // If running from PHAR, return phar path
        $pharPath = \Phar::running(false);
        if ($pharPath !== '') {
            return 'phar://' . $pharPath;
        }

        // Otherwise, return the KVS_CLI_ROOT constant
        if (defined('KVS_CLI_ROOT')) {
            return KVS_CLI_ROOT;
        }

        // Fallback: go up from this file
        return dirname(__DIR__, 2);
    }

    private function getPharPath(): ?string
    {
        $pharPath = \Phar::running(false);
        if ($pharPath !== '') {
            return $pharPath;
        }

        return null;
    }

    private function detectKvsPath(): ?string
    {
        // Check --path option if passed via argv
        global $argv;
        if (isset($argv) && is_array($argv)) {
            foreach ($argv as $arg) {
                if (is_string($arg) && str_starts_with($arg, '--path=')) {
                    $path = substr($arg, 7);
                    if ($this->isValidKvsPath($path)) {
                        $realPath = realpath($path);
                        return $realPath !== false ? $realPath : $path;
                    }
                }
            }
        }

        // Check environment variable
        $envPath = getenv('KVS_PATH');
        if ($envPath !== false && $envPath !== '' && $this->isValidKvsPath($envPath)) {
            $realPath = realpath($envPath);
            return $realPath !== false ? $realPath : $envPath;
        }

        // Check current directory
        $cwd = getcwd();
        if ($cwd !== false && $this->isValidKvsPath($cwd)) {
            return $cwd;
        }

        // Check parent directories (up to 3 levels)
        if ($cwd !== false) {
            $path = $cwd;
            for ($i = 0; $i < 3; $i++) {
                $parent = dirname($path);
                if ($parent === $path) {
                    break;
                }
                $path = $parent;
                if ($this->isValidKvsPath($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function isValidKvsPath(string $path): bool
    {
        return file_exists($path . '/admin/include/setup_db.php');
    }

    private function getKvsVersion(string $kvsPath): ?string
    {
        // Try version.php first (most common location)
        $versionFile = $kvsPath . '/admin/include/version.php';
        if (file_exists($versionFile)) {
            $content = @file_get_contents($versionFile);
            if ($content !== false) {
                // Look for $config['project_version']
                if (preg_match('/\$config\[\'project_version\'\]\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
                    return $matches[1];
                }
            }
        }

        // Fallback to setup.php
        $setupFile = $kvsPath . '/admin/include/setup.php';
        if (file_exists($setupFile)) {
            $content = @file_get_contents($setupFile);
            if ($content !== false) {
                // Look for $config['project_version']
                if (preg_match('/\$config\[\'project_version\'\]\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
                    return $matches[1];
                }

                // Try VERSION_NUMBER constant
                if (preg_match('/define\s*\(\s*[\'"]VERSION_NUMBER[\'"]\s*,\s*[\'"]([^"\']+)[\'"]/', $content, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }
}
