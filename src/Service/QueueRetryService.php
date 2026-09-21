<?php

namespace KVS\CLI\Service;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Apply the KVS admin restart protocol to one failed active task.
 */
class QueueRetryService
{
    public function __construct(
        private PDO $db,
        private string $prefix,
        private string $notificationPrefix,
        private string $kvsPath
    ) {
    }

    /** @return array<string, int> */
    public function preview(int $taskId, bool $lock = false): array
    {
        $lockClause = $lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare(
            "SELECT task_id, status_id, video_id, album_id, server_id, times_restarted
             FROM {$this->prefix}background_tasks WHERE task_id = :id" . $lockClause
        );
        $stmt->execute(['id' => $taskId]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException("Task $taskId is not in the active queue. Historical tasks cannot be retried.");
        }

        $task = [];
        foreach ($row as $key => $value) {
            if (!is_numeric($value)) {
                throw new RuntimeException("Invalid task field: $key");
            }
            $task[$key] = (int) $value;
        }
        if ($task['status_id'] !== 2) {
            throw new RuntimeException("Task $taskId is not failed. Only failed active tasks can be retried.");
        }
        return $task;
    }

    /** @return array<string, int> State immediately after the restart, before a worker claims the task. */
    public function retry(int $taskId): array
    {
        $lock = $this->acquireRetryLock();
        try {
            return $this->retryWithinTransaction($taskId);
        } finally {
            if ($lock !== null) {
                $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
                $stmt->execute(['name' => $lock]);
            }
        }
    }

