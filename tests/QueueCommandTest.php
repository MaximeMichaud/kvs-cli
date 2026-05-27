<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\QueueCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(QueueCommand::class)]
class QueueCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private QueueCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->db = $this->createDatabase();

        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->command = $this->createCommand($this->db);
        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testQueueListEmpty(): void
    {
        $this->db->exec('DELETE FROM ' . TestHelper::table('background_tasks'));

        $this->tester->execute([
            'action' => 'list',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Queue is empty - no tasks found', $output);
    }

    public function testQueueListWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 2,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Background Tasks Queue', $output);
        $this->assertStringContainsString('Showing 2 tasks', $output);
        $this->assertStringContainsString('Create Video Format', $output);
        $this->assertStringContainsString('New Album', $output);
        $this->assertStringNotContainsString('New Video', $output);
    }

    public function testQueueListWithStatusFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'pending',
            '--format' => 'json',
        ]);
        $pendingRows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $pendingRows);
        $this->assertSame(10, (int) $pendingRows[0]['task_id']);
        $this->assertSame('Pending', $pendingRows[0]['status']);

        $this->tester->execute([
            'action' => 'list',
            '--status' => 'processing',
            '--format' => 'json',
        ]);
        $processingRows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $processingRows);
        $this->assertSame(20, (int) $processingRows[0]['task_id']);

        $this->tester->execute([
            'action' => 'list',
            '--status' => 'failed',
            '--format' => 'json',
        ]);
        $failedRows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $failedRows);
        $this->assertSame(30, (int) $failedRows[0]['task_id']);
    }

    public function testQueueListJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(30, (int) $rows[0]['task_id']);
        $this->assertSame('Failed', $rows[0]['status']);
        $this->assertSame('Create Video Format', $rows[0]['type']);
        $this->assertSame('Video #101', $rows[0]['content_id']);
        $this->assertSame('Backup Worker', $rows[0]['server']);
        $this->assertSame('Conversion Failed', $rows[0]['error']);
    }

    public function testQueueListCountFormatIgnoresLimitButAppliesFilters(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame("3\n", $this->tester->getDisplay());

        $this->tester->execute([
            'action' => 'list',
            '--status' => 'pending',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame("1\n", $this->tester->getDisplay());
    }

    public function testQueueStats(): void
    {
        $this->tester->execute([
            'action' => 'stats',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Queue Statistics', $output);
        $this->assertStringContainsString('Queue Status', $output);
        $this->assertStringContainsString('Tasks by Type', $output);
        $this->assertStringContainsString('Failed Tasks by Error', $output);
        $this->assertStringContainsString('Last 24 Hours', $output);
        $this->assertStringContainsString('New Video', $output);
        $this->assertStringContainsString('Conversion Failed', $output);
        $this->assertMatchesRegularExpression('/Pending\W+1/', $output);
        $this->assertMatchesRegularExpression('/Processing\W+1/', $output);
        $this->assertMatchesRegularExpression('/Failed\W+1/', $output);
        $this->assertMatchesRegularExpression('/Completed\W+1/', $output);
        $this->assertMatchesRegularExpression('/Deleted\W+1/', $output);
    }

    public function testQueueHistory(): void
    {
        $this->tester->execute([
            'action' => 'history',
            '--limit' => 2,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Task History', $output);
        $this->assertStringContainsString('Showing 2 tasks', $output);
        $this->assertStringContainsString('New Video', $output);
        $this->assertStringContainsString('New Album', $output);
        $this->assertStringNotContainsString('Create Video Format', $output);
    }

    public function testQueueHistoryCountFormatIgnoresLimitButAppliesFilters(): void
    {
        $this->tester->execute([
            'action' => 'history',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame("3\n", $this->tester->getDisplay());

        $this->tester->execute([
            'action' => 'history',
            '--status' => 'completed',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame("2\n", $this->tester->getDisplay());
    }

    public function testQueueShowRequiresId(): void
    {
        $this->tester->execute([
            'action' => 'show',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Task ID is required', $output);
    }

    public function testQueueShowNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '999999999',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Task not found: 999999999', $output);
    }

    public function testQueueShowWithTask(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Task #30', $output);
        $this->assertStringContainsString('Failed', $output);
        $this->assertStringContainsString('Create Video Format', $output);
        $this->assertStringContainsString('Video ID', $output);
        $this->assertStringContainsString('101', $output);
        $this->assertStringContainsString('Backup Worker', $output);
        $this->assertStringContainsString('Conversion Failed', $output);
        $this->assertStringContainsString('Converter returned exit code 1', $output);
    }

    public function testQueueHelpAction(): void
    {
        $this->tester->execute([
            'action' => 'help-action',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Available actions', $output);
        $this->assertStringContainsString('list : List active tasks in queue', $output);
        $this->assertStringContainsString('show <id> : Show details for a specific task', $output);
        $this->assertStringContainsString('stats : Show queue statistics', $output);
        $this->assertStringContainsString('history : Show completed/deleted tasks history', $output);
    }

    public function testQueueRejectsUnknownAction(): void
    {
        $this->tester->execute([
            'action' => 'unknown_action',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown queue action "unknown_action"', $this->tester->getDisplay());
    }

    public function testQueueCommandMetadata(): void
    {
        $this->assertEquals('system:queue', $this->command->getName());
        $this->assertStringContainsString('background tasks', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('queue', $aliases);
    }

    public function testQueueDefaultAction(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Background Tasks Queue', $output);
        $this->assertStringContainsString('Showing 3 tasks', $output);
    }

    public function testQueueListWithTypeFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--type' => '1',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['task_id']);
        $this->assertSame('New Video', $rows[0]['type']);
    }

    public function testQueueHistoryWithStatusFilter(): void
    {
        $this->tester->execute([
            'action' => 'history',
            '--status' => 'completed',
            '--format' => 'json',
        ]);
        $completedRows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(2, $completedRows);
        $this->assertSame([301, 303], array_map(static fn (array $row): int => (int) $row['task_id'], $completedRows));
        $this->assertSame(['Completed', 'Completed'], array_column($completedRows, 'status'));

        $this->tester->execute([
            'action' => 'history',
            '--status' => 'deleted',
            '--format' => 'json',
        ]);
        $deletedRows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $deletedRows);
        $this->assertSame(302, (int) $deletedRows[0]['task_id']);
        $this->assertSame('Deleted', $deletedRows[0]['status']);
    }

    public function testQueueHistoryWithAlbumFilter(): void
    {
        $this->tester->execute([
            'action' => 'history',
            '--album' => '7',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(302, (int) $rows[0]['task_id']);
        $this->assertSame('Album #7', $rows[0]['content_id']);

        $this->tester->execute([
            'action' => 'history',
            '--album' => '999',
            '--format' => 'count',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame("0\n", $this->tester->getDisplay());
    }

    public function testQueueHistoryWithServerFilter(): void
    {
        $this->tester->execute([
            'action' => 'history',
            '--server' => '1',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(301, (int) $rows[0]['task_id']);
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $this->createSchema($db);
        $this->seedDatabase($db);

        return $db;
    }

    private function createSchema(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_conversion_servers') . ' (' .
            'server_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('background_tasks') . ' (' .
            'task_id INTEGER, status_id INTEGER, type_id INTEGER, video_id INTEGER, album_id INTEGER, ' .
            'server_id INTEGER, error_code INTEGER, priority INTEGER, message TEXT, data TEXT, ' .
            'times_restarted INTEGER, added_date TEXT, start_date TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('background_tasks_history') . ' (' .
            'task_id INTEGER, status_id INTEGER, type_id INTEGER, video_id INTEGER, album_id INTEGER, ' .
            'server_id INTEGER, error_code INTEGER, priority INTEGER, message TEXT, data TEXT, ' .
            'start_date TEXT, end_date TEXT, effective_duration INTEGER)'
        );
    }

    private function seedDatabase(PDO $db): void
    {
        $db->exec(
            'INSERT INTO ' . TestHelper::table('admin_conversion_servers') .
            " (server_id, title) VALUES (1, 'Local Worker'), (2, 'Backup Worker')"
        );

        $this->insertTask($db, [
            'task_id' => 10,
            'status_id' => 0,
            'type_id' => 1,
            'video_id' => 100,
            'album_id' => 0,
            'server_id' => 1,
            'error_code' => 0,
            'priority' => 10,
            'message' => 'Waiting for conversion',
            'data' => serialize(['source' => 'upload']),
            'times_restarted' => 0,
            'added_date' => '2026-05-26 08:00:00',
            'start_date' => '0000-00-00 00:00:00',
        ]);
        $this->insertTask($db, [
            'task_id' => 20,
            'status_id' => 1,
            'type_id' => 10,
            'video_id' => 0,
            'album_id' => 7,
            'server_id' => 1,
            'error_code' => 0,
            'priority' => 30,
            'message' => 'Rendering album',
            'data' => '',
            'times_restarted' => 1,
            'added_date' => '2026-05-26 09:00:00',
            'start_date' => '2026-05-26 09:30:00',
        ]);
        $this->insertTask($db, [
            'task_id' => 30,
            'status_id' => 2,
            'type_id' => 4,
            'video_id' => 101,
            'album_id' => 0,
            'server_id' => 2,
            'error_code' => 3,
            'priority' => 50,
            'message' => 'Converter returned exit code 1',
            'data' => '',
            'times_restarted' => 2,
            'added_date' => '2026-05-26 10:00:00',
            'start_date' => '2026-05-26 10:05:00',
        ]);

        $recentCompleted = date('Y-m-d H:i:s', time() - 3600);
        $recentDeleted = date('Y-m-d H:i:s', time() - 7200);
        $oldCompleted = date('Y-m-d H:i:s', time() - 172800);

        $this->insertHistoryTask($db, [
            'task_id' => 301,
            'status_id' => 3,
            'type_id' => 1,
            'video_id' => 100,
            'album_id' => 0,
            'server_id' => 1,
            'error_code' => 0,
            'priority' => 10,
            'message' => 'Finished conversion',
            'data' => '',
            'start_date' => date('Y-m-d H:i:s', time() - 7261),
            'end_date' => $recentCompleted,
            'effective_duration' => 3661,
        ]);
        $this->insertHistoryTask($db, [
            'task_id' => 302,
            'status_id' => 4,
            'type_id' => 10,
            'video_id' => 0,
            'album_id' => 7,
            'server_id' => 2,
            'error_code' => 0,
            'priority' => 30,
            'message' => 'Deleted by admin',
            'data' => '',
            'start_date' => date('Y-m-d H:i:s', time() - 7265),
            'end_date' => $recentDeleted,
            'effective_duration' => 65,
        ]);
        $this->insertHistoryTask($db, [
            'task_id' => 303,
            'status_id' => 3,
            'type_id' => 4,
            'video_id' => 102,
            'album_id' => 0,
            'server_id' => 2,
            'error_code' => 0,
            'priority' => 20,
            'message' => 'Old conversion',
            'data' => '',
            'start_date' => date('Y-m-d H:i:s', time() - 172920),
            'end_date' => $oldCompleted,
            'effective_duration' => 120,
        ]);
    }

    /**
     * @param array<string, int|string> $task
     */
    private function insertTask(PDO $db, array $task): void
    {
        $stmt = $db->prepare(
            'INSERT INTO ' . TestHelper::table('background_tasks') .
            ' (task_id, status_id, type_id, video_id, album_id, server_id, error_code, priority, ' .
            'message, data, times_restarted, added_date, start_date) VALUES ' .
            '(:task_id, :status_id, :type_id, :video_id, :album_id, :server_id, :error_code, :priority, ' .
            ':message, :data, :times_restarted, :added_date, :start_date)'
        );
        $stmt->execute($task);
    }

    /**
     * @param array<string, int|string> $task
     */
    private function insertHistoryTask(PDO $db, array $task): void
    {
        $stmt = $db->prepare(
            'INSERT INTO ' . TestHelper::table('background_tasks_history') .
            ' (task_id, status_id, type_id, video_id, album_id, server_id, error_code, priority, ' .
            'message, data, start_date, end_date, effective_duration) VALUES ' .
            '(:task_id, :status_id, :type_id, :video_id, :album_id, :server_id, :error_code, :priority, ' .
            ':message, :data, :start_date, :end_date, :effective_duration)'
        );
        $stmt->execute($task);
    }

    private function createCommand(PDO $db): QueueCommand
    {
        return new class ($this->config, $db) extends QueueCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:queue');
                $this->setDescription('Manage KVS background tasks queue');
                $this->setAliases(['queue']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
