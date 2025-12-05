<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class UserCommandTest extends TestCase
{
    private Configuration $config;
    private UserCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;
    private string $dbName;

    protected function setUp(): void
    {
        // Use real KVS installation with test database
        // Detect KVS path: env var, or relative to test directory
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new UserCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);

        // Setup test database connection using TestHelper
        try {
            $this->db = TestHelper::getPDO();
            $dbConfig = TestHelper::getDbConfig();
            $this->dbName = $dbConfig['database'];
        } catch (\PDOException $e) {
            $this->markTestSkipped('Cannot connect to test database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testUserListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 2
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('User id', $output);
        $this->assertStringContainsString('Username', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testUserListWithRemovalRequested(): void
    {
        // Create a test user with removal request
        $this->db->exec("
            INSERT INTO ktvs_users (username, display_name, email, status_id, is_removal_requested, removal_reason, added_date)
            VALUES ('test_removal_user', 'Test Removal', 'test_removal@test.com', 2, 1, 'I want to delete my account', NOW())
            ON DUPLICATE KEY UPDATE is_removal_requested=1, removal_reason='I want to delete my account'
        ");

        $this->tester->execute([
            'action' => 'list',
            '--removal-requested' => true,
            '--limit' => 10
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Removal reason', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // Cleanup
        $this->db->exec("DELETE FROM ktvs_users WHERE username='test_removal_user'");
    }

    public function testUserListFormats(): void
    {
        // Test JSON format
        $testerJson = new CommandTester($this->command);
        $testerJson->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json'
        ]);

        $output = $testerJson->getDisplay();
        $this->assertJson($output);
        $this->assertEquals(0, $testerJson->getStatusCode());

        // Test CSV format - CSV writes to php://output so we capture it with ob_start
        $testerCsv = new CommandTester($this->command);
        ob_start();
        $testerCsv->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'csv'
        ]);
        $csvOutput = ob_get_clean();

        $this->assertStringContainsString('user_id', $csvOutput);
        $this->assertEquals(0, $testerCsv->getStatusCode());

        // Test count format
        $testerCount = new CommandTester($this->command);
        $testerCount->execute([
            'action' => 'list',
            '--format' => 'count'
        ]);

        $output = trim($testerCount->getDisplay());
        $this->assertMatchesRegularExpression('/^\d+$/', $output);
        $this->assertEquals(0, $testerCount->getStatusCode());
    }

    public function testUserShow(): void
    {
        // Get first user ID
        $stmt = $this->db->query("SELECT user_id FROM ktvs_users LIMIT 1");
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            $this->markTestSkipped('No users in database');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => $userId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('User ID', $output);
        $this->assertStringContainsString('Username', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testRemovalRequestedRequiresColumns(): void
    {
        // Verify that is_removal_requested column exists
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='{$this->dbName}'
            AND TABLE_NAME='ktvs_users'
            AND COLUMN_NAME='is_removal_requested'
        ");

        $hasColumn = $stmt->fetchColumn();

        if (!$hasColumn) {
            $this->fail(
                "Column 'is_removal_requested' does not exist in ktvs_users table. " .
                "Run: ALTER TABLE ktvs_users ADD COLUMN is_removal_requested TINYINT(1) DEFAULT 0"
            );
        }

        // Verify removal_reason column
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='{$this->dbName}'
            AND TABLE_NAME='ktvs_users'
            AND COLUMN_NAME='removal_reason'
        ");

        $hasColumn = $stmt->fetchColumn();

        if (!$hasColumn) {
            $this->fail(
                "Column 'removal_reason' does not exist in ktvs_users table. " .
                "Run: ALTER TABLE ktvs_users ADD COLUMN removal_reason TEXT NULL"
            );
        }

        $this->assertTrue(true);
    }

    public function testCommandMetadata(): void
    {
        $this->assertEquals('content:user', $this->command->getName());
        $this->assertStringContainsString('user', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('user', $aliases);
    }
}
