<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Service\QueueRetryService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/** @group integration */
class QueueRetryMariaDbIntegrationTest extends TestCase
{
    public function testNativeRestartRollbackAndUnsupportedEngines(): void
    {
        if (getenv('KVS_CLI_TEST_QUEUE_MARIADB') !== '1') {
            self::markTestSkipped('Set KVS_CLI_TEST_QUEUE_MARIADB=1 to run this MariaDB contract test.');
        }

        $config = TestHelper::getDbConfig();
        $db = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $prefix = 'queue_contract_' . bin2hex(random_bytes(5)) . '_';
        $sharedPrefix = $prefix . 'shared_';
        $tasks = $prefix . 'background_tasks';
        $videos = $prefix . 'videos';
        $notifications = $sharedPrefix . 'admin_notifications';
        $tables = [$tasks, $videos, $notifications];
        $path = TestHelper::createTempDir('queue-mariadb-');
        foreach (['tasks', 'videos'] as $directory) {
            mkdir($path . '/admin/logs/' . $directory, 0755, true);
        }
        $service = new QueueRetryService($db, $prefix, $sharedPrefix, $path);

        try {
            $db->exec("CREATE TABLE {$tasks} (
                task_id INT PRIMARY KEY, status_id INT NOT NULL, video_id INT NOT NULL DEFAULT 0,
                album_id INT NOT NULL DEFAULT 0, server_id INT NOT NULL DEFAULT 0,
                last_server_id INT NOT NULL DEFAULT 0, times_restarted INT NOT NULL DEFAULT 0,
                message TEXT NOT NULL, error_code INT NOT NULL DEFAULT 0,
                data TEXT NOT NULL, priority INT NOT NULL DEFAULT 10
            ) ENGINE=InnoDB");
            $db->exec("CREATE TABLE {$videos} (video_id INT PRIMARY KEY, status_id INT NOT NULL) ENGINE=InnoDB");
            $db->exec("CREATE TABLE {$notifications} (
                notification_id VARCHAR(100) PRIMARY KEY, objects INT NOT NULL, details TEXT NOT NULL
            ) ENGINE=InnoDB");
            $db->exec("INSERT INTO {$tasks} (task_id,status_id,video_id,server_id,message,error_code,data)
                VALUES (11,2,21,8,'Original failure',9,'original payload'),(12,2,22,8,'Other failure',7,'other payload')");
            $db->exec("INSERT INTO {$videos} VALUES (21,2),(22,2)");
            $db->exec("INSERT INTO {$notifications} VALUES ('administration.background_tasks.failure',2,'[]')");
            file_put_contents($path . '/admin/logs/tasks/11.txt', "Original log\n");
            $before = $this->snapshot($db, $tables);

            // Every affected table must be transactional, including shared notifications.
            foreach ($tables as $table) {
                $db->exec("ALTER TABLE {$table} ENGINE=MyISAM");
                try {
                    $service->retry(11);
                    self::fail('A non-transactional table must be refused before mutation.');
                } catch (RuntimeException $e) {
                    self::assertStringContainsString('InnoDB', $e->getMessage());
                }
                self::assertSame($before, $this->snapshot($db, $tables));
                self::assertSame("Original log\n", file_get_contents($path . '/admin/logs/tasks/11.txt'));
                self::assertFileDoesNotExist($path . '/admin/logs/videos/21.txt');
                self::assertFalse($db->inTransaction());
                $db->exec("ALTER TABLE {$table} ENGINE=InnoDB");
            }

            // Fail after task and content updates, so the real database must roll them back.
            $trigger = $prefix . 'fail_notification';
            $db->exec("CREATE TRIGGER {$trigger} BEFORE UPDATE ON {$notifications} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Forced notification failure'");
            try {
                $service->retry(11);
                self::fail('The forced database failure must abort the restart.');
            } catch (\PDOException $e) {
                self::assertStringContainsString('Forced notification failure', $e->getMessage());
            } finally {
                $db->exec("DROP TRIGGER {$trigger}");
            }
            self::assertSame($before, $this->snapshot($db, $tables));
            self::assertSame("Original log\n", file_get_contents($path . '/admin/logs/tasks/11.txt'));
            self::assertFileDoesNotExist($path . '/admin/logs/videos/21.txt');
            self::assertFalse($db->inTransaction());

            $result = $service->retry(11);
            self::assertSame(0, $result['status_id']);
            self::assertSame(8, $result['last_server_id']);
            self::assertSame(1, $result['times_restarted']);
            $state = $this->snapshot($db, $tables);
            self::assertSame(3, (int) $state[$videos][0]['status_id']);
            self::assertSame(2, (int) $state[$videos][1]['status_id']);
            self::assertSame(1, (int) $state[$notifications][0]['objects']);
            self::assertSame('original payload', $state[$tasks][0]['data']);
            self::assertSame(9, (int) $state[$tasks][0]['error_code']);
            self::assertSame('', $state[$tasks][0]['message']);
            self::assertStringContainsString('Restarted task manually', file_get_contents($path . '/admin/logs/videos/21.txt'));
            try {
                $service->retry(11);
                self::fail('A repeated restart must be refused.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('not failed', $e->getMessage());
            }
            self::assertSame($state, $this->snapshot($db, $tables));
            $service->retry(12);
            self::assertSame([], $this->snapshot($db, $tables)[$notifications]);

            $db->exec("UPDATE {$tasks} SET status_id=2");
            $db->exec("UPDATE {$videos} SET status_id=2");
            $db->exec("INSERT INTO {$notifications} VALUES ('administration.background_tasks.failure',2,'[]')");
            // Widen the real race around the shared notification, using two independent PHP processes.
            $db->exec("CREATE TRIGGER {$trigger} BEFORE UPDATE ON {$notifications} FOR EACH ROW DO SLEEP(0.25)");
            $this->retryConcurrently($config, $prefix, $sharedPrefix, $path);
            $state = $this->snapshot($db, $tables);
            self::assertSame([], $state[$notifications]);
            foreach ($state[$tasks] as $task) {
                self::assertSame(0, (int) $task['status_id']);
                self::assertSame(2, (int) $task['times_restarted']);
            }
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $db->exec('DROP TABLE IF EXISTS ' . implode(', ', $tables));
            TestHelper::removeDir($path);
        }
    }

    /** @param array{host: string, port: int, user: string, pass: string, database: string} $config */
    private function retryConcurrently(array $config, string $prefix, string $sharedPrefix, string $path): void
    {
        $script = <<<'PHP'
require $argv[1];
$config = json_decode(getenv('QUEUE_CONTRACT_DB'), true, flags: JSON_THROW_ON_ERROR);
$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
    $config['user'],
    $config['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$service = new KVS\CLI\Service\QueueRetryService($db, $argv[2], $argv[3], $argv[4]);
echo json_encode($service->retry((int) $argv[5]), JSON_THROW_ON_ERROR);
PHP;
        $processes = [];
        try {
            foreach ([11, 12] as $id) {
                $process = new Process(
                    [PHP_BINARY, '-r', $script, dirname(__DIR__) . '/vendor/autoload.php', $prefix, $sharedPrefix, $path, (string) $id],
                    env: ['QUEUE_CONTRACT_DB' => json_encode($config, JSON_THROW_ON_ERROR)],
                    timeout: 20
                );
                $processes[] = $process;
                $process->start();
            }
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(0, $result['status_id']);
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    /**
     * @param list<string> $tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(PDO $db, array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $stmt = $db->query("SELECT * FROM {$table} ORDER BY 1");
            self::assertNotFalse($stmt);
            $result[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $result;
    }
}
