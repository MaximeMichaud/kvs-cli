<?php

namespace KVS\CLI\Command\Dev;

use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'dev:log',
    description: 'View and manage KVS logs',
    aliases: ['log', 'logs']
)]
class LogCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::OPTIONAL, 'Log type to view (e.g., cron, api, uploader)')
            ->addOption('tail', 't', InputOption::VALUE_REQUIRED, 'Show last N lines', 50)
            ->addOption('follow', 'f', InputOption::VALUE_NONE, 'Follow log output')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear log file')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available log files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('list')) {
            return $this->listLogs();
        }

        $type = $input->getArgument('type');

        // If no type specified and no options, show list
        if (!$type && !$input->getOption('clear') && !$input->getOption('follow')) {
            return $this->listLogs();
        }

        if ($input->getOption('clear')) {
            return $this->clearLog($type);
        }

        if ($input->getOption('follow')) {
            return $this->followLog($type);
        }

        return $this->showLog($type, (int)$input->getOption('tail'));
    }

    private function listLogs(): int
    {
        $logDirs = [
            $this->config->getAdminPath() . '/logs',
            $this->config->getAdminPath() . '/data/logs',
        ];

        $logs = [];

        foreach ($logDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*.{log,txt}', GLOB_BRACE);
            foreach ($files as $file) {
                $logs[] = [
                    basename($file, '.log'),
                    basename($file),
                    format_bytes(filesize($file)),
                    date('Y-m-d H:i:s', filemtime($file)),
                ];
            }
        }

        if (empty($logs)) {
            $this->io->info('No log files found');
            return self::SUCCESS;
        }

        $this->io->table(['Type', 'File', 'Size', 'Modified'], $logs);

        return self::SUCCESS;
    }

    private function showLog(string $type, int $lines): int
    {
        $logFile = $this->findLogFile($type);

        if (!$logFile) {
            $this->io->error("Log file not found: $type");
            $this->io->note('Use --list to see available logs');
            return self::FAILURE;
        }

        $this->io->section("Log: $type");
        $this->io->info("File: $logFile");

        if (!file_exists($logFile)) {
            $this->io->warning('Log file is empty or does not exist');
            return self::SUCCESS;
        }

        $content = $this->tail($logFile, $lines);

        if (empty($content)) {
            $this->io->info('No log entries');
        } else {
            foreach ($content as $line) {
                $this->formatLogLine($line);
            }
        }

        return self::SUCCESS;
    }

    private function followLog(string $type): int
    {
        $logFile = $this->findLogFile($type);

        if (!$logFile) {
            $this->io->error("Log file not found: $type");
            return self::FAILURE;
        }

        $this->io->info("Following log: $logFile");
        $this->io->info('Press Ctrl+C to stop');
        $this->io->newLine();

        $lastPosition = filesize($logFile);

        while (true) {
            clearstatcache(false, $logFile);
            $currentSize = filesize($logFile);

            if ($currentSize > $lastPosition) {
                $fp = fopen($logFile, 'r');
                fseek($fp, $lastPosition);

                while (!feof($fp)) {
                    $line = fgets($fp);
                    if ($line) {
                        $this->formatLogLine($line);
                    }
                }

                $lastPosition = ftell($fp);
                fclose($fp);
            } elseif ($currentSize < $lastPosition) {
                $lastPosition = $currentSize;
            }

            sleep(1);
        }
    }

    private function clearLog(string $type): int
    {
        $logFile = $this->findLogFile($type);

        if (!$logFile) {
            $this->io->error("Log file not found: $type");
            return self::FAILURE;
        }

        $this->io->warning("This will clear the log file: $logFile");

        if (!$this->io->confirm('Do you want to continue?', false)) {
            return self::SUCCESS;
        }

        file_put_contents($logFile, '');
        $this->io->success('Log file cleared');

        return self::SUCCESS;
    }

    private function findLogFile(string $type): ?string
    {
        $possibleFiles = [
            $this->config->getAdminPath() . "/logs/$type.log",
            $this->config->getAdminPath() . "/logs/$type.txt",
            $this->config->getAdminPath() . "/data/logs/$type.log",
            $this->config->getAdminPath() . "/data/logs/$type.txt",
        ];

        foreach ($possibleFiles as $file) {
            if (file_exists($file)) {
                return $file;
            }
        }

        $dir = $this->config->getAdminPath() . '/logs';
        if (is_dir($dir)) {
            $files = glob("$dir/*$type*");
            if (!empty($files)) {
                return $files[0];
            }
        }

        return null;
    }

    private function tail(string $file, int $lines): array
    {
        $result = [];
        $fp = fopen($file, 'r');

        if (!$fp) {
            return $result;
        }

        $buffer = 4096;
        fseek($fp, -1, SEEK_END);

        if (ftell($fp) === 0) {
            return $result;
        }

        $output = '';
        $chunk = '';

        while (ftell($fp) > 0 && count($result) < $lines) {
            $seek = min(ftell($fp), $buffer);
            fseek($fp, -$seek, SEEK_CUR);
            $chunk = fread($fp, $seek);
            $output = $chunk . $output;
            fseek($fp, -mb_strlen($chunk, '8bit'), SEEK_CUR);

            $result = explode("\n", $output);

            if (count($result) > $lines) {
                $result = array_slice($result, -$lines);
            }
        }

        fclose($fp);

        return array_slice($result, -$lines);
    }

    private function formatLogLine(string $line): void
    {
        $line = trim($line);

        if (empty($line)) {
            return;
        }

        if (str_contains(strtolower($line), 'error')) {
            $this->io->text("<fg=red>$line</>");
        } elseif (str_contains(strtolower($line), 'warning')) {
            $this->io->text("<fg=yellow>$line</>");
        } elseif (str_contains(strtolower($line), 'info')) {
            $this->io->text("<fg=cyan>$line</>");
        } else {
            $this->io->text($line);
        }
    }
}
