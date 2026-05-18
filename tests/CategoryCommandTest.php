<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class CategoryCommandTest extends TestCase
{
    private Configuration $config;
    private CategoryCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = TestHelper::createTestKvsInstallation();

        $this->config = TestHelper::createTestConfiguration($kvsPath);
        $this->command = new CategoryCommand($this->config);

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
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testCategoryListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 2
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Category id', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCategoryListWithStatus(): void
    {
        // Verify we have categories with different statuses
        $stmt = $this->db->query('SELECT COUNT(*) FROM ' . TestHelper::table('categories') . ' WHERE status_id = 1');
        $activeCount = $stmt->fetchColumn();

        if ($activeCount == 0) {
            $this->markTestSkipped('No active categories in database');
        }

        $this->tester->execute([
            'action' => 'list',
            '--status' => 1,
            '--limit' => 5
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Category id', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCategoryListFormats(): void
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

        // Test CSV format
        $testerCsv = new CommandTester($this->command);
        ob_start();
        $testerCsv->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'csv'
        ]);
        $csvOutput = ob_get_clean();

        $this->assertStringContainsString('category_id', $csvOutput);
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

    public function testCategoryShow(): void
    {
        // Get first category ID
        $stmt = $this->db->query('SELECT category_id FROM ' . TestHelper::table('categories') . ' LIMIT 1');
        $categoryId = $stmt->fetchColumn();

        if (!$categoryId) {
            $this->markTestSkipped('No categories in database');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => $categoryId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Category:', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCategoryCommandMetadata(): void
    {
        $this->assertEquals('content:category', $this->command->getName());
        $this->assertStringContainsString('manage', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('category', $aliases);
    }
}
