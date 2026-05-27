<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Video\FormatsCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(FormatsCommand::class)]
class FormatsCommandTest extends TestCase
{
    private string $kvsPath;
    private string $storagePath;
    private Configuration $config;
    private FormatsCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->storagePath = $this->kvsPath . '/storage/videos';
        $this->createVideoStorage();
        $this->db = $this->createDatabase();

        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->command = $this->createCommand($this->db);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
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
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video directory not found', $output);
    }

    public function testListFormatsWithExistingVideo(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '10'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('720p MP4', $output);
        $this->assertStringContainsString('10_720p.mp4', $output);
        $this->assertStringContainsString('1.00 KB', $output);
        $this->assertStringContainsString('Unknown', $output);
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
        $this->assertStringContainsString('Video directory not found for video ID: 999999999', $output);
    }

    public function testCheckFormatsWithExistingVideo(): void
    {
        $this->tester->execute([
            'action' => 'check',
            'video_id' => '10'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('720p MP4', $output);
        $this->assertStringContainsString('1080p MP4', $output);
        $this->assertStringContainsString('available', strtolower($output));
        $this->assertStringContainsString('missing', strtolower($output));
    }

    public function testCheckFormatsJsonReturnsFailureWhenAnyFormatIsMissing(): void
    {
        $this->tester->execute([
            'action' => 'check',
            'video_id' => '10',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $statuses = array_column($rows, 'status');

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertContains('available', $statuses);
        $this->assertContains('missing', $statuses);
    }

    public function testShowAvailableFormats(): void
    {
        $this->tester->execute(['action' => 'available']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Available Format Configurations', $output);
        $this->assertStringContainsString('720p MP4', $output);
        $this->assertStringContainsString('Required', $output);
        $this->assertStringContainsString('Access', $output);
        $this->assertStringContainsString('Premium', $output);
    }

    #[DataProvider('provideOutputFormats')]
    public function testListFormatsOutputFormats(string $format): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '10',
            '--format' => $format
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        if ($format === 'json') {
            $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(1, $rows);
            $this->assertSame('720p MP4', $rows[0]['format']);
            $this->assertSame('10_720p.mp4', $rows[0]['file']);
            return;
        }

        $this->assertStringContainsString('720p MP4', $this->tester->getDisplay());
    }

    public static function provideOutputFormats(): array
    {
        return [
            'table format' => ['table'],
            'json format' => ['json'],
        ];
    }

    public function testDefaultActionShowsAvailableFormats(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringNotContainsString('Video ID is required', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('720p MP4', $output);
    }

    public function testRejectsUnknownAction(): void
    {
        $this->tester->execute([
            'action' => 'unknown_action',
            'video_id' => '10',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown formats action "unknown_action"', $this->tester->getDisplay());
    }

    public function testCommandHasExpectedAliases(): void
    {
        $aliases = $this->command->getAliases();

        $this->assertContains('formats', $aliases);
    }

    private function createVideoStorage(): void
    {
        $videoDir = $this->storagePath . '/0/10';
        mkdir($videoDir, 0775, true);
        file_put_contents($videoDir . '/10_720p.mp4', str_repeat('x', 1024));
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('videos') . ' (' .
            'video_id INTEGER, server_group_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_servers') . ' (' .
            'server_id INTEGER, path TEXT, content_type_id INTEGER, status_id INTEGER, is_remote INTEGER, group_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('formats_videos') . ' (' .
            'format_video_id INTEGER, title TEXT, postfix TEXT, status_id INTEGER, ' .
            'format_video_group_id INTEGER, access_level_id INTEGER)'
        );

        $db->exec('INSERT INTO ' . TestHelper::table('videos') . ' VALUES (10, 1)');
        $serverPath = $db->quote($this->storagePath);
        $db->exec(
            'INSERT INTO ' . TestHelper::table('admin_servers') .
            " VALUES (1, {$serverPath}, 1, 1, 0, 1)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('formats_videos') .
            " VALUES " .
            "(1, '720p MP4', '_720p.mp4', 1, 1, 0), " .
            "(2, '1080p MP4', '_1080p.mp4', 2, 1, 2)"
        );

        return $db;
    }

    private function createCommand(PDO $db): FormatsCommand
    {
        return new class ($this->config, $db) extends FormatsCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('video:formats');
                $this->setDescription('Manage video formats');
                $this->setAliases(['formats']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
