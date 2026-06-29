<?php

namespace KVS\CLI\Command\Dev;

use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'dev:log',
    description: 'View and manage KVS logs',
    aliases: ['log', 'logs']
)]
class LogCommand extends BaseCommand
{
    private const LOG_EXTENSIONS = ['log', 'txt'];

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
        if ($this->hasConflictingBoolOptions($input, ['list', 'clear', 'follow'])) {
            return self::FAILURE;
        }

        $type = $this->getStringArgument($input, 'type');

        if ($this->getBoolOption($input, 'list')) {
            if ($type !== null && $type !== '') {
                $this->io()->error(
                    'The list action does not support a log type argument. Remove the type or omit --list.'
                );
                return self::FAILURE;
            }
            if ($this->rejectUnsupportedOptions($input, 'list', ['tail'])) {
                return self::FAILURE;
            }

            return $this->listLogs();
        }

        // If no type specified and no options, show list
        if ($type === null && !$this->getBoolOption($input, 'clear') && !$this->getBoolOption($input, 'follow')) {
            if ($this->rejectUnsupportedOptions($input, 'list', ['tail'])) {
                return self::FAILURE;
            }

            return $this->listLogs();
        }

        if ($this->getBoolOption($input, 'clear')) {
            if ($this->rejectUnsupportedOptions($input, 'clear', ['tail'])) {
                return self::FAILURE;
            }

            return $this->clearLog($type, $input);
        }

        if ($this->getBoolOption($input, 'follow')) {
            if ($this->rejectUnsupportedOptions($input, 'follow', ['tail'])) {
                return self::FAILURE;
            }

            return $this->followLog($type);
        }

        $tail = $this->getPositiveIntOptionOrDefault($input, 'tail', 50);
        if ($tail === null) {
            return self::FAILURE;
        }

