<?php

namespace KVS\CLI\Command\Traits;

use KVS\CLI\Constants;

/**
 * Provides environment-based security checks and bootstrap code for eval commands.
 *
 * Used by EvalCommand and EvalFileCommand to prevent accidental
 * code execution in production environments.
 */
trait EvalSecurityTrait
{
    /**
     * Check if eval is allowed in the current environment.
     *
     * Returns true if:
     * - KVS_ENV is 'dev', 'development', 'test', or not set (defaults to dev)
     * - OR KVS_ALLOW_EVAL is explicitly set to 'true' or '1'
     *
     * This prevents accidental execution in production environments.
     */
    private function isEvalAllowed(): bool
    {
        // Explicit override takes precedence
        $allowEval = getenv('KVS_ALLOW_EVAL');
        if ($allowEval === 'true' || $allowEval === '1') {
            return true;
        }

        // Check environment - default to allowing (dev mode)
        $env = getenv('KVS_ENV');
        if ($env === false || $env === '') {
            // No environment set - assume dev, allow eval
            return true;
        }

        // Allow in dev/test environments
        $allowedEnvs = ['dev', 'development', 'test', 'testing', 'local'];
        return in_array(strtolower($env), $allowedEnvs, true);
    }

    /**
     * Get PDO-based bootstrap code that defines Model and DB helper classes.
     *
     * This code is eval'd to provide convenient database access in eval commands.
     * It defines: Video, User, Album, Category, Tag, DVD, Model_ classes and DB helper.
     */
    private function getEvalBootstrapCode(string $tablePrefix): string
    {
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
        return str_replace(Constants::DEFAULT_TABLE_PREFIX, $tablePrefix, $code);
    }
}
