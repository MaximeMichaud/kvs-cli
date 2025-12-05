<?php

namespace KVS\CLI\Command;

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
        $file = $input->getArgument('file');
        $skipKvs = $input->getOption('skip-kvs');
        $args = $input->getOption('args') ?: [];

        // Check if file exists
        if (!file_exists($file)) {
            // Try relative to KVS path
            $altFile = $this->config->getKvsPath() . '/' . $file;
            if (file_exists($altFile)) {
                $file = $altFile;
            } else {
                $this->io->error("File not found: $file");
                return self::FAILURE;
            }
        }

        if (!is_readable($file)) {
            $this->io->error("File is not readable: $file");
            return self::FAILURE;
        }

        $this->io->info("Executing: $file");
        if ($args) {
            $this->io->comment('Arguments: ' . implode(' ', $args));
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

            // Get database connection
            $db = $this->getDatabaseConnection();
            if (!$db) {
                $this->io->warning('Database connection not available');
            }

            // Load bootstrap (this defines Model and DB classes and auto-initializes with $db)
            $bootstrap = $this->getBootstrapCode();
            eval($bootstrap);
        }

        // Execute the file
        $errorOccurred = false;
        ob_start();

        try {
            $result = include $file;
        } catch (\ParseError $e) {
            $errorOccurred = true;
            $this->io->error('Parse Error in ' . basename($file) . ': ' . $e->getMessage());
            if ($this->io->isVerbose()) {
                $this->io->text('Line ' . $e->getLine() . ': ' . $this->getFileLine($file, $e->getLine()));
                $this->io->text($e->getTraceAsString());
            }
        } catch (\Exception $e) {
            $errorOccurred = true;
            $this->io->error('Error: ' . $e->getMessage());
            if ($this->io->isVerbose()) {
                $this->io->text($e->getTraceAsString());
            }
        } catch (\Error $e) {
            $errorOccurred = true;
            $this->io->error('Fatal Error: ' . $e->getMessage());
            if ($this->io->isVerbose()) {
                $this->io->text($e->getTraceAsString());
            }
        }

        $output = ob_get_clean();

        // Display output
        if ($output !== '') {
            $this->io->write($output);
        }

        // Display return value if script returned something
        if (!$errorOccurred && $result !== null && $result !== 1 && $result !== true) {
            $this->io->section('Script returned');
            if (is_scalar($result)) {
                $this->io->writeln((string)$result);
            } else {
                var_dump($result);
            }
        }

        // Restore original argv
        $_SERVER['argv'] = $originalArgv;
        $_SERVER['argc'] = count($originalArgv);

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

    private function getBootstrapCode(): string
    {
        return <<<'PHP'
// Simple model classes for convenience
if (!class_exists('Model')) {
    class Model {
        protected static $table;
        protected static $db;
        
        public static function setDb($connection) {
            self::$db = $connection;
        }
        
        public static function find($id) {
            if (!self::$db || !static::$table) return null;
            $result = mysqli_query(self::$db, "SELECT * FROM " . static::$table . " WHERE id = " . (int)$id);
            return $result ? mysqli_fetch_assoc($result) : null;
        }
        
        public static function all($limit = 10) {
            if (!self::$db || !static::$table) return [];
            $result = mysqli_query(self::$db, "SELECT * FROM " . static::$table . " LIMIT " . (int)$limit);
            $data = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $data[] = $row;
            }
            return $data;
        }
        
        public static function count($where = '') {
            if (!self::$db || !static::$table) return 0;
            $sql = "SELECT COUNT(*) as total FROM " . static::$table;
            if ($where) $sql .= " WHERE $where";
            $result = mysqli_query(self::$db, $sql);
            $row = mysqli_fetch_assoc($result);
            return (int)$row['total'];
        }
    }
    
    class Video extends Model { protected static $table = 'ktvs_videos'; }
    class User extends Model { protected static $table = 'ktvs_users'; }
    class Album extends Model { protected static $table = 'ktvs_albums'; }
    class Category extends Model { protected static $table = 'ktvs_categories'; }
    class Tag extends Model { protected static $table = 'ktvs_tags'; }
    class DVD extends Model { protected static $table = 'ktvs_dvds'; }
    class Model_ extends Model { protected static $table = 'ktvs_models'; }
}

// Database helper
if (!class_exists('DB')) {
    class DB {
        private static $connection;
        
        public static function setConnection($db) {
            self::$connection = $db;
        }
        
        public static function query($sql) {
            if (!self::$connection) {
                echo "No database connection\n";
                return false;
            }
            $result = mysqli_query(self::$connection, $sql);
            if ($result === false) {
                echo "Query error: " . mysqli_error(self::$connection) . "\n";
                return false;
            }
            if ($result === true) {
                return true;
            }
            $data = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $data[] = $row;
            }
            return $data;
        }
        
        public static function escape($value) {
            if (!self::$connection) return $value;
            return mysqli_real_escape_string(self::$connection, $value);
        }
    }
}

// Auto-initialize if $db variable is available
if (isset($db) && $db) {
    Model::setDb($db);
    DB::setConnection($db);
}
PHP;
    }
}