    private function acquireRetryLock(): ?string
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return null;
        }
        $stmt = $this->db->query('SELECT DATABASE()');
        $database = $stmt === false ? false : $stmt->fetchColumn();
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('Cannot identify the queue database.');
        }
        // Different task retries also update the same notification. Serialize them before opening a snapshot.
        $name = 'kvs_cli_queue_' . substr(hash('sha256', $database . '|' . $this->prefix), 0, 40);
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, 10)');
        $stmt->execute(['name' => $name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Another queue retry is in progress. Try again shortly. No changes were made.');
        }
        return $name;
    }

    /** @return array<string, int> */
    private function retryWithinTransaction(int $taskId): array
    {
        /** @var list<array{handle: resource, path: string, size: int<0, max>, created: bool}> $logs */
        $logs = [];
        $committed = false;
        $this->db->beginTransaction();
        try {
            $task = $this->preview($taskId, true);
            $this->requireTransactionalTables($task);
            $logNames = ["tasks/$taskId.txt"];
            foreach (['video' => 'videos', 'album' => 'albums'] as $type => $table) {
                $contentId = $task[$type . '_id'];
                if ($contentId > 0) {
                    $logNames[] = "$table/$contentId.txt";
                }
            }
            // Open all logs before changing state; retain locks until commit or rollback.
            foreach ($logNames as $name) {
                $logs[] = $this->openLog($name);
            }

            foreach (['video' => 'videos', 'album' => 'albums'] as $type => $table) {
                $contentId = $task[$type . '_id'];
                if ($contentId > 0) {
                    $stmt = $this->db->prepare(
                        "UPDATE {$this->prefix}{$table} SET status_id = 3
                         WHERE {$type}_id = :id AND status_id = 2"
                    );
                    $stmt->execute(['id' => $contentId]);
                }
            }

            // KVS deliberately retains error_code, data, priority, and timestamps on a manual restart.
            $stmt = $this->db->prepare(
                "UPDATE {$this->prefix}background_tasks
                 SET status_id = 0, last_server_id = server_id, server_id = 0,
                     times_restarted = times_restarted + 1, message = ''
                 WHERE task_id = :id AND status_id = 2"
            );
            $stmt->execute(['id' => $taskId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Task state changed. No restart was applied.');
            }
            $this->syncFailureNotification();

            $message = "\n" . date('[Y-m-d H:i:s] ') . "INFO  Restarted task manually via kvs-cli\n";
            foreach ($logs as $log) {
                if (fwrite($log['handle'], $message) !== strlen($message) || !fflush($log['handle'])) {
                    throw new RuntimeException('Could not write restart log: ' . $log['path']);
                }
            }
            $this->db->commit();
            $committed = true;

            return array_replace($task, [
                'status_id' => 0,
                'last_server_id' => $task['server_id'],
                'server_id' => 0,
                'times_restarted' => $task['times_restarted'] + 1,
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } finally {
            foreach (array_reverse($logs) as $log) {
                if (!$committed) {
                    ftruncate($log['handle'], $log['size']);
                    fflush($log['handle']);
                    if ($log['created']) {
                        unlink($log['path']);
                    }
                }
                flock($log['handle'], LOCK_UN);
                fclose($log['handle']);
            }
        }
    }

    /** @return array{handle: resource, path: string, size: int<0, max>, created: bool} */
    private function openLog(string $name): array
    {
        $root = realpath($this->kvsPath . '/admin/logs');
        $directory = realpath($this->kvsPath . '/admin/logs/' . dirname($name));
        if ($root === false || $directory === false || !str_starts_with($directory . '/', $root . '/')) {
            throw new RuntimeException('KVS log directory is missing or outside admin/logs: ' . dirname($name));
        }
        $path = $directory . '/' . basename($name);
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('KVS log must be a regular file: ' . $path);
        }
        $created = !file_exists($path);
        $handle = @fopen($path, $created ? 'x+b' : 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot open KVS log for writing: ' . $path);
        }
        if (!flock($handle, LOCK_EX | LOCK_NB) || fseek($handle, 0, SEEK_END) !== 0) {
            fclose($handle);
            if ($created) {
                unlink($path);
            }
            throw new RuntimeException('Cannot lock KVS log: ' . $path);
        }
        $size = ftell($handle);
        if ($size === false || $size < 0) {
            fclose($handle);
            if ($created) {
                unlink($path);
            }
            throw new RuntimeException('Cannot read KVS log position: ' . $path);
        }
        return ['handle' => $handle, 'path' => $path, 'size' => $size, 'created' => $created];
    }

    private function syncFailureNotification(): void
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM {$this->prefix}background_tasks WHERE status_id = 2");
        if ($stmt === false) {
            throw new RuntimeException('Cannot count failed tasks.');
        }
        $count = (int) $stmt->fetchColumn();
        $table = $this->notificationPrefix . 'admin_notifications';
        $notification = 'administration.background_tasks.failure';
        if ($count === 0) {
            $stmt = $this->db->prepare("DELETE FROM {$table} WHERE notification_id = :id");
            $stmt->execute(['id' => $notification]);
            return;
        }
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $upsert = $driver === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE objects = VALUES(objects), details = VALUES(details)'
            : 'ON CONFLICT(notification_id) DO UPDATE SET objects = excluded.objects, details = excluded.details';
        $stmt = $this->db->prepare(
            "INSERT INTO {$table} (notification_id, objects, details) VALUES (:id, :objects, :details) $upsert"
        );
        $stmt->execute(['id' => $notification, 'objects' => $count, 'details' => '[]']);
    }

    /** @param array<string, int> $task */
    private function requireTransactionalTables(array $task): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $tables = [$this->prefix . 'background_tasks', $this->notificationPrefix . 'admin_notifications'];
        foreach (['video' => 'videos', 'album' => 'albums'] as $type => $table) {
            if ($task[$type . '_id'] > 0) {
                $tables[] = $this->prefix . $table;
            }
        }
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $stmt = $this->db->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)"
        );
        $stmt->execute($tables);
        /** @var array<string, string|null> $engines */
        $engines = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($tables as $table) {
            if (strtolower($engines[$table] ?? '') !== 'innodb') {
                throw new RuntimeException("Retry requires an InnoDB table to support rollback: $table. No changes were made.");
            }
        }
    }
}