        return $this->showLog($type, $tail);
    }

    private function listLogs(): int
    {
        $logs = [];

        foreach ($this->getLogFiles() as $file) {
            $logs[] = [
                $file['type'],
                $file['file'],
                format_bytes($file['size']),
                date('Y-m-d H:i:s', $file['mtime']),
            ];
        }

        if ($logs === []) {
            $this->io()->info('No log files found');
            return self::SUCCESS;
        }

        $this->renderTable(['Type', 'File', 'Size', 'Modified'], $logs);

        return self::SUCCESS;
    }

    private function showLog(?string $type, int $lines): int
    {
        if ($type === null) {
            $this->io()->error('Log type is required');
            $this->io()->note('Use --list to see available logs');
            return self::FAILURE;
        }

        $logFile = $this->findLogFile($type);

        if ($logFile === null) {
            $this->io()->error("Log file not found: $type");
            $this->io()->note('Use --list to see available logs');
            return self::FAILURE;
        }

        $this->io()->section("Log: $type");
        $this->io()->info("File: $logFile");

        if (!file_exists($logFile)) {
            $this->io()->warning('Log file is empty or does not exist');
            return self::SUCCESS;
        }

        $content = $this->tail($logFile, $lines);

        if ($content === []) {
            $this->io()->info('No log entries');
        } else {
            foreach ($content as $line) {
                $this->formatLogLine($line);
            }
        }

        return self::SUCCESS;
    }

    private function followLog(?string $type): int
    {
        if ($type === null) {
            $this->io()->error('Log type is required');
            return self::FAILURE;
        }

        $logFile = $this->findLogFile($type);

        if ($logFile === null) {
            $this->io()->error("Log file not found: $type");
            return self::FAILURE;
        }

        $this->io()->info("Following log: $logFile");
        $this->io()->info('Press Ctrl+C to stop');
        $this->io()->newLine();

        $initialSize = filesize($logFile);
        if ($initialSize === false) {
            $this->io()->error('Unable to read log file size');
            return self::FAILURE;
        }

        $lastPosition = $initialSize;
        $lastInode = fileinode($logFile);

        while (true) {
            $lines = $this->readNewFollowLines($logFile, $lastPosition, $lastInode);
            if ($lines === null) {
                $this->io()->error('Unable to read log file');
                return self::FAILURE;
            }

            foreach ($lines as $line) {
                $this->formatLogLine($line);
            }

            sleep(1);
        }
    }

    /**
     * @return list<string>|null
     */
    private function readNewFollowLines(string $logFile, int &$lastPosition, int|false &$lastInode): ?array
    {
        clearstatcache(false, $logFile);
        $currentSize = filesize($logFile);
        if ($currentSize === false) {
            return null;
        }

        $currentInode = fileinode($logFile);
        if ($currentInode !== false && $lastInode !== false && $currentInode !== $lastInode) {
            $lastPosition = 0;
            $lastInode = $currentInode;
        } elseif ($currentSize < $lastPosition) {
            $lastPosition = 0;
        }

        if ($currentSize <= $lastPosition) {
            return [];
        }

        $fp = fopen($logFile, 'r');
        if ($fp === false) {
            return null;
        }

        if (fseek($fp, $lastPosition) !== 0) {
            fclose($fp);
            return null;
        }

        $lines = [];
        while (($line = fgets($fp)) !== false) {
            $lines[] = $line;
        }

        $position = ftell($fp);
        if ($position !== false) {
            $lastPosition = $position;
        }
        if ($currentInode !== false) {
            $lastInode = $currentInode;
        }

        fclose($fp);

        return $lines;
    }

    private function clearLog(?string $type, InputInterface $input): int
    {
        if ($type === null) {
            $this->io()->error('Log type is required');
            return self::FAILURE;
        }

        $logFile = $this->findLogFile($type);

        if ($logFile === null) {
            $this->io()->error("Log file not found: $type");
            return self::FAILURE;
        }

        $this->io()->warning("This will clear the log file: $logFile");

        if ($this->io()->confirm('Do you want to continue?', false) !== true) {
            if (!$input->isInteractive()) {
                $this->io()->error('Log clear cancelled because confirmation was not provided.');
                return self::FAILURE;
            }

            $this->io()->warning('Log clear cancelled');
            return self::SUCCESS;
        }

        file_put_contents($logFile, '');
        $this->io()->success('Log file cleared');

        return self::SUCCESS;
    }

    private function findLogFile(string $type): ?string
    {
        $type = trim(str_replace('\\', '/', $type), '/');

        if (!$this->isSafeLogType($type)) {
            return null;
        }

        $types = [$type];
        if (preg_match('/\.(?:log|txt)\z/i', $type) !== 1) {
            foreach (self::LOG_EXTENSIONS as $extension) {
                $types[] = "$type.$extension";
            }
        }

        foreach ($this->getLogFiles() as $file) {
            if (in_array($file['type'], $types, true) || in_array($file['file'], $types, true)) {
                return $file['path'];
            }
        }

        if (!str_contains($type, '/')) {
            foreach ($this->getLogFiles() as $file) {
                if (str_contains($file['type'], $type)) {
                    return $file['path'];
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{type: string, file: string, path: string, size: int, mtime: int}>
     */
    private function getLogFiles(): array
    {
        $logDirs = [
            ['prefix' => '', 'dir' => $this->config->getAdminPath() . '/logs'],
            ['prefix' => '', 'dir' => $this->config->getAdminPath() . '/data/logs'],
            ['prefix' => 'conversion', 'dir' => $this->config->getAdminPath() . '/data/conversion'],
        ];

        $files = [];

        foreach ($logDirs as $logDir) {
            $prefix = $logDir['prefix'];
            $dir = $logDir['dir'];
            $root = realpath($dir);
            if ($root === false || !is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $extension = strtolower($file->getExtension());
                if (!in_array($extension, self::LOG_EXTENSIONS, true)) {
                    continue;
                }

                $path = $file->getPathname();
                $relativePath = $this->getRelativeLogPath($root, $path);
                if ($relativePath === null) {
                    continue;
                }
                $displayPath = $prefix === '' ? $relativePath : $prefix . '/' . $relativePath;

                $fileSize = filesize($path);
                $fileMtime = filemtime($path);
                if ($fileSize === false || $fileMtime === false) {
                    continue;
                }

                $files[] = [
                    'type' => preg_replace('/\.(?:log|txt)\z/i', '', $displayPath) ?? $displayPath,
                    'file' => $displayPath,
                    'path' => $path,
                    'size' => $fileSize,
                    'mtime' => $fileMtime,
                ];
            }
        }

        usort($files, static fn (array $left, array $right): int => strcmp($left['type'], $right['type']));

        return $files;
    }

    private function getRelativeLogPath(string $root, string $path): ?string
    {
        $realPath = realpath($path);
        if ($realPath === false || !str_starts_with($realPath, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($root) + 1));
    }

    private function isSafeLogType(string $type): bool
    {
        if ($type === '' || str_contains($type, "\0")) {
            return false;
        }

        foreach (explode('/', $type) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Read the last N lines from a file
     *
     * @param string $file Path to the file
     * @param int $lines Number of lines to read
     * @return array<int, string> Array of lines
     */
    private function tail(string $file, int $lines): array
    {
        $result = [];
        $fp = fopen($file, 'r');

        if ($fp === false) {
            return $result;
        }

        $buffer = 4096;
        fseek($fp, -1, SEEK_END);

        if (ftell($fp) === 0) {
            fclose($fp);
            return $result;
        }

        $output = '';
        $chunk = '';

        while (ftell($fp) > 0 && count($result) < $lines) {
            $seek = min(ftell($fp), $buffer);
            fseek($fp, -$seek, SEEK_CUR);
            $readChunk = fread($fp, $seek);
            if ($readChunk === false) {
                break;
            }
            $chunk = $readChunk;
            $output = $chunk . $output;
            $chunkLength = mb_strlen($chunk, '8bit');
            fseek($fp, -$chunkLength, SEEK_CUR);

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

        if ($line === '') {
            return;
        }

        if (str_contains(strtolower($line), 'error')) {
            $this->io()->text("<fg=red>$line</>");
        } elseif (str_contains(strtolower($line), 'warning')) {
            $this->io()->text("<fg=yellow>$line</>");
        } elseif (str_contains(strtolower($line), 'info')) {
            $this->io()->text("<fg=cyan>$line</>");
        } else {
            $this->io()->text($line);
        }
    }
}
