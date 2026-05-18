<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\VideoCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class VideoCommandTest extends TestCase
{
    private Configuration $config;
    private VideoCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = TestHelper::createTestKvsInstallation();

        $this->config = TestHelper::createTestConfiguration($kvsPath);
        $this->command = new VideoCommand($this->config);

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

    public function testVideoListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 2
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Video id', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testVideoListWithStatus(): void
    {
        // Verify we have videos with different statuses
        $stmt = $this->db->query('SELECT COUNT(*) FROM ' . TestHelper::table('videos') . ' WHERE status_id = 1');
        $activeCount = $stmt->fetchColumn();

        if ($activeCount == 0) {
            $this->markTestSkipped('No active videos in database');
        }

        $this->tester->execute([
            'action' => 'list',
            '--status' => 1,
            '--limit' => 5
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Video id', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testVideoListFormats(): void
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

        $this->assertStringContainsString('video_id', $csvOutput);
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

    public function testVideoShow(): void
    {
        // Get first video ID
        $stmt = $this->db->query('SELECT video_id FROM ' . TestHelper::table('videos') . ' LIMIT 1');
        $videoId = $stmt->fetchColumn();

        if (!$videoId) {
            $this->markTestSkipped('No videos in database');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => $videoId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Video #', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testVideoCommandMetadata(): void
    {
        $this->assertEquals('content:video', $this->command->getName());
        $this->assertStringContainsString('manage', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('video', $aliases);
    }
}
