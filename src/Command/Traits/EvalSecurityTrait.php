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
     * Get mysqli-based bootstrap code that defines Model and DB helper classes.
     *
     * This code is eval'd to provide convenient database access in eval commands.
     * It defines: Video, User, Album, Category, Tag, DVD, Model_ classes and DB helper.
     */
    private function getEvalBootstrapCode(string $tablePrefix): string
    {
        $code = <<<'PHP'
// mysqli-based model classes for convenience
if (!class_exists('Model')) {
    class Model {
        protected static $table;
        protected static $db; // mysqli instance
        protected static $prefix = 'ktvs_'; // Will be replaced by str_replace

        public static function setDb($connection) {
            self::$db = $connection;
        }

        public static function find($id) {
            if (!self::$db || !static::$table) return null;
            $sql = "SELECT * FROM " . static::$table . " WHERE " . static::getIdColumn() . " = " . (int)$id;
            $result = mysqli_query(self::$db, $sql);
            if ($result === false) {
                echo "Query error: " . mysqli_error(self::$db) . "\n";
                return null;
            }
            return mysqli_fetch_assoc($result) ?: null;
        }

        public static function all($limit = 10) {
            if (!self::$db || !static::$table) return [];
            $result = mysqli_query(self::$db, "SELECT * FROM " . static::$table . " LIMIT " . (int)$limit);
            if ($result === false) {
                echo "Query error: " . mysqli_error(self::$db) . "\n";
                return [];
            }
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
            if ($result === false) {
                echo "Query error: " . mysqli_error(self::$db) . "\n";
                return 0;
            }
            $row = mysqli_fetch_assoc($result);
            return (int)($row['total'] ?? 0);
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

// mysqli-based database helper
if (!class_exists('DB')) {
    class DB {
        private static $connection; // mysqli instance

        public static function setConnection($connection) {
            self::$connection = $connection;
        }

        public static function query($sql, $params = []) {
            if (!self::$connection) {
                echo "No database connection\n";
                return false;
            }
            if ($params !== []) {
                $stmt = mysqli_prepare(self::$connection, $sql);
                if ($stmt === false) {
                    echo "Query error: " . mysqli_error(self::$connection) . "\n";
                    return false;
                }
                $types = '';
                $values = [];
                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } elseif (is_float($param)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                    $values[] = $param;
                }
                $refs = [];
                foreach ($values as $key => $value) {
                    $refs[$key] = &$values[$key];
                }
                if (!mysqli_stmt_bind_param($stmt, $types, ...$refs)) {
                    echo "Query error: " . mysqli_stmt_error($stmt) . "\n";
                    return false;
                }
                if (!mysqli_stmt_execute($stmt)) {
                    echo "Query error: " . mysqli_stmt_error($stmt) . "\n";
                    return false;
                }
                $result = mysqli_stmt_get_result($stmt);
                if ($result === false) {
                    return mysqli_stmt_affected_rows($stmt);
                }
                $data = [];
                while ($row = mysqli_fetch_assoc($result)) {
                    $data[] = $row;
                }
                return $data;
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
            return mysqli_real_escape_string(self::$connection, (string)$value);
        }

        public static function exec($sql) {
            if (!self::$connection) {
                echo "No database connection\n";
                return false;
            }
            $result = mysqli_query(self::$connection, $sql);
            if ($result === false) {
                echo "Query error: " . mysqli_error(self::$connection) . "\n";
                return false;
            }
            return mysqli_affected_rows(self::$connection);
        }

        public static function lastId() {
            if (!self::$connection) return null;
            return mysqli_insert_id(self::$connection);
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
