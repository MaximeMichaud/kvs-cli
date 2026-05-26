<?php

namespace KVS\CLI\Command\System;

use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system:cron',
    description: 'Run KVS cron tasks',
    aliases: ['cron']
)]
class CronCommand extends BaseCommand
{
    /**
     * @var array<string, array{script: string, description: string}>
     */
    private const CRON_TASKS = [
        'main' => ['script' => 'cron.php', 'description' => 'Main cron job (runs all scheduled tasks)'],
        'conversion' => ['script' => 'cron_conversion.php', 'description' => 'Process video conversions'],
        'optimize' => ['script' => 'cron_optimize.php', 'description' => 'Optimize database and files'],
        'rotator' => ['script' => 'cron_rotator.php', 'description' => 'Content rotation tasks'],
        'feeds' => ['script' => 'cron_feeds.php', 'description' => 'Update external feeds'],
        'cleanup' => ['script' => 'cron_cleanup.php', 'description' => 'Clean temporary files'],
        'stats' => ['script' => 'cron_stats.php', 'description' => 'Process statistics'],
        'check_db' => ['script' => 'cron_check_db.php', 'description' => 'Check database integrity'],
        'postponed' => ['script' => 'cron_postponed_tasks.php', 'description' => 'Run postponed tasks'],
        'billing' => ['script' => 'cron_billing.php', 'description' => 'Process billing tasks'],
        'clone_db' => ['script' => 'cron_clone_db.php', 'description' => 'Clone database tasks'],
        'custom' => ['script' => 'cron_custom.php', 'description' => 'Run custom cron tasks'],
        'import' => ['script' => 'cron_import.php', 'description' => 'Process import tasks'],
        'plugins' => ['script' => 'cron_plugins.php', 'description' => 'Run plugin cron tasks'],
        'servers' => ['script' => 'cron_servers.php', 'description' => 'Process server tasks'],
    ];

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Run or inspect KVS cron tasks.

<info>EXAMPLES:</info>
  <comment>kvs system:cron --list</comment>
  <comment>kvs system:cron --status</comment>
  <comment>kvs system:cron main</comment>
  <comment>kvs system:cron cleanup</comment>
HELP
            )
            ->addArgument('task', InputArgument::OPTIONAL, 'Specific cron task to run')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available cron tasks')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show cron status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->getBoolOption($input, 'list')) {
            return $this->listCronTasks();
        }

        if ($this->getBoolOption($input, 'status')) {
            return $this->showCronStatus();
        }

        $task = $this->getStringArgument($input, 'task');
        if ($task !== null) {
            return $this->runSpecificTask($task);
        }

        return $this->runAllCronTasks();
    }

    private function listCronTasks(): int
    {
        $tasks = [];
        foreach (self::CRON_TASKS as $name => $task) {
            $tasks[] = [$name, $task['script'], $task['description']];
        }

        $this->renderTable(
            ['Task Name', 'Script', 'Description'],
            $tasks
        );

        return self::SUCCESS;
    }

    private function showCronStatus(): int
    {
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            return self::FAILURE;
        }

        try {
            $stmt = $db->query("
                SELECT pid, last_exec_date
                FROM {$this->multiTable('admin_processes')}
                ORDER BY pid ASC
            ");
            if ($stmt === false) {
                $this->io()->error('Failed to query cron status');
                return self::FAILURE;
            }
            $processes = $stmt->fetchAll();
        } catch (\Exception $e) {
            $this->io()->error('Failed to query cron status: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($processes === []) {
            $this->io()->warning('No cron status information available');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($processes as $process) {
            if (!is_array($process)) {
                continue;
            }

            $pid = $process['pid'] ?? '';
            $lastExecDate = $process['last_exec_date'] ?? null;
            $timestamp = null;
            if (is_string($lastExecDate) && $lastExecDate !== '' && $lastExecDate !== '0000-00-00 00:00:00') {
                $parsed = strtotime($lastExecDate);
                if ($parsed !== false && $parsed > 0) {
                    $timestamp = $parsed;
                }
            }

            $rows[] = [
                is_scalar($pid) ? (string) $pid : '',
                $timestamp === null ? 'Never' : date('Y-m-d H:i:s', $timestamp),
                $timestamp === null ? 'Never' : $this->getTimeDiff($timestamp),
            ];
        }

        $this->renderTable(
            ['Task', 'Last Run', 'Time Ago'],
            $rows
        );

        return self::SUCCESS;
    }

    private function runSpecificTask(string $task): int
    {
        $cronScripts = $this->getCronScriptMap();

        if (!isset($cronScripts[$task])) {
            $this->io()->error("Unknown cron task: $task");
            $this->io()->note('Use --list to see available tasks');
            return self::FAILURE;
        }

        $scriptPath = $this->config->getAdminPath() . '/include/' . $cronScripts[$task];

        if (!file_exists($scriptPath)) {
            $this->io()->error("Cron script not found: $scriptPath");
            return self::FAILURE;
        }

        $this->io()->info("Running cron task: $task");

        $output = $this->executePhpScript($scriptPath);

        if ($output !== null) {
            $this->io()->success("Cron task '$task' completed successfully");
            if ($output !== '') {
                $this->io()->text($output);
            }
            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * @return array<string, string>
     */
    private function getCronScriptMap(): array
    {
        $scripts = [];
        foreach (self::CRON_TASKS as $name => $task) {
            $scripts[$name] = $task['script'];

            $basename = pathinfo($task['script'], PATHINFO_FILENAME);
            if ($basename !== '') {
                $scripts[$basename] = $task['script'];
            }
        }

        return $scripts;
    }

    private function runAllCronTasks(): int
    {
        $cronScript = $this->config->getAdminPath() . '/include/cron.php';

        if (!file_exists($cronScript)) {
            $this->io()->error("Main cron script not found: $cronScript");
            return self::FAILURE;
        }

        $this->io()->info('Running all cron tasks...');

        $output = $this->executePhpScript($cronScript);

        if ($output !== null) {
            $this->io()->success('All cron tasks completed successfully');
            if ($output !== '') {
                $this->io()->text($output);
            }
            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    private function getTimeDiff(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return "$diff seconds ago";
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return "$minutes minutes ago";
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return "$hours hours ago";
        } else {
            $days = floor($diff / 86400);
            return "$days days ago";
        }
    }
}
