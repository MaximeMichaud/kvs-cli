<?php

namespace KVS\CLI\Tests;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class QueueTaskActionsTest extends TestCase
{
    private string $path;
    private PDO $db;
    private QueueActionTestCommand $command;
    private CommandTester $tester;
    private const PREFIX = 'queue_test_';
    private const NOTIFICATION_PREFIX = 'satellite_test_';

    protected function setUp(): void
    {
        $this->path = TestHelper::createTestKvsInstallation([
            'tables_prefix' => self::PREFIX,
            'tables_prefix_multi' => self::NOTIFICATION_PREFIX,
        ]);
        foreach (['tasks', 'videos', 'albums'] as $directory) {
            $path = $this->path . '/admin/logs/' . $directory;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
        $this->db = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $prefix = self::PREFIX;
        $this->db->exec("CREATE TABLE {$prefix}background_tasks (
            task_id INTEGER PRIMARY KEY, status_id INTEGER, video_id INTEGER DEFAULT 0,
            album_id INTEGER DEFAULT 0, server_id INTEGER DEFAULT 0, last_server_id INTEGER DEFAULT 0,
            times_restarted INTEGER DEFAULT 0, message TEXT DEFAULT '', error_code INTEGER DEFAULT 0,
            data TEXT DEFAULT '', priority INTEGER DEFAULT 10, start_date TEXT DEFAULT 'original',
            type_id INTEGER DEFAULT 1)");
        $this->db->exec("CREATE TABLE {$prefix}background_tasks_history AS SELECT * FROM {$prefix}background_tasks WHERE 0");
        $this->db->exec("CREATE TABLE {$prefix}admin_conversion_servers (server_id INTEGER, title TEXT)");
        $this->db->exec("CREATE TABLE {$prefix}videos (video_id INTEGER PRIMARY KEY, status_id INTEGER)");
        $this->db->exec("CREATE TABLE {$prefix}albums (album_id INTEGER PRIMARY KEY, status_id INTEGER)");
        $this->db->exec('CREATE TABLE ' . self::NOTIFICATION_PREFIX . 'admin_notifications
            (notification_id TEXT PRIMARY KEY, objects INTEGER, details TEXT)');
        $this->db->exec("INSERT INTO {$prefix}background_tasks
            (task_id, status_id, video_id, server_id, times_restarted, message, error_code, data)
            VALUES (11, 2, 21, 8, 2, 'Original conversion error', 7, 'original payload')");
        $this->db->exec("INSERT INTO {$prefix}videos VALUES (21, 2)");
        $this->command = new QueueActionTestCommand(TestHelper::createTestConfiguration($this->path), $this->db);
        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->path);
    }

    public function testRetryPreservesNativeStateAndWritesLogs(): void
    {
        $this->seedNotification(1);
        file_put_contents($this->path . '/admin/logs/tasks/11.txt', "Original task log\n");
        $result = $this->runAction('retry', ['--yes' => true]);
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertSame('retried', $result['outcome']);
        $row = $this->activeTask();
        $this->assertSame(0, $row['status_id']);
        $this->assertSame(0, $row['server_id']);
        $this->assertSame(8, $row['last_server_id']);
        $this->assertSame(3, $row['times_restarted']);
        $this->assertSame('', $row['message']);
        $this->assertSame(7, $row['error_code']);
        $this->assertSame('original payload', $row['data']);
        $this->assertSame('original', $row['start_date']);
        $this->assertSame(3, (int) $this->db->query('SELECT status_id FROM ' . self::PREFIX . 'videos')->fetchColumn());
        $this->assertSame(0, $this->notificationCount());
        $this->assertStringStartsWith("Original task log\n", file_get_contents($this->path . '/admin/logs/tasks/11.txt'));
        foreach (['tasks/11.txt', 'videos/21.txt'] as $log) {
            $this->assertStringContainsString('Restarted task manually via kvs-cli', file_get_contents($this->path . '/admin/logs/' . $log));
        }
    }

    public function testRetryAlbumAndNotificationCount(): void
    {
        $prefix = self::PREFIX;
        $this->db->exec("UPDATE {$prefix}background_tasks SET video_id=0, album_id=31 WHERE task_id=11");
        $this->db->exec("INSERT INTO {$prefix}albums VALUES (31, 2)");
        $this->db->exec("INSERT INTO {$prefix}background_tasks (task_id,status_id) VALUES (12,2)");
        $this->seedNotification(2);
        $this->runAction('retry', ['--yes' => true]);
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertSame(3, (int) $this->db->query("SELECT status_id FROM {$prefix}albums")->fetchColumn());
        $this->assertSame(1, (int) $this->db->query('SELECT objects FROM ' . self::NOTIFICATION_PREFIX . 'admin_notifications')->fetchColumn());
        $this->assertFileExists($this->path . '/admin/logs/albums/31.txt');
        $this->assertSame(2, $this->activeTask(12)['status_id']);
    }

    public function testRetryDoesNotChangeAnActiveVideoOrDuplicateTheRestart(): void
    {
        $this->db->exec('UPDATE ' . self::PREFIX . 'videos SET status_id=1');
        $this->runAction('retry', ['--yes' => true]);
        $this->assertSame(1, (int) $this->db->query('SELECT status_id FROM ' . self::PREFIX . 'videos')->fetchColumn());
        $log = file_get_contents($this->path . '/admin/logs/tasks/11.txt');
        $this->runAction('retry', ['--yes' => true]);
        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertSame(3, $this->activeTask()['times_restarted']);
        $this->assertSame($log, file_get_contents($this->path . '/admin/logs/tasks/11.txt'));
    }

    public function testRetryDryRunDoesNotWriteAnything(): void
    {
        $before = $this->activeTask();
        $result = $this->runAction('retry', ['--dry-run' => true]);
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertSame('dry-run', $result['outcome']);
        $this->assertSame($before, $this->activeTask());
        $this->assertFileDoesNotExist($this->path . '/admin/logs/tasks/11.txt');
        $this->assertSame(0, $this->notificationCount());
    }

    public function testRetryRequiresConfirmationInScripts(): void
    {
        $before = $this->activeTask();
        $result = $this->runAction('retry');
        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('--yes', $result['message']);
        $this->assertSame($before, $this->activeTask());
    }

    public function testInteractiveRetryCanBeDeclined(): void
    {
        $this->tester->setInputs(['no']);
        $this->tester->execute(['action' => 'retry', 'id' => '11']);
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('declined', $this->tester->getDisplay());
        $this->assertSame(2, $this->activeTask()['status_id']);
    }

    public function testRetryRefusesArchivedTasks(): void
    {
        $this->archiveTask(2);
        $result = $this->runAction('retry', ['--yes' => true]);
        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Historical', $result['message']);
        $this->assertFalse($this->activeTask());
    }

    public function testLogFailureLeavesDatabaseAndEarlierLogsUnchanged(): void
    {
        $before = $this->activeTask();
        rmdir($this->path . '/admin/logs/videos');
        $this->runAction('retry', ['--yes' => true]);
        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertSame($before, $this->activeTask());
        $this->assertFileDoesNotExist($this->path . '/admin/logs/tasks/11.txt');
    }

    public function testDatabaseFailureRollsBackContentAndQueueChanges(): void
    {
        $before = $this->activeTask();
        $this->db->exec('DROP TABLE ' . self::NOTIFICATION_PREFIX . 'admin_notifications');
        file_put_contents($this->path . '/admin/logs/tasks/11.txt', 'Original log');
        $this->runAction('retry', ['--yes' => true]);
        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertSame($before, $this->activeTask());
        $this->assertSame(2, (int) $this->db->query('SELECT status_id FROM ' . self::PREFIX . 'videos')->fetchColumn());
        $this->assertSame('Original log', file_get_contents($this->path . '/admin/logs/tasks/11.txt'));
        $this->assertFileDoesNotExist($this->path . '/admin/logs/videos/21.txt');
    }

    public function testRetryRejectsSymlinkedLogWithoutTouchingItsTarget(): void
    {
        $target = $this->path . '/sentinel.txt';
        file_put_contents($target, 'sentinel');
        symlink($target, $this->path . '/admin/logs/tasks/11.txt');
        $this->runAction('retry', ['--yes' => true]);
        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertSame('sentinel', file_get_contents($target));
        $this->assertSame(2, $this->activeTask()['status_id']);
    }

    /** @return iterable<string, array{int, bool, string, int}> */
    public static function terminalStates(): iterable
    {
        yield 'active failure' => [2, false, 'failed', 1];
        yield 'historical failure' => [2, true, 'failed', 1];
        yield 'completed' => [3, true, 'completed', 0];
        yield 'cancelled' => [4, true, 'cancelled', 3];
        yield 'unknown' => [9, false, 'unknown-status', 1];
    }

    #[DataProvider('terminalStates')]
    public function testWaitRecognizesTerminalStates(int $status, bool $history, string $outcome, int $code): void
    {
        if ($history) {
            $this->archiveTask($status);
        } else {
            $this->db->exec('UPDATE ' . self::PREFIX . 'background_tasks SET status_id=' . $status);
        }
        $result = $this->runAction('wait', ['--timeout' => '0']);
        $this->assertSame($code, $this->tester->getStatusCode());
        $this->assertSame($outcome, $result['outcome']);
        $this->assertSame($history, $result['is_history']);
        $this->assertSame(7, $result['error_code']);
        $this->assertSame('Original conversion error', $result['message']);
        $this->assertSame(0.0, $this->command->clock);
    }

    public function testWaitFollowsRealRowsAcrossTheHistoryHandoverGap(): void
    {
        $this->db->exec('UPDATE ' . self::PREFIX . 'background_tasks SET status_id=0');
        $polls = 0;
        $this->command->onSleep = function () use (&$polls): void {
            $polls++;
            $prefix = self::PREFIX;
            if ($polls === 1) {
                $this->db->exec("UPDATE {$prefix}background_tasks SET status_id=1");
            } elseif ($polls === 2) {
                $this->db->exec("CREATE TEMPORARY TABLE pending_history AS SELECT * FROM {$prefix}background_tasks");
                $this->db->exec("DELETE FROM {$prefix}background_tasks");
            } elseif ($polls === 3) {
                $this->db->exec("INSERT INTO {$prefix}background_tasks_history SELECT * FROM pending_history");
                $this->db->exec("UPDATE {$prefix}background_tasks_history SET status_id=3");
            }
        };
        $result = $this->runAction('wait', ['--timeout' => '10', '--interval' => '0.5']);
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertSame('completed', $result['outcome']);
        $this->assertTrue($result['is_history']);
        $this->assertSame(1.5, $this->command->clock);
    }

    public function testWaitHonorsLongPollingInterval(): void
    {
        $this->db->exec('UPDATE ' . self::PREFIX . 'background_tasks SET status_id=1');
        $this->command->onSleep = function (): void {
            $this->archiveTask(3);
        };
        $result = $this->runAction('wait', ['--timeout' => '120', '--interval' => '60']);
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertSame('completed', $result['outcome']);
        $this->assertSame(60.0, $this->command->clock);
    }

    public function testWaitTimesOutWithoutChangingTaskOrOversleeping(): void
    {
        $this->db->exec('UPDATE ' . self::PREFIX . 'background_tasks SET status_id=1');
        $before = $this->activeTask();
        $result = $this->runAction('wait', ['--timeout' => '1', '--interval' => '60']);
        $this->assertSame(124, $this->tester->getStatusCode());
        $this->assertSame('timeout', $result['outcome']);
        $this->assertSame(1.0, $this->command->clock);
        $this->assertSame($before, $this->activeTask());
    }

    public function testWaitReportsMissingTaskAfterGracePeriod(): void
    {
        $this->db->exec('DELETE FROM ' . self::PREFIX . 'background_tasks');
        $result = $this->runAction('wait', ['--timeout' => '10']);
        $this->assertSame(2, $this->tester->getStatusCode());
        $this->assertSame('not-found', $result['outcome']);
        $this->assertNull($result['status_id']);
        $this->assertSame(2.0, $this->command->clock);
    }

    public function testWaitInterruptionDoesNotCancelTask(): void
    {
        $this->db->exec('UPDATE ' . self::PREFIX . 'background_tasks SET status_id=1');
        $before = $this->activeTask();
        $this->command->onSleep = function (): void {
            $this->assertFalse($this->command->handleSignal(2));
        };
        $result = $this->runAction('wait');
        $this->assertSame(130, $this->tester->getStatusCode());
        $this->assertSame('interrupted', $result['outcome']);
        $this->assertSame($before, $this->activeTask());
    }

    /** @return iterable<string, array<string, string|bool>> */
    public static function invalidInputs(): iterable
    {
        yield 'missing ID' => [['action' => 'retry']];
        yield 'fractional ID' => [['action' => 'wait', 'id' => '1.5']];
        yield 'negative timeout' => [['action' => 'wait', 'id' => '11', '--timeout' => '-1']];
        yield 'fractional timeout' => [['action' => 'wait', 'id' => '11', '--timeout' => '0.5']];
        yield 'zero interval' => [['action' => 'wait', 'id' => '11', '--interval' => '0']];
        yield 'nonfinite interval' => [['action' => 'wait', 'id' => '11', '--interval' => 'INF']];
        yield 'long interval' => [['action' => 'wait', 'id' => '11', '--interval' => '61']];
        yield 'retry filters' => [['action' => 'retry', 'id' => '11', '--status' => 'failed', '--yes' => true]];
        yield 'retry fields' => [['action' => 'retry', 'id' => '11', '--fields' => 'invalid', '--yes' => true]];
        yield 'retry format' => [['action' => 'retry', 'id' => '11', '--format' => 'count', '--yes' => true]];
        yield 'retry timeout' => [['action' => 'retry', 'id' => '11', '--timeout' => '2', '--yes' => true]];
        yield 'wait dry run' => [['action' => 'wait', 'id' => '11', '--dry-run' => true]];
        yield 'list mutation flag' => [['action' => 'list', '--yes' => true]];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputDoesNotChangeTask(array $input): void
    {
        $before = $this->activeTask();
        $this->tester->execute($input, ['interactive' => false]);
        $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame($before, $this->activeTask());
    }

    private function archiveTask(int $status): void
    {
        $prefix = self::PREFIX;
        $this->db->exec("INSERT INTO {$prefix}background_tasks_history SELECT * FROM {$prefix}background_tasks WHERE task_id=11");
        $this->db->exec("UPDATE {$prefix}background_tasks_history SET status_id=$status WHERE task_id=11");
        $this->db->exec("DELETE FROM {$prefix}background_tasks WHERE task_id=11");
    }

    private function seedNotification(int $count): void
    {
        $stmt = $this->db->prepare('INSERT INTO ' . self::NOTIFICATION_PREFIX . 'admin_notifications VALUES (?, ?, ?)');
        $stmt->execute(['administration.background_tasks.failure', $count, 'null']);
    }

    private function notificationCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . self::NOTIFICATION_PREFIX . 'admin_notifications')->fetchColumn();
    }

    private function activeTask(int $id = 11): array|false
    {
        return $this->db->query('SELECT * FROM ' . self::PREFIX . 'background_tasks WHERE task_id=' . $id)->fetch();
    }

    private function runAction(string $action, array $options = []): array
    {
        $this->tester->execute([
            'action' => $action,
            'id' => '11',
            '--format' => 'json',
            ...$options,
        ], ['interactive' => false]);
        return json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    }
}
