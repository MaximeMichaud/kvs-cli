<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\QueueCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(QueueCommand::class)]
class QueueCommandTest extends TestCase
{
    private Configuration $config;
    private QueueCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = TestHelper::createTestKvsInstallation();

        $this->config = TestHelper::createTestConfiguration($kvsPath);
        $this->command = new QueueCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);

        if (TestHelper::isCommandDefinitionTest($this->name())) {
            return;
        }

        // Setup test database connection using TestHelper
        try {
            $this->db = TestHelper::getPDO();
        } catch (\PDOException $e) {
            $this->markTestSkipped(TestHelper::databaseSkipMessage($e));
        }

        // Ensure background_tasks table exists
        try {
            $table = $this->config->getTablePrefix() . 'background_tasks';
            $this->db->query("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\PDOException $e) {
            $this->markTestSkipped('background_tasks table does not exist');
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testQueueListEmpty(): void
    {
        $this->tester->execute([
            'action' => 'list',
        ]);

        $output = $this->tester->getDisplay();
        // Either shows tasks or "Queue is empty"
        $this->assertTrue(
            str_contains($output, 'Background Tasks Queue')
            || str_contains($output, 'Queue is empty')
        );
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testQueueListWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 5
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testQueueListWithStatusFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'pending'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());

        $this->tester->execute([
            'action' => 'list',
            '--status' => 'processing'
        ]);
        $this->assertEquals(0, $this->tester->getStatusCode());

        $this->tester->execute([
            'action' => 'list',
            '--status' => 'failed'
        ]);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testQueueListJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--limit' => 1
        ]);

        $output = $this->tester->getDisplay();
        // Either valid JSON or empty array
        if (trim($output) !== '') {
            $decoded = json_decode($output, true);
            $this->assertTrue($decoded !== null || $output === '[]');
        }
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testQueueStats(): void
    {
        $this->tester->execute([
            'action' => 'stats',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Queue Statistics', $output);
        $this->assertStringContainsString('Queue Status', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testQueueHistory(): void
    {
        // Ensure history table exists
        try {
            $table = $this->config->getTablePrefix() . 'background_tasks_history';
            $this->db->query("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\PDOException $e) {
            $this->markTestSkipped('background_tasks_history table does not exist');
        }

        $this->tester->execute([
            'action' => 'history',
            '--limit' => 5
        ]);

        $output = $this->tester->getDisplay();
        // Either shows history or "No history found"
        $this->assertTrue(
            str_contains($output, 'Task History')
            || str_contains($output, 'No history found')
        );
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testQueueShowRequiresId(): void
    {
        $this->tester->execute([
            'action' => 'show',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Task ID is required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testQueueShowNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Task not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testQueueShowWithTask(): void
    {
        // Get a task ID if any exist
        $prefix = $this->config->getTablePrefix();
        $stmt = $this->db->query("SELECT task_id FROM {$prefix}background_tasks LIMIT 1");
        $taskId = $stmt->fetchColumn();

        if (!$taskId) {
            // Try history table
            $stmt = $this->db->query("SELECT task_id FROM {$prefix}background_tasks_history LIMIT 1");
            $taskId = $stmt->fetchColumn();
        }

        if (!$taskId) {
            $this->markTestSkipped('No tasks in database to test show command');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => (string)$taskId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString("Task #$taskId", $output);
        $this->assertStringContainsString('Status', $output);
        $this->assertStringContainsString('Type', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testQueueHelpAction(): void
    {
        $this->tester->execute([
            'action' => 'help-action',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Available actions', $output);
        $this->assertStringContainsString('list', $output);
        $this->assertStringContainsString('show', $output);
        $this->assertStringContainsString('stats', $output);
        $this->assertStringContainsString('history', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
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
        // No action specified should list the queue
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertTrue(
            str_contains($output, 'Background Tasks Queue')
            || str_contains($output, 'Queue is empty')
        );
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testQueueListWithTypeFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--type' => '1'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testQueueHistoryWithStatusFilter(): void
    {
        try {
            $table = $this->config->getTablePrefix() . 'background_tasks_history';
            $this->db->query("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\PDOException $e) {
            $this->markTestSkipped('background_tasks_history table does not exist');
        }

        $this->tester->execute([
            'action' => 'history',
            '--status' => 'completed'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());

        $this->tester->execute([
            'action' => 'history',
            '--status' => 'deleted'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }
}
