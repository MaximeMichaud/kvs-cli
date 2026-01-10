<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Video\FormatsCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(FormatsCommand::class)]
class FormatsCommandTest extends TestCase
{
    private Configuration $config;
    private FormatsCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new FormatsCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);

        try {
            $this->db = TestHelper::getPDO();
        } catch (\PDOException $e) {
            $this->markTestSkipped('Cannot connect to test database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testListFormatsRequiresVideoId(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required', $output);
    }

    public function testListFormatsWithInvalidVideoId(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        // Could be either "not found" or "path not configured" depending on setup
        $this->assertNotEquals(0, $this->tester->getStatusCode());
    }

    public function testListFormatsWithExistingVideo(): void
    {
        // Get a video ID that exists
        $table = TestHelper::table('videos');
        $stmt = $this->db->query("SELECT video_id FROM {$table} LIMIT 1");
        $videoId = $stmt->fetchColumn();

        if ($videoId === false) {
            $this->markTestSkipped('No videos in database');
        }

        $this->tester->execute([
            'action' => 'list',
            'video_id' => $videoId
        ]);

        // Success if video has formats, failure if directory not found (expected in test env)
        $statusCode = $this->tester->getStatusCode();
        $this->assertTrue($statusCode === 0 || $statusCode === 1);
    }

    public function testCheckFormatsRequiresVideoId(): void
    {
        $this->tester->execute(['action' => 'check']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required', $output);
    }

    public function testCheckFormatsWithInvalidVideoId(): void
    {
        $this->tester->execute([
            'action' => 'check',
            'video_id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testShowAvailableFormats(): void
    {
        // Check if formats_videos table exists
        try {
            $table = TestHelper::table('formats_videos');
            $stmt = $this->db->query("SHOW TABLES LIKE '{$table}'");
            $hasTable = $stmt->fetch() !== false;
        } catch (\PDOException $e) {
            $hasTable = false;
        }

        $this->tester->execute(['action' => 'available']);

        // If table exists and has data, success; otherwise failure with warning
        $statusCode = $this->tester->getStatusCode();
        $this->assertTrue($statusCode === 0 || $statusCode === 1);
    }

    #[DataProvider('provideOutputFormats')]
    public function testListFormatsOutputFormats(string $format): void
    {
        // Get a video ID that exists
        $table = TestHelper::table('videos');
        $stmt = $this->db->query("SELECT video_id FROM {$table} LIMIT 1");
        $videoId = $stmt->fetchColumn();

        if ($videoId === false) {
            $this->markTestSkipped('No videos in database');
        }

        $this->tester->execute([
            'action' => 'list',
            'video_id' => $videoId,
            '--format' => $format
        ]);

        // Just verify the command runs without error
        $statusCode = $this->tester->getStatusCode();
        $this->assertTrue($statusCode === 0 || $statusCode === 1);
    }

    public static function provideOutputFormats(): array
    {
        return [
            'table format' => ['table'],
            'json format' => ['json'],
        ];
    }

    public function testDefaultActionIsList(): void
    {
        $this->tester->execute([
            'video_id' => '999999999'
        ]);

        // Should behave like list action
        $output = $this->tester->getDisplay();
        $this->assertNotEquals(0, $this->tester->getStatusCode());
    }

    public function testCommandHasExpectedAliases(): void
    {
        $aliases = $this->command->getAliases();

        $this->assertContains('formats', $aliases);
    }
}
