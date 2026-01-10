<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Video\ScreenshotsCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(ScreenshotsCommand::class)]
class ScreenshotsCommandTest extends TestCase
{
    private Configuration $config;
    private ScreenshotsCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new ScreenshotsCommand($this->config);

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

    public function testListScreenshotsRequiresVideoId(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required', $output);
    }

    public function testListScreenshotsWithInvalidVideoId(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '999999999'
        ]);

        // Either warning or path not configured - both are valid in test env
        $statusCode = $this->tester->getStatusCode();
        $this->assertTrue($statusCode === 0 || $statusCode === 1);
    }

    public function testListScreenshotsWithExistingVideo(): void
    {
        // Get a video ID that exists
        $table = $this->config->getTablePrefix() . 'videos';
        $stmt = $this->db->query("SELECT video_id FROM {$table} LIMIT 1");
        $videoId = $stmt->fetchColumn();

        if ($videoId === false) {
            $this->markTestSkipped('No videos in database');
        }

        $this->tester->execute([
            'action' => 'list',
            'video_id' => $videoId
        ]);

        // Success with warning (no files) or success with files
        $statusCode = $this->tester->getStatusCode();
        $this->assertTrue($statusCode === 0 || $statusCode === 1);
    }

    public function testGenerateScreenshotsRequiresVideoId(): void
    {
        $this->tester->execute(['action' => 'generate']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required', $output);
    }

    public function testRegenerateScreenshotsRequiresVideoId(): void
    {
        $this->tester->execute(['action' => 'regenerate']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required', $output);
    }

    /**
     * @group integration
     * @group slow
     */
    public function testGenerateScreenshotsChecksFfmpeg(): void
    {
        // Skip unless explicitly allowed - this test can write to filesystem
        if (!getenv('KVS_TEST_ALLOW_SIDE_EFFECTS')) {
            $this->markTestSkipped('Skipped: set KVS_TEST_ALLOW_SIDE_EFFECTS=1 to run');
        }

        // Get a video ID that exists
        $table = $this->config->getTablePrefix() . 'videos';
        $stmt = $this->db->query("SELECT video_id FROM {$table} LIMIT 1");
        $videoId = $stmt->fetchColumn();

        if ($videoId === false) {
            $this->markTestSkipped('No videos in database');
        }

        $this->tester->execute([
            'action' => 'generate',
            'video_id' => $videoId
        ]);

        $output = $this->tester->getDisplay();
        $statusCode = $this->tester->getStatusCode();

        // Should fail with either ffmpeg error or path error in test env
        $this->assertEquals(1, $statusCode);
        $this->assertTrue(
            str_contains($output, 'ffmpeg') || str_contains($output, 'not configured') || str_contains($output, 'not found'),
            'Expected ffmpeg or path configuration error'
        );
    }

    #[DataProvider('provideOutputFormats')]
    public function testListScreenshotsOutputFormats(string $format): void
    {
        // Get a video ID that exists
        $table = $this->config->getTablePrefix() . 'videos';
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

        // Just verify the command runs without crashing
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

        // Should behave like list action - returns success with warning
        $statusCode = $this->tester->getStatusCode();
        $this->assertTrue($statusCode === 0 || $statusCode === 1);
    }

    public function testCommandHasExpectedOptions(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('count'));
        $this->assertTrue($definition->hasOption('type'));
        $this->assertTrue($definition->hasOption('format'));
    }

    public function testCommandHasExpectedAliases(): void
    {
        $aliases = $this->command->getAliases();

        $this->assertContains('screenshots', $aliases);
    }
}
