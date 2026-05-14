<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Video\FormatsCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class FormatsPathTest extends TestCase
{
    private string $tempDir;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kvs-formats-path-test-' . uniqid();
        TestHelper::createMockKvsInstallation($this->tempDir);

        $videoDir = $this->tempDir . '/contents/videos/1000/1234';
        mkdir($videoDir, 0755, true);
        file_put_contents($videoDir . '/1234.mp4', 'video');

        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE ktvs_videos (video_id INTEGER, server_group_id INTEGER)');
        $this->db->exec('CREATE TABLE ktvs_admin_servers (
            server_id INTEGER,
            group_id INTEGER,
            content_type_id INTEGER,
            status_id INTEGER,
            is_remote INTEGER,
            path TEXT
        )');
        $this->db->exec('CREATE TABLE ktvs_formats_videos (
            format_video_id INTEGER,
            title TEXT,
            postfix TEXT,
            status_id INTEGER,
            format_video_group_id INTEGER,
            access_level_id INTEGER
        )');

        $storagePath = $this->db->quote($this->tempDir . '/contents/videos');
        $this->db->exec('INSERT INTO ktvs_videos (video_id, server_group_id) VALUES (1234, 1)');
        $this->db->exec(
            "INSERT INTO ktvs_admin_servers
                (server_id, group_id, content_type_id, status_id, is_remote, path)
             VALUES (1, 1, 1, 1, 0, {$storagePath})"
        );
        $this->db->exec(
            "INSERT INTO ktvs_formats_videos
                (format_video_id, title, postfix, status_id, format_video_group_id, access_level_id)
             VALUES (1, 'MP4 480p', '.mp4', 1, 1, 0)"
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            TestHelper::removeDir($this->tempDir);
        }
    }

    public function testListUsesNativeKvsVideoStorageDirectory(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute([
            'action' => 'list',
            'video_id' => '1234',
            '--format' => 'json',
        ]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('1234.mp4', $output);
        $this->assertStringContainsString('MP4 480p', $output);
        $this->assertStringNotContainsString('videos_sources', $output);
    }

    public function testCheckUsesKvsVideoIdPostfixFilename(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute([
            'action' => 'check',
            'video_id' => '1234',
        ]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('MP4 480p (.mp4', $output);
        $this->assertStringNotContainsString('Missing Formats', $output);
    }

    public function testAvailableFormatsJsonDoesNotIncludeTableDecoration(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute([
            'action' => 'available',
            '--format' => 'json',
        ]);

        $output = $tester->getDisplay();
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertIsArray($decoded);
        $this->assertSame('MP4 480p', $decoded[0]['title']);
        $this->assertStringNotContainsString('Available Format Configurations', $output);
        $this->assertStringNotContainsString('These formats are configured', $output);
    }

    private function createCommand(): FormatsCommand
    {
        return new class (new Configuration(['path' => $this->tempDir]), $this->db) extends FormatsCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }
}
