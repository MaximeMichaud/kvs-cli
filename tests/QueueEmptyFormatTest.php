<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\QueueCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class QueueEmptyFormatTest extends TestCase
{
    private string $tempDir;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-queue-empty-format-test-');
        TestHelper::createMockKvsInstallation($this->tempDir);

        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE ktvs_background_tasks (
            task_id INTEGER,
            status_id INTEGER,
            type_id INTEGER,
            video_id INTEGER,
            album_id INTEGER,
            server_id INTEGER,
            error_code INTEGER,
            priority INTEGER,
            added_date TEXT
        )');
        $this->db->exec('CREATE TABLE ktvs_background_tasks_history (
            task_id INTEGER,
            status_id INTEGER,
            type_id INTEGER,
            video_id INTEGER,
            album_id INTEGER,
            effective_duration INTEGER,
            end_date TEXT
        )');
        $this->db->exec('CREATE TABLE ktvs_admin_conversion_servers (server_id INTEGER, title TEXT)');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            TestHelper::removeDir($this->tempDir);
        }
    }

    public function testEmptyQueueListJsonIsValidArray(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute([
            'action' => 'list',
            '--format' => 'json',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testEmptyQueueListCountIsZero(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute([
            'action' => 'list',
            '--format' => 'count',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame("0\n", $tester->getDisplay());
    }

    public function testEmptyQueueDefaultActionShowsEmptyMessage(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute([]);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Queue is empty', $output);
        $this->assertStringNotContainsString('Background Tasks Queue', $output);
    }

    public function testQueueListSingularTaskCount(): void
    {
        $this->db->exec(
            "INSERT INTO ktvs_background_tasks
                (task_id, status_id, type_id, video_id, album_id, server_id, error_code, priority, added_date)
             VALUES
                (123, 0, 1, 456, NULL, NULL, 0, 10, '2026-05-26 12:00:00')"
        );

        $tester = new CommandTester($this->createCommand());
        $tester->execute(['action' => 'list']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Showing 1 task', $output);
        $this->assertStringNotContainsString('Showing 1 tasks', $output);
    }

    public function testEmptyQueueHistoryJsonIsValidArray(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute([
            'action' => 'history',
            '--format' => 'json',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testEmptyQueueHistoryCountIsZero(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->execute([
            'action' => 'history',
            '--format' => 'count',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame("0\n", $tester->getDisplay());
    }

    public function testQueueHistorySingularTaskCount(): void
    {
        $this->db->exec(
            "INSERT INTO ktvs_background_tasks_history
                (task_id, status_id, type_id, video_id, album_id, effective_duration, end_date)
             VALUES
                (234, 3, 1, 456, NULL, 12, '2026-05-26 12:00:00')"
        );

        $tester = new CommandTester($this->createCommand());
        $tester->execute(['action' => 'history']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Showing 1 task', $output);
        $this->assertStringNotContainsString('Showing 1 tasks', $output);
    }

    private function createCommand(): QueueCommand
    {
        return new class (new Configuration(['path' => $this->tempDir]), $this->db) extends QueueCommand {
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
