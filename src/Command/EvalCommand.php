<?php

namespace KVS\CLI\Command;

use KVS\CLI\Constants;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'eval',
    description: 'Execute PHP code with KVS context loaded',
    aliases: ['eval-php']
)]
class EvalCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'PHP code to execute')
            ->addOption('skip-kvs', null, InputOption::VALUE_NONE, 'Skip loading KVS context')
            ->setHelp(<<<'HELP'
The <info>eval</info> command executes PHP code with the KVS context pre-loaded.

<comment>Usage:</comment>
  kvs eval 'echo "Hello World";'
  kvs eval 'return Video::count();'
  kvs eval 'var_dump(Video::find(1));'

<comment>Examples:</comment>
  # Simple echo
  kvs eval 'echo "KVS Path: " . $kvsPath;'

  # Database query
  kvs eval 'print_r(DB::query("SHOW TABLES"));'

  # Using models (table prefix is auto-configured)
  kvs eval 'echo "Total videos: " . Video::count();'

  # Return value (will be var_dumped)
  kvs eval 'return ["videos" => Video::count(), "users" => User::count()];'

<comment>Available variables:</comment>
  $kvsConfig - KVS configuration
  $kvsPath   - KVS installation path
  $db        - Database connection

<comment>Available classes:</comment>
  Video, User, Album, Category, Tag, DVD, Model_
  DB::query(), DB::escape()
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = $input->getArgument('code');
        $skipKvs = $input->getOption('skip-kvs');

        if ($skipKvs === null || $skipKvs === false) {
            // Prepare KVS context variables
            $kvsPath = $this->config->getKvsPath();
            $kvsConfig = $this->config;

            // Get database connection (PDO)
            $db = $this->getDatabaseConnection();
            if ($db === null) {
                $this->io()->warning('Database connection not available');
            }

            // Get config array for easier access
            $dbConfig = $this->config->getDatabaseConfig();
            $config = [
                'project_path' => $kvsPath,
                'project_version' => $this->config->get('project_version', 'unknown'),
                'db_host' => $dbConfig['host'] ?? null,
                'db_name' => $dbConfig['database'] ?? null,
            ];

            // Load bootstrap (this defines Model and DB classes with PDO)
            $bootstrap = $this->getBootstrapCode($db);
            eval($bootstrap);

            // Initialize Model and DB helpers with PDO connection
            // Must use global namespace since classes are defined in eval()
            if ($db !== null) {
                // @phpstan-ignore-next-line - Model and DB classes are dynamically created in bootstrap code
                \Model::setDb($db);
                // @phpstan-ignore-next-line - Model and DB classes are dynamically created in bootstrap code
                \DB::setConnection($db);
            }

            // IMPORTANT: Extract variables into current scope so eval() can access them
            // This makes $kvsPath, $kvsConfig, $db, $config, $dbConfig available in user's eval()
            extract(compact('kvsPath', 'kvsConfig', 'db', 'config', 'dbConfig'));
        }

        // Execute the user code
        $result = null;
        ob_start();
        $errorOccurred = false;

        try {
            $result = eval($code);
        } catch (\ParseError $e) {
            $errorOccurred = true;
            $this->io()->error('Parse Error: ' . $e->getMessage());
            if ($this->io()->isVerbose()) {
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

        $outputStr = ob_get_clean();

        // Display output
        if ($outputStr !== false && $outputStr !== '') {
            $this->io()->write($outputStr);
        }

        // Display return value if any
        if (!$errorOccurred && $result !== null) {
            if (is_bool($result)) {
                $this->io()->writeln($result ? 'true' : 'false');
            } elseif (is_scalar($result)) {
                $this->io()->writeln((string)$result);
            } else {
                var_dump($result);
            }
        }

        return $errorOccurred ? self::FAILURE : self::SUCCESS;
    }

    private function getBootstrapCode(?\PDO $db = null): string
    {
        $prefix = $this->config->getTablePrefix();
        $code = <<<'PHP'
// PDO-based model classes for convenience
if (!class_exists('Model')) {
    class Model {
        protected static $table;
        protected static $db; // PDO instance
        protected static $prefix = 'ktvs_'; // Will be replaced by str_replace

        public static function setDb($pdo) {
            self::$db = $pdo;
        }

        public static function find($id) {
            if (!self::$db || !static::$table) return null;
            try {
                $stmt = self::$db->prepare("SELECT * FROM " . static::$table . " WHERE " . static::getIdColumn() . " = ?");
                $stmt->execute([(int)$id]);
                return $stmt->fetch();
            } catch (\PDOException $e) {
                echo "Query error: " . $e->getMessage() . "\n";
                return null;
            }
        }

        public static function all($limit = 10) {
            if (!self::$db || !static::$table) return [];
            try {
                // Note: PDO doesn't support binding LIMIT as parameter in some drivers
                $sql = "SELECT * FROM " . static::$table . " LIMIT " . (int)$limit;
                $stmt = self::$db->query($sql);
                return $stmt->fetchAll();
            } catch (\PDOException $e) {
                echo "Query error: " . $e->getMessage() . "\n";
                return [];
            }
        }

        public static function count($where = '') {
            if (!self::$db || !static::$table) return 0;
            try {
                $sql = "SELECT COUNT(*) as total FROM " . static::$table;
                if ($where) $sql .= " WHERE $where";
                $stmt = self::$db->query($sql);
                return (int)$stmt->fetchColumn();
            } catch (\PDOException $e) {
                echo "Query error: " . $e->getMessage() . "\n";
                return 0;
            }
        }

        // Helper to get primary key column name
        protected static function getIdColumn() {
            // Most KVS tables use <singular>_id pattern
            $tableName = static::$table;
            $prefixLen = strlen(self::$prefix);
            if (str_starts_with($tableName, self::$prefix)) {
                $singular = rtrim(substr($tableName, $prefixLen), 's');
                return $singular . '_id';
            }
            return 'id';
        }
    }

    class Video extends Model {
        protected static $table = 'ktvs_videos';
        protected static function getIdColumn() { return 'video_id'; }
    }
    class User extends Model {
        protected static $table = 'ktvs_users';
        protected static function getIdColumn() { return 'user_id'; }
    }
    class Album extends Model {
        protected static $table = 'ktvs_albums';
        protected static function getIdColumn() { return 'album_id'; }
    }
    class Category extends Model {
        protected static $table = 'ktvs_categories';
        protected static function getIdColumn() { return 'category_id'; }
    }
    class Tag extends Model {
        protected static $table = 'ktvs_tags';
        protected static function getIdColumn() { return 'tag_id'; }
    }
    class DVD extends Model {
        protected static $table = 'ktvs_dvds';
        protected static function getIdColumn() { return 'dvd_id'; }
    }
    class Model_ extends Model {
        protected static $table = 'ktvs_models';
        protected static function getIdColumn() { return 'model_id'; }
    }
}

// PDO-based database helper
if (!class_exists('DB')) {
    class DB {
        private static $connection; // PDO instance

        public static function setConnection($pdo) {
            self::$connection = $pdo;
        }

        public static function query($sql, $params = []) {
            if (!self::$connection) {
                echo "No database connection\n";
                return false;
            }
            try {
                if ($params === []) {
                    $stmt = self::$connection->query($sql);
                } else {
                    $stmt = self::$connection->prepare($sql);
                    $stmt->execute($params);
                }
                return $stmt->fetchAll();
            } catch (\PDOException $e) {
                echo "Query error: " . $e->getMessage() . "\n";
                return false;
            }
        }

        public static function escape($value) {
            if (!self::$connection) return $value;
            return self::$connection->quote($value);
        }

        public static function exec($sql) {
            if (!self::$connection) {
                echo "No database connection\n";
                return false;
            }
            try {
                return self::$connection->exec($sql);
            } catch (\PDOException $e) {
                echo "Query error: " . $e->getMessage() . "\n";
                return false;
            }
        }
    }
}

// Auto-initialize if $db variable is available
if (isset($db) && $db) {
    Model::setDb($db);
    DB::setConnection($db);
}
PHP;
        // Replace default prefix placeholder with configured prefix
        return str_replace(Constants::DEFAULT_TABLE_PREFIX, $prefix, $code);
    }
}
