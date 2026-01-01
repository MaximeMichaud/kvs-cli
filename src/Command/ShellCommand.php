<?php

namespace KVS\CLI\Command;

use KVS\CLI\Service\TempFileManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psy\Configuration;
use Psy\Shell;

#[AsCommand(
    name: 'shell',
    description: 'Interactive PHP shell with KVS context loaded',
    aliases: ['console', 'repl']
)]
class ShellCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('includes', 'i', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Additional files to include')
            ->addOption('bootstrap', 'b', InputOption::VALUE_REQUIRED, 'Bootstrap file to load')
            ->setHelp(<<<'HELP'
The <info>shell</info> command starts an interactive PHP shell (REPL) with the KVS context pre-loaded.

<comment>Usage:</comment>
  kvs shell                    Start interactive shell
  kvs shell --includes=file.php  Include additional files

<comment>Available in shell:</comment>
  $config    - KVS configuration object
  $db        - Database connection
  DB::       - Database helper class
  Video::    - Video model
  User::     - User model
  Album::    - Album model
  Category:: - Category model

<comment>Examples in shell:</comment>
  >>> $video = Video::find(1)
  >>> $videos = Video::all(5)
  >>> $config->get('project_url')
  >>> User::count()
  >>> help                     # Show PsySH help
  >>> exit                     # Exit the shell
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!class_exists('Psy\Shell')) {
            $this->io()->error('PsySH is not installed. Run: composer require psy/psysh');
            return self::FAILURE;
        }

        $this->io()->title('KVS Interactive Shell');
        $this->io()->info('Loading KVS context...');

        // Get database connection
        $db = $this->getDatabaseConnection();
        if ($db === null) {
            $this->io()->warning('Database connection not available');
        }

        // Prepare shell configuration
        $shellConfig = new Configuration([
            'prompt' => 'kvs>>> ',
            'updateCheck' => 'never',
            'startupMessage' => $this->getStartupMessage(),
        ]);

        // Create bootstrap file content with secure permissions and automatic cleanup
        $bootstrap = $this->createBootstrap($input);
        $tempBootstrap = TempFileManager::createWithContent($bootstrap, 'kvs_shell_', '.php');

        // Include bootstrap
        require $tempBootstrap;

        // Initialize database connections if available
        if ($db !== null) {
            if (class_exists('\\Model')) {
                \Model::setDb($db);
            }
            if (class_exists('\\DB')) {
                \DB::setConnection($db);
            }
        }

        // Set shell variables
        $shell = new Shell($shellConfig);
        $shell->setScopeVariables($this->getShellVariables());

        // Add custom includes
        $includes = $this->getArrayOption($input, 'includes');
        foreach ($includes as $include) {
            if (file_exists($include)) {
                require_once $include;
            }
        }

        // Run the shell
        $shell->run();

        // Note: Temp file cleanup is automatic via TempFileManager shutdown handler

        return self::SUCCESS;
    }

    private function createBootstrap(InputInterface $input): string
    {
        $kvsPath = $this->config->getKvsPath();
        $prefix = $this->config->getTablePrefix();
        $bootstrap = $this->getStringOption($input, 'bootstrap');

        $code = <<<PHP
<?php
// KVS Shell Bootstrap

// Load KVS configuration if available
if (file_exists('$kvsPath/admin/include/setup.php')) {
    \$config = [];
    @include '$kvsPath/admin/include/setup.php';
}

// Simple model classes for convenience
class Model {
    protected static \$table;
    protected static \$db;

    public static function setDb(\$connection) {
        self::\$db = \$connection;
    }

    public static function find(\$id) {
        if (!self::\$db || !static::\$table) return null;
        \$result = mysqli_query(self::\$db, "SELECT * FROM " . static::\$table . " WHERE id = " . (int)\$id);
        return \$result ? mysqli_fetch_assoc(\$result) : null;
    }

    public static function all(\$limit = 10) {
        if (!self::\$db || !static::\$table) return [];
        \$result = mysqli_query(self::\$db, "SELECT * FROM " . static::\$table . " LIMIT " . (int)\$limit);
        \$data = [];
        while (\$row = mysqli_fetch_assoc(\$result)) {
            \$data[] = \$row;
        }
        return \$data;
    }

    public static function count(\$where = '') {
        if (!self::\$db || !static::\$table) return 0;
        \$sql = "SELECT COUNT(*) as total FROM " . static::\$table;
        if (\$where) \$sql .= " WHERE \$where";
        \$result = mysqli_query(self::\$db, \$sql);
        \$row = mysqli_fetch_assoc(\$result);
        return (int)\$row['total'];
    }

    public static function where(\$field, \$value, \$limit = 10) {
        if (!self::\$db || !static::\$table) return [];
        \$sql = sprintf(
            "SELECT * FROM %s WHERE %s = '%s' LIMIT %d",
            static::\$table,
            mysqli_real_escape_string(self::\$db, \$field),
            mysqli_real_escape_string(self::\$db, \$value),
            (int)\$limit
        );
        \$result = mysqli_query(self::\$db, \$sql);
        \$data = [];
        while (\$row = mysqli_fetch_assoc(\$result)) {
            \$data[] = \$row;
        }
        return \$data;
    }
}

class Video extends Model { protected static \$table = '{$prefix}videos'; }
class User extends Model { protected static \$table = '{$prefix}users'; }
class Album extends Model { protected static \$table = '{$prefix}albums'; }
class Category extends Model { protected static \$table = '{$prefix}categories'; }
class Tag extends Model { protected static \$table = '{$prefix}tags'; }
class DVD extends Model { protected static \$table = '{$prefix}dvds'; }
class Model_ extends Model { protected static \$table = '{$prefix}models'; }

// Database helper
class DB {
    private static \$connection;

    public static function setConnection(\$db) {
        self::\$connection = \$db;
    }

    public static function query(\$sql) {
        if (!self::\$connection) {
            echo "No database connection\n";
            return false;
        }
        \$result = mysqli_query(self::\$connection, \$sql);
        if (\$result === false) {
            echo "Query error: " . mysqli_error(self::\$connection) . "\n";
            return false;
        }
        if (\$result === true) {
            return true;
        }
        \$data = [];
        while (\$row = mysqli_fetch_assoc(\$result)) {
            \$data[] = \$row;
        }
        return \$data;
    }

    public static function escape(\$value) {
        if (!self::\$connection) return \$value;
        return mysqli_real_escape_string(self::\$connection, \$value);
    }

    public static function lastId() {
        if (!self::\$connection) return null;
        return mysqli_insert_id(self::\$connection);
    }
}

// Helper functions
if (!function_exists('dd')) {
    function dd(...\$vars) {
        foreach (\$vars as \$var) {
            var_dump(\$var);
        }
        exit;
    }
}

if (!function_exists('dump')) {
    function dump(...\$vars) {
        foreach (\$vars as \$var) {
            var_dump(\$var);
        }
    }
}
PHP;

        // Add custom bootstrap if provided
        if (is_string($bootstrap) && file_exists($bootstrap)) {
            $bootstrapContent = file_get_contents($bootstrap);
            if ($bootstrapContent !== false) {
                $code .= "\n// Custom bootstrap\n" . $bootstrapContent;
            }
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function getShellVariables(): array
    {
        $vars = [
            'kvsConfig' => $this->config,
            'kvsPath' => $this->config->getKvsPath(),
        ];

        $db = $this->getDatabaseConnection(true);
        if ($db !== null) {
            $vars['db'] = $db;
        }

        return $vars;
    }

    private function getStartupMessage(): string
    {
        return <<<MSG
========================================
         KVS Interactive Shell
========================================
PHP \PHP_VERSION on \PHP_OS

Variables:
  \$config - KVS configuration
  \$db     - Database connection

Classes:
  Video, User, Album, Category, Tag, DVD
  DB::query() - Run SQL queries

Type 'help' for PsySH help
Type 'exit' or Ctrl+D to quit
========================================

MSG;
    }
}
