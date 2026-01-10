<?php

namespace KVS\CLI\Command;

use KVS\CLI\Command\Traits\EvalSecurityTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'eval-file',
    description: 'Execute a PHP file with KVS context loaded'
)]
class EvalFileCommand extends BaseCommand
{
    use EvalSecurityTrait;

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'PHP file to execute')
            ->addOption('skip-kvs', null, InputOption::VALUE_NONE, 'Skip loading KVS context')
            ->addOption('args', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Arguments to pass to the script')
            ->setHelp(<<<'HELP'
The <info>eval-file</info> command executes a PHP file with the KVS context pre-loaded.

<comment>Usage:</comment>
  kvs eval-file script.php
  kvs eval-file maintenance.php --args="cleanup" --args="verbose"

<comment>Examples:</comment>
  # Execute a simple script
  kvs eval-file cleanup.php

  # Pass arguments to the script
  kvs eval-file migrate.php --args="videos" --args="--dry-run"

  # Skip KVS context loading
  kvs eval-file test.php --skip-kvs

<comment>Available in script:</comment>
  $kvsConfig - KVS configuration
  $kvsPath   - KVS installation path
  $db        - Database connection
  $argv      - Script arguments

<comment>Available classes:</comment>
  Video, User, Album, Category, Tag, DVD, Model_
  DB::query(), DB::escape()
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Security: Block eval-file in production unless explicitly allowed
        if (!$this->isEvalAllowed()) {
            $this->io()->error('Eval-file command is disabled in production environment.');
            $this->io()->text('Set KVS_ALLOW_EVAL=true to override, or use KVS_ENV=dev');
            return self::FAILURE;
        }

        $file = $this->getStringArgument($input, 'file');
        if ($file === null) {
            $this->io()->error('File argument is required');
            return self::FAILURE;
        }

        $skipKvs = $this->getBoolOption($input, 'skip-kvs');
        $args = $this->getArrayOption($input, 'args');

        // Check if file exists
        if (!file_exists($file)) {
            // Try relative to KVS path
            $altFile = $this->config->getKvsPath() . '/' . $file;
            if (file_exists($altFile)) {
                $file = $altFile;
            } else {
                $this->io()->error("File not found: $file");
                return self::FAILURE;
            }
        }

        if (!is_readable($file)) {
            $this->io()->error("File is not readable: $file");
            return self::FAILURE;
        }

        $this->io()->info("Executing: $file");
        if ($args !== []) {
            $this->io()->comment('Arguments: ' . implode(' ', $args));
        }

        // Set up script arguments
        $originalArgv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = array_merge([$file], $args);
        $_SERVER['argc'] = count($_SERVER['argv']);
        $argv = $_SERVER['argv'];
        $argc = $_SERVER['argc'];

        if (!$skipKvs) {
            // Load KVS context
            $kvsPath = $this->config->getKvsPath();
            $kvsConfig = $this->config;
            $config = $this->config; // Alias for compatibility

            // Get database connection
            $db = $this->getDatabaseConnection();
            if ($db === null) {
                $this->io()->warning('Database connection not available');
            }

            // Load bootstrap (this defines Model and DB classes with PDO)
            $bootstrap = $this->getEvalBootstrapCode($this->config->getTablePrefix());
            eval($bootstrap);
        }

        // Execute the file
        $errorOccurred = false;
        $result = null;
        ob_start();

        try {
            $result = include $file;
        } catch (\ParseError $e) {
            $errorOccurred = true;
            $this->io()->error('Parse Error in ' . basename($file) . ': ' . $e->getMessage());
            if ($this->io()->isVerbose()) {
                $this->io()->text('Line ' . $e->getLine() . ': ' . $this->getFileLine($file, $e->getLine()));
                $this->io()->text($e->getTraceAsString());
            }
        } catch (\Exception $e) {
            $errorOccurred = true;
            $this->io()->error('Error: ' . $e->getMessage());
            if ($this->io()->isVerbose()) {
                $this->io()->text($e->getTraceAsString());
            }
        } catch (\Error $e) {
            $errorOccurred = true;
            $this->io()->error('Fatal Error: ' . $e->getMessage());
            if ($this->io()->isVerbose()) {
                $this->io()->text($e->getTraceAsString());
            }
        }

        $capturedOutput = ob_get_clean();

        // Display output
        if ($capturedOutput !== false && $capturedOutput !== '') {
            $this->io()->write($capturedOutput);
        }

        // Display return value if script returned something
        if (!$errorOccurred && $result !== null && $result !== 1 && $result !== true) {
            $this->io()->section('Script returned');
            if (is_scalar($result)) {
                $this->io()->writeln((string)$result);
            } else {
                var_dump($result);
            }
        }

        // Restore original argv
        $_SERVER['argv'] = $originalArgv;
        $_SERVER['argc'] = is_countable($originalArgv) ? count($originalArgv) : 0;

        return $errorOccurred ? self::FAILURE : self::SUCCESS;
    }

    private function getFileLine(string $file, int $lineNumber): string
    {
        $lines = file($file);
        if (isset($lines[$lineNumber - 1])) {
            return trim($lines[$lineNumber - 1]);
        }
        return '';
    }
}
