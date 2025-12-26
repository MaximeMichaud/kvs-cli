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
    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::OPTIONAL, 'Specific cron task to run')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available cron tasks')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show cron status')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force run even if recently executed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('list') !== false) {
            return $this->listCronTasks();
        }

        if ($input->getOption('status') !== false) {
            return $this->showCronStatus();
        }

        $task = $input->getArgument('task');
        if ($task !== null) {
            return $this->runSpecificTask($task, $input->getOption('force') !== false);
        }

        return $this->runAllCronTasks($input->getOption('force') !== false);
    }

    private function listCronTasks(): int
    {
        $tasks = [
            ['main', 'cron.php', 'Main cron job (runs all scheduled tasks)'],
            ['conversion', 'cron_conversion.php', 'Process video conversions'],
            ['optimize', 'cron_optimize.php', 'Optimize database and files'],
            ['rotator', 'cron_rotator.php', 'Content rotation tasks'],
            ['feeds', 'cron_feeds.php', 'Update external feeds'],
            ['cleanup', 'cron_cleanup.php', 'Clean temporary files'],
            ['stats', 'cron_stats.php', 'Process statistics'],
            ['check_db', 'cron_check_db.php', 'Check database integrity'],
            ['postponed', 'cron_postponed_tasks.php', 'Run postponed tasks'],
        ];

        $this->renderTable(
            ['Task Name', 'Script', 'Description'],
            $tasks
        );

        return self::SUCCESS;
    }

    private function showCronStatus(): int
    {
        $adminPath = $this->config->getAdminPath();
        $statusFile = $adminPath . '/data/engine/cron_status.dat';

        if (file_exists($statusFile)) {
            $contents = file_get_contents($statusFile);
            if ($contents !== false) {
                $status = unserialize($contents);

                if (is_array($status)) {
                    $rows = [];
                    foreach ($status as $task => $lastRun) {
                        $rows[] = [
                            $task,
                            date('Y-m-d H:i:s', $lastRun),
                            $this->getTimeDiff($lastRun),
                        ];
                    }

                    $this->renderTable(
                        ['Task', 'Last Run', 'Time Ago'],
                        $rows
                    );
                }
            }
        } else {
            $this->io->warning('No cron status information available');
        }

        return self::SUCCESS;
    }

    private function runSpecificTask(string $task, bool $force): int
    {
        $cronScripts = [
            'main' => 'cron.php',
            'conversion' => 'cron_conversion.php',
            'optimize' => 'cron_optimize.php',
            'rotator' => 'cron_rotator.php',
            'feeds' => 'cron_feeds.php',
            'cleanup' => 'cron_cleanup.php',
            'stats' => 'cron_stats.php',
            'check_db' => 'cron_check_db.php',
            'postponed' => 'cron_postponed_tasks.php',
        ];

        if (!isset($cronScripts[$task])) {
            $this->io->error("Unknown cron task: $task");
            $this->io->note('Use --list to see available tasks');
            return self::FAILURE;
        }

        $scriptPath = $this->config->getAdminPath() . '/include/' . $cronScripts[$task];

        if (!file_exists($scriptPath)) {
            $this->io->error("Cron script not found: $scriptPath");
            return self::FAILURE;
        }

        $this->io->info("Running cron task: $task");

        $output = $this->executePhpScript($scriptPath, $force ? ['--force'] : []);

        if ($output !== null) {
            $this->io->success("Cron task '$task' completed successfully");
            if ($output !== '') {
                $this->io->text($output);
            }
            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    private function runAllCronTasks(bool $force): int
    {
        $cronScript = $this->config->getAdminPath() . '/include/cron.php';

        if (!file_exists($cronScript)) {
            $this->io->error("Main cron script not found: $cronScript");
            return self::FAILURE;
        }

        $this->io->info('Running all cron tasks...');

        $output = $this->executePhpScript($cronScript, $force ? ['--force'] : []);

        if ($output !== null) {
            $this->io->success('All cron tasks completed successfully');
            if ($output !== '') {
                $this->io->text($output);
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
