<?php

namespace KVS\CLI\Command\Dev;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\truncate;

#[AsCommand(
    name: 'dev:debug',
    description: 'Debug KVS system',
    aliases: ['debug']
)]
class DebugCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Run system checks')
            ->addOption('info', null, InputOption::VALUE_NONE, 'Show debug information')
            ->addOption('test-db', null, InputOption::VALUE_NONE, 'Test database connection');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->getBoolOption($input, 'check')) {
            return $this->runChecks();
        }

        if ($this->getBoolOption($input, 'test-db')) {
            return $this->testDatabase();
        }

        return $this->showDebugInfo();
    }

    private function runChecks(): int
    {
        $this->io()->section('System Checks');

        $checks = [];

        $checks[] = ['PHP Version', PHP_VERSION, 'OK'];

        $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'gd', 'curl'];
        foreach ($requiredExtensions as $ext) {
            $checks[] = [
                "PHP Extension: $ext",
                extension_loaded($ext) ? 'Installed' : 'Missing',
                extension_loaded($ext) ? 'OK' : 'ERROR',
            ];
        }

        $kvsPath = $this->config->getKvsPath();
        $checks[] = ['KVS Path', $kvsPath !== '' ? $kvsPath : 'Not found', $kvsPath !== '' ? 'OK' : 'ERROR'];

        if ($this->config->getKvsPath() !== '') {
            $adminPath = $this->config->getAdminPath();
            $checks[] = ['Admin Path', is_dir($adminPath) ? 'Found' : 'Missing', is_dir($adminPath) ? 'OK' : 'ERROR'];

            $setupFile = $adminPath . '/include/setup.php';
            $checks[] = ['Setup File', file_exists($setupFile) ? 'Found' : 'Missing', file_exists($setupFile) ? 'OK' : 'ERROR'];

            $dbConfig = $adminPath . '/include/setup_db.php';
            $checks[] = ['DB Config', file_exists($dbConfig) ? 'Found' : 'Missing', file_exists($dbConfig) ? 'OK' : 'ERROR'];
        }

        $db = $this->getDatabaseConnection();
        $checks[] = ['Database Connection', $db !== null ? 'Connected' : 'Failed', $db !== null ? 'OK' : 'ERROR'];

        $this->renderTable(['Check', 'Value', 'Status'], $checks);

        $errors = array_filter($checks, fn($c) => $c[2] === 'ERROR');

        if ($errors === []) {
            $this->io()->success('All checks passed');
            return self::SUCCESS;
        } else {
            $this->io()->error(sprintf('%d check(s) failed', count($errors)));
            return self::FAILURE;
        }
    }

    private function testDatabase(): int
    {
        $this->io()->section('Database Connection Test');

        $dbConfig = $this->config->getDatabaseConfig();

        // Validate required configuration keys
        $requiredKeys = ['host', 'database', 'user'];
        foreach ($requiredKeys as $key) {
            if (!isset($dbConfig[$key]) || $dbConfig[$key] === '') {
                $this->io()->error("Database configuration missing: $key");
                return self::FAILURE;
            }
        }

        $this->io()->info('Configuration:');
        $this->io()->listing([
            'Host: ' . $dbConfig['host'],
            'Database: ' . $dbConfig['database'],
            'User: ' . $dbConfig['user'],
        ]);

        $db = $this->getDatabaseConnection();

        if ($db === null) {
            $this->io()->error('Connection failed');
            return self::FAILURE;
        }

        try {
            $stmt = $db->query("SELECT VERSION()");
            if ($stmt === false) {
                throw new \Exception('Failed to query MySQL version');
            }
            $version = $stmt->fetchColumn();
            $this->io()->success("Connected successfully! MySQL version: $version");

            $stmt = $db->query("SHOW TABLES LIKE '" . $this->config->getTablePrefix() . "%'");
            if ($stmt === false) {
                throw new \Exception('Failed to query tables');
            }
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $this->io()->info(sprintf('Found %d KVS tables', count($tables)));

            if ($this->io()->isVerbose()) {
                $this->io()->listing($tables);
            }
        } catch (\Exception $e) {
            $this->io()->error('Query failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showDebugInfo(): int
    {
        $this->io()->section('Debug Information');

        $kvsPathValue = $this->config->getKvsPath();
        $cwd = getcwd();

        // Note: ini_get() can return false, but PHPDoc says string|numeric-string
        /** @phpstan-ignore-next-line */
        $memoryLimitValue = @ini_get('memory_limit') ?: 'Unknown';
        /** @phpstan-ignore-next-line */
        $maxExecTimeValue = @ini_get('max_execution_time') ?: 'Unknown';
        /** @phpstan-ignore-next-line */
        $displayErrorsRaw = @ini_get('display_errors') ?: '0';
        /** @phpstan-ignore-next-line */
        $logErrorsRaw = @ini_get('log_errors') ?: '0';
        /** @phpstan-ignore-next-line */
        $errorLogRaw = @ini_get('error_log') ?: '';

        /** @phpstan-ignore-next-line */
        $displayErrorsValue = ($displayErrorsRaw !== '0' && $displayErrorsRaw !== '') ? 'On' : 'Off';
        /** @phpstan-ignore-next-line */
        $logErrorsValue = ($logErrorsRaw !== '0' && $logErrorsRaw !== '') ? 'On' : 'Off';
        $errorLogValue = $errorLogRaw !== '' ? $errorLogRaw : 'Not set';

        /** @var list<list<string|int|null>> $info */
        $info = [
            ['Working Directory', $cwd !== false ? $cwd : 'Unknown'],
            ['Script Path', __FILE__],
            ['KVS Path', $kvsPathValue !== '' ? $kvsPathValue : 'Not found'],
            ['Admin Path', $this->config->getAdminPath()],
            ['Content Path', $this->config->getContentPath()],
            ['PHP Version', PHP_VERSION],
            ['PHP SAPI', PHP_SAPI],
            ['Memory Limit', $memoryLimitValue],
            ['Max Execution Time', $maxExecTimeValue],
            ['Error Reporting', (string)error_reporting()],
            ['Display Errors', $displayErrorsValue],
            ['Log Errors', $logErrorsValue],
            ['Error Log', $errorLogValue],
        ];

        $this->renderTable(['Parameter', 'Value'], $info);

        $this->io()->section('Environment Variables');

        $envVars = [
            'PATH' => getenv('PATH'),
            'HOME' => getenv('HOME'),
            'USER' => getenv('USER'),
            'SHELL' => getenv('SHELL'),
        ];

        $rows = [];
        foreach ($envVars as $key => $value) {
            if (is_string($value) && $value !== '') {
                $rows[] = [$key, truncate($value, Constants::CONFIG_TRUNCATE_LENGTH)];
            }
        }

        $this->renderTable(['Variable', 'Value'], $rows);

        return self::SUCCESS;
    }
}
