<?php

namespace KVS\CLI\Tests\Support;

use PDO;

final class NativeDeletionFixture
{
    public static function create(string $site, string $kind, string $result, string $engine): PDO
    {
        $db = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($db instanceof \Pdo\Sqlite) {
            $db->createFunction('DATABASE', static fn (): string => 'fixture_database');
        } else {
            $db->sqliteCreateFunction('DATABASE', static fn (): string => 'fixture_database');
        }
        $db->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $db->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, ENGINE TEXT)');
        $db->exec('CREATE TABLE fixture_' . $kind . 's (' . $kind . '_id INTEGER, status_id INTEGER)');
        $db->exec('INSERT INTO fixture_' . $kind . 's VALUES (1, 1)');
        $db->exec('CREATE TABLE fixture_background_tasks (task_id INTEGER PRIMARY KEY, type_id INTEGER, video_id INTEGER, album_id INTEGER)');
        $db->exec('CREATE TABLE fixture_admin_audit_log (object_id INTEGER, object_type_id INTEGER, action_id INTEGER DEFAULT 180)');
        $metadata = $db->prepare('INSERT INTO information_schema.TABLES VALUES (?, ?, ?)');
        foreach ([$kind . 's', 'background_tasks', 'admin_audit_log'] as $table) {
            $metadata->execute(['fixture_database', 'fixture_' . $table, $engine]);
        }
        $GLOBALS['native_deletion_fixture_db'] = $db;
        $GLOBALS['native_deletion_fixture_result'] = $result;
        file_put_contents($site . '/admin/include/setup.php', '<?php $config = ["tables_prefix" => "fixture_"];');
        file_put_contents($site . '/admin/include/functions_base.php', self::functions());
        foreach (['functions.php', 'functions_servers.php', 'functions_admin.php'] as $file) {
            file_put_contents($site . '/admin/include/' . $file, '<?php');
        }
        if ($result === 'status-changed') {
            $statement = 'UPDATE fixture_' . $kind . 's SET status_id=3';
            file_put_contents(
                $site . '/admin/include/functions_admin.php',
                '<?php $GLOBALS["native_deletion_fixture_db"]->exec(' . var_export($statement, true) . ');'
            );
        }
        return $db;
    }

    private static function functions(): string
    {
        return <<<'PHP'
<?php
function sql($query)
{
    $db = $GLOBALS['native_deletion_fixture_db'];
    if ($query === 'START TRANSACTION') {
        return $db->beginTransaction();
    }
    if ($query === 'COMMIT') {
        return $db->commit();
    }
    if ($query === 'ROLLBACK') {
        return $db->rollBack();
    }
    return $db->query($query);
}
function sql_pr($query, ...$parameters)
{
    $query = preg_replace('/\s+FOR UPDATE\s*$/i', '', $query);
    $statement = $GLOBALS['native_deletion_fixture_db']->prepare($query);
    $statement->execute($parameters);
    return $statement;
}
function mr2array($result)
{
    return $result->fetchAll(PDO::FETCH_ASSOC);
}
function mr2number($result)
{
    return (int) $result->fetchColumn();
}
function native_deletion_fixture_delete($kind, $id, $taskType, $objectType)
{
    $prefix = $GLOBALS['config']['tables_prefix'];
    sql_pr('UPDATE ' . $prefix . $kind . 's SET status_id=4 WHERE ' . $kind . '_id=?', $id);
    $result = $GLOBALS['native_deletion_fixture_result'];
    if ($result !== 'missing-audit') {
        sql_pr('INSERT INTO ' . $prefix . 'admin_audit_log (object_id, object_type_id) VALUES (?, ?)', $id, $objectType);
    }
    if ($result === 'throw') {
        throw new RuntimeException('Native deletion failed after updating the content');
    }
    if (in_array($result, ['queued', 'status-changed', 'missing-audit'], true)) {
        sql_pr('INSERT INTO ' . $prefix . 'background_tasks (task_id, type_id, ' . $kind . '_id) VALUES (1, ?, ?)', $taskType, $id);
    }
    return $result !== 'false';
}
function delete_video($id)
{
    return native_deletion_fixture_delete('video', $id, 2, 1);
}
function delete_album($id)
{
    return native_deletion_fixture_delete('album', $id, 11, 2);
}
PHP;
    }
}
