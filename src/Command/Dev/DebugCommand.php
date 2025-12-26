<?php

namespace KVS\CLI\Command\Dev;

use KVS\CLI\Command\BaseCommand;
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
        if ($input->getOption('check') === true) {
            return $this->runChecks();
        }

        if ($input->getOption('test-db') === true) {
            return $this->testDatabase();
        }

        return $this->showDebugInfo();
    }

    private function runChecks(): int
    {
        $this->io->section('System Checks');

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
            $this->io->success('All checks passed');
            return self::SUCCESS;
        } else {
            $this->io->error(sprintf('%d check(s) failed', count($errors)));
            return self::FAILURE;
        }
    }

    private function testDatabase(): int
    {
        $this->io->section('Database Connection Test');

        $dbConfig = $this->config->getDatabaseConfig();

        if ($dbConfig === []) {
            $this->io->error('Database configuration not found');
            return self::FAILURE;
        }

        $this->io->info('Configuration:');
        $this->io->listing([
            'Host: ' . $dbConfig['host'],
            'Database: ' . $dbConfig['database'],
            'User: ' . $dbConfig['user'],
        ]);

        $db = $this->getDatabaseConnection();

        if ($db === null) {
            $this->io->error('Connection failed');
            return self::FAILURE;
        }

        try {
            $stmt = $db->query("SELECT VERSION()");
            if ($stmt === false) {
                throw new \Exception('Failed to query MySQL version');
            }
            $version = $stmt->fetchColumn();
            $this->io->success("Connected successfully! MySQL version: $version");

            $stmt = $db->query("SHOW TABLES LIKE '" . $this->config->getTablePrefix() . "%'");
            if ($stmt === false) {
                throw new \Exception('Failed to query tables');
            }
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $this->io->info(sprintf('Found %d KVS tables', count($tables)));

            if ($this->io->isVerbose()) {
                $this->io->listing($tables);
            }
        } catch (\Exception $e) {
            $this->io->error('Query failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showDebugInfo(): int
    {
        $this->io->section('Debug Information');

        $kvsPathValue = $this->config->getKvsPath();
        $displayErrors = ini_get('display_errors');
        $logErrors = ini_get('log_errors');
        $errorLog = ini_get('error_log');
        $memoryLimit = ini_get('memory_limit');
        $maxExecTime = ini_get('max_execution_time');
        $cwd = getcwd();

        /** @var list<list<string|int|null>> $info */
        $info = [
            ['Working Directory', $cwd !== false ? $cwd : 'Unknown'],
            ['Script Path', __FILE__],
            ['KVS Path', $kvsPathValue !== '' ? $kvsPathValue : 'Not found'],
            ['Admin Path', $this->config->getAdminPath()],
            ['Content Path', $this->config->getContentPath()],
            ['PHP Version', PHP_VERSION],
            ['PHP SAPI', PHP_SAPI],
            ['Memory Limit', $memoryLimit !== false ? $memoryLimit : 'Unknown'],
            ['Max Execution Time', $maxExecTime !== false ? $maxExecTime : 'Unknown'],
            ['Error Reporting', (string)error_reporting()],
            ['Display Errors', ($displayErrors !== '' && $displayErrors !== '0') ? 'On' : 'Off'],
            ['Log Errors', ($logErrors !== '' && $logErrors !== '0') ? 'On' : 'Off'],
            ['Error Log', ($errorLog !== '' && $errorLog !== false) ? $errorLog : 'Not set'],
        ];

        $this->renderTable(['Parameter', 'Value'], $info);

        $this->io->section('Environment Variables');

        $envVars = [
            'PATH' => getenv('PATH'),
            'HOME' => getenv('HOME'),
            'USER' => getenv('USER'),
            'SHELL' => getenv('SHELL'),
        ];

        $rows = [];
        foreach ($envVars as $key => $value) {
            if (is_string($value) && $value !== '') {
                $rows[] = [$key, truncate($value, 60)];
            }
        }

        $this->renderTable(['Variable', 'Value'], $rows);

        return self::SUCCESS;
    }
}
