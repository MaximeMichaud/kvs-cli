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
        $this->assertStringContainsString('Video files creation', $output);
        $this->assertStringContainsString('New album', $output);
        $this->assertStringNotContainsString('New video', $output);
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
        $this->assertSame('Scheduled', $pendingRows[0]['status']);

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

    public function testQueueListAcceptsDisplayedStatusAliases(): void
    {
        $cases = [
            'scheduled' => [10, 'Scheduled'],
            'in-process' => [20, 'In process'],
            'in_process' => [20, 'In process'],
            'error' => [30, 'Error'],
        ];

        foreach ($cases as $status => [$expectedTaskId, $expectedStatus]) {
            $this->tester->execute([
                'action' => 'list',
                '--status' => $status,
                '--format' => 'json',
            ]);
            $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertEquals(0, $this->tester->getStatusCode(), $status);
            $this->assertCount(1, $rows, $status);
            $this->assertSame($expectedTaskId, (int) $rows[0]['task_id'], $status);
            $this->assertSame($expectedStatus, $rows[0]['status'], $status);
        }
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
        $this->assertSame('Error', $rows[0]['status']);
        $this->assertSame('Video files creation', $rows[0]['type']);
        $this->assertSame('Video #101', $rows[0]['content_id']);
        $this->assertSame('Backup Worker', $rows[0]['server']);
        $this->assertSame('03 - Unexpected error', $rows[0]['error']);
    }

    public function testQueueListHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 1,
            '--fields' => 'task_id',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Task id', $output);
        $this->assertStringContainsString('30', $output);
        $this->assertStringNotContainsString('Background Tasks Queue', $output);
        $this->assertStringNotContainsString('Status', $output);
    }

    public function testQueueListUsesKvsAdminErrorCodeLabels(): void
    {
        $this->insertTask($this->db, [
            'task_id' => 40,
            'status_id' => 2,
            'type_id' => 4,
            'video_id' => 102,
            'album_id' => 0,
            'server_id' => 1,
            'error_code' => 8,
            'priority' => 60,
            'message' => 'Screenshot generation failed',
            'data' => '',
            'times_restarted' => 0,
            'added_date' => '2026-05-26 11:00:00',
            'start_date' => '2026-05-26 11:05:00',
        ]);

        $this->tester->execute([
            'action' => 'list',
            '--status' => 'failed',
            '--format' => 'json',
            '--fields' => 'task_id,error_code,error',
            '--limit' => '1',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(40, (int) $rows[0]['task_id']);
        $this->assertSame(8, (int) $rows[0]['error_code']);
        $this->assertSame('08 - Screenshots error', $rows[0]['error']);
        $this->assertStringNotContainsString('Plugin Error', $this->tester->getDisplay());
    }

    public function testQueueListFiltersByKvsAdminErrorCode(): void
    {
        $this->insertTask($this->db, [
            'task_id' => 40,
            'status_id' => 2,
            'type_id' => 4,
            'video_id' => 102,
            'album_id' => 0,
            'server_id' => 1,
            'error_code' => 8,
            'priority' => 60,
            'message' => 'Screenshot generation failed',
            'data' => '',
            'times_restarted' => 0,
            'added_date' => '2026-05-26 11:00:00',
            'start_date' => '2026-05-26 11:05:00',
        ]);

        $this->tester->execute([
            'action' => 'list',
            '--error-code' => '8',
            '--format' => 'json',
            '--fields' => 'task_id,error_code,error',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(40, (int) $rows[0]['task_id']);
        $this->assertSame(8, (int) $rows[0]['error_code']);
        $this->assertSame('08 - Screenshots error', $rows[0]['error']);
    }

    public function testQueueListExposesKvsAdminObjectFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json',
            '--fields' => implode(',', [
                'task_id',
                'status_id',
                'error_code',
                'message',
                'type_id',
                'server',
                'object',
                'object_id',
                'object_type_id',
                'priority',
                'added_date',
                'start_date',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(30, (int) $rows[0]['task_id']);
        $this->assertSame(2, (int) $rows[0]['status_id']);
        $this->assertSame(3, (int) $rows[0]['error_code']);
        $this->assertSame('Converter returned exit code 1', $rows[0]['message']);
        $this->assertSame(4, (int) $rows[0]['type_id']);
        $this->assertSame('Backup Worker', $rows[0]['server']);
        $this->assertSame(101, (int) $rows[0]['object']);
        $this->assertSame(101, (int) $rows[0]['object_id']);
        $this->assertSame(1, (int) $rows[0]['object_type_id']);
        $this->assertSame(50, (int) $rows[0]['priority']);
        $this->assertSame('2026-05-26 10:00:00', $rows[0]['added_date']);
        $this->assertSame('2026-05-26 10:05:00', $rows[0]['start_date']);
    }

    public function testQueueListExposesKvsAdminAppendFields(): void
    {
        $stmt = $this->db->prepare(
            'UPDATE ' . TestHelper::table('background_tasks') . ' SET data = :data WHERE task_id = 30'
        );
        $stmt->execute([
            'data' => serialize([
                'format_postfix' => '.mp4',
                'format_size' => '720p',
            ]),
        ]);

        $progressDir = $this->kvsPath . '/admin/data/engine/tasks';
        self::assertTrue(is_dir($progressDir) || mkdir($progressDir, 0777, true));
        file_put_contents($progressDir . '/30.dat', '42');

        $this->tester->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json',
            '--fields' => 'task_id,format_postfix,format_size,pc_complete,is_error',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(30, (int) $rows[0]['task_id']);
        $this->assertSame('.mp4', $rows[0]['format_postfix']);
        $this->assertSame('720p', $rows[0]['format_size']);
        $this->assertSame('42%', $rows[0]['pc_complete']);
        $this->assertSame(1, (int) $rows[0]['is_error']);
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
        $this->assertStringContainsString('New video', $output);
        $this->assertStringContainsString('03 - Unexpected error', $output);
        $this->assertMatchesRegularExpression('/Scheduled\W+1/', $output);
        $this->assertMatchesRegularExpression('/In process\W+1/', $output);
        $this->assertMatchesRegularExpression('/Error\W+1/', $output);
        $this->assertMatchesRegularExpression('/Completed\W+1/', $output);
        $this->assertMatchesRegularExpression('/Cancelled\W+1/', $output);
    }

    public function testQueueStatsSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'stats',
            '--format' => 'json',
            '--fields' => 'section,metric,value,label',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsByMetric = array_column($rows, null, 'metric');

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('queue_status', $rowsByMetric['Scheduled']['section'] ?? null);
        $this->assertSame(1, (int) ($rowsByMetric['Scheduled']['value'] ?? 0));
        $this->assertStringNotContainsString('Queue Statistics', $this->tester->getDisplay());
    }

    public function testQueueStatsHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'stats',
            '--fields' => 'metric',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Metric', $output);
        $this->assertStringContainsString('Total', $output);
        $this->assertStringNotContainsString('Queue Statistics', $output);
        $this->assertStringNotContainsString('Queue Status', $output);
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
        $this->assertStringContainsString('Video files creation', $output);
        $this->assertStringContainsString('New album', $output);
        $this->assertStringNotContainsString('New video', $output);
    }

    public function testQueueHistoryHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'history',
            '--limit' => 1,
            '--fields' => 'task_id',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Task id', $output);
        $this->assertStringContainsString('303', $output);
        $this->assertStringNotContainsString('Task History', $output);
        $this->assertStringNotContainsString('Status', $output);
    }

    public function testQueueHistoryExposesKvsAdminObjectFields(): void
    {
        $this->tester->execute([
            'action' => 'history',
            '--server' => '1',
            '--limit' => 1,
            '--format' => 'json',
            '--fields' => implode(',', [
                'task_id',
                'status_id',
                'error_code',
                'message',
                'type_id',
                'server',
                'object',
                'object_id',
                'object_type_id',
                'start_date',
                'end_date',
                'effective_duration',
                'duration',
                'effective_duration_seconds',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(301, (int) $rows[0]['task_id']);
        $this->assertSame(3, (int) $rows[0]['status_id']);
        $this->assertSame(0, (int) $rows[0]['error_code']);
        $this->assertSame('Finished conversion', $rows[0]['message']);
        $this->assertSame(1, (int) $rows[0]['type_id']);
        $this->assertSame('Local Worker', $rows[0]['server']);
        $this->assertSame(100, (int) $rows[0]['object']);
        $this->assertSame(100, (int) $rows[0]['object_id']);
        $this->assertSame(1, (int) $rows[0]['object_type_id']);
        $this->assertSame('1:01:01', $rows[0]['effective_duration']);
        $this->assertSame('1:01:01', $rows[0]['duration']);
        $this->assertSame(3661, (int) $rows[0]['effective_duration_seconds']);
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

    public function testQueueHistoryAcceptsErrorStatusAlias(): void
    {
        $this->insertHistoryTask($this->db, [
            'task_id' => 304,
            'status_id' => 2,
            'type_id' => 4,
            'video_id' => 104,
            'album_id' => 0,
            'server_id' => 2,
            'error_code' => 8,
            'priority' => 20,
            'message' => 'Screenshot error',
            'data' => '',
            'start_date' => '2026-05-26 10:00:00',
            'end_date' => '2026-05-26 10:01:00',
            'effective_duration' => 60,
        ]);

        $this->tester->execute([
            'action' => 'history',
            '--status' => 'error',
            '--format' => 'count',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame("1\n", $this->tester->getDisplay());
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
        $this->assertStringContainsString('Error', $output);
        $this->assertStringContainsString('Video files creation', $output);
        $this->assertStringContainsString('Video ID', $output);
        $this->assertStringContainsString('101', $output);
        $this->assertStringContainsString('Backup Worker', $output);
        $this->assertStringContainsString('03 - Unexpected error', $output);
        $this->assertStringContainsString('Converter returned exit code 1', $output);
    }

    public function testQueueShowSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('30', $rows[0]['task_id']);
        $this->assertSame('Error', $rows[0]['status']);
        $this->assertSame('Video files creation', $rows[0]['type']);
        $this->assertFalse($rows[0]['is_history']);
        $this->assertStringNotContainsString('Task #30', $output);
    }

    public function testQueueShowHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
            '--fields' => 'task_id',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Task id', $output);
        $this->assertStringContainsString('30', $output);
        $this->assertStringNotContainsString('Task #30', $output);
        $this->assertStringNotContainsString('Property', $output);
    }

    public function testQueueShowRejectsNonIntegerActiveTaskIdBeforeQuery(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Task ID', $this->tester->getDisplay());
    }

    public function testQueueShowRejectsNonIntegerHistoryTaskIdBeforeQuery(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '301abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Task ID', $this->tester->getDisplay());
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
        $this->assertStringContainsString('history : Show completed/cancelled/failed tasks history', $output);
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
        $this->assertSame('New video', $rows[0]['type']);
    }

    public function testQueueListNamesDeleteTimelineScreenshotsTaskType(): void
    {
        $this->insertTask($this->db, [
            'task_id' => 40,
            'status_id' => 0,
            'type_id' => 20,
            'video_id' => 100,
            'album_id' => 0,
            'server_id' => 1,
            'error_code' => 0,
            'priority' => 60,
            'message' => 'Deleting timeline screenshots',
            'data' => '',
            'times_restarted' => 0,
            'added_date' => '2026-05-26 11:00:00',
            'start_date' => '0000-00-00 00:00:00',
        ]);

        $this->tester->execute([
            'action' => 'list',
            '--type' => '20',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(40, (int) $rows[0]['task_id']);
        $this->assertSame('Timeline screenshots deletion', $rows[0]['type']);
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
        $this->assertSame([303, 301], array_map(static fn (array $row): int => (int) $row['task_id'], $completedRows));
        $this->assertSame(['Completed', 'Completed'], array_column($completedRows, 'status'));

        $this->tester->execute([
            'action' => 'history',
            '--status' => 'cancelled',
            '--format' => 'json',
        ]);
        $cancelledRows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $cancelledRows);
        $this->assertSame(302, (int) $cancelledRows[0]['task_id']);
        $this->assertSame('Cancelled', $cancelledRows[0]['status']);
    }

    public function testQueueHistoryKeepsDeletedStatusAliasForCompatibility(): void
    {
        $this->tester->execute([
            'action' => 'history',
            '--status' => 'deleted',
            '--format' => 'json',
        ]);
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(302, (int) $rows[0]['task_id']);
        $this->assertSame('Cancelled', $rows[0]['status']);
    }

    public function testQueueHistoryDisplaysFailedHistoryStatus(): void
    {
        $this->insertFailedHistoryTask();

        $this->tester->execute([
            'action' => 'history',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(304, (int) $rows[0]['task_id']);
        $this->assertSame('Error', $rows[0]['status']);
    }

    public function testQueueHistoryWithFailedStatusFilter(): void
    {
        $this->insertFailedHistoryTask();

        $this->tester->execute([
            'action' => 'history',
            '--status' => 'failed',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(304, (int) $rows[0]['task_id']);
        $this->assertSame('Error', $rows[0]['status']);
    }

    public function testQueueHistoryFiltersByKvsAdminErrorCode(): void
    {
        $this->insertFailedHistoryTask();

        $this->tester->execute([
            'action' => 'history',
            '--error-code' => '3',
            '--format' => 'json',
            '--fields' => 'task_id,error_code,error,status',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(304, (int) $rows[0]['task_id']);
        $this->assertSame(3, (int) $rows[0]['error_code']);
        $this->assertSame('03 - Unexpected error', $rows[0]['error']);
        $this->assertSame('Error', $rows[0]['status']);
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

    public function testQueueShowRejectsListOnlyOptions(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
            '--status' => 'processing',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('The show action does not support --status', $this->tester->getDisplay());
    }

    public function testQueueStatsRejectsListOnlyOptions(): void
    {
        $this->tester->execute([
            'action' => 'stats',
            '--limit' => 1,
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('The stats action does not support --limit', $this->tester->getDisplay());
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

    private function insertFailedHistoryTask(): void
    {
        $this->insertHistoryTask($this->db, [
            'task_id' => 304,
            'status_id' => 2,
            'type_id' => 4,
            'video_id' => 103,
            'album_id' => 0,
            'server_id' => 2,
            'error_code' => 3,
            'priority' => 40,
            'message' => 'Failed and archived',
            'data' => '',
            'start_date' => date('Y-m-d H:i:s', time() - 600),
            'end_date' => date('Y-m-d H:i:s', time() + 60),
            'effective_duration' => 45,
        ]);
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
