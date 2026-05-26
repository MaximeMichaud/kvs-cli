<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\VideoCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class VideoCommandDeleteTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testDeleteVideoUsesKvsNativeCleanup(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);

        $db->exec('INSERT INTO ktvs_videos (video_id) VALUES (1), (2)');
        $db->exec('INSERT INTO ktvs_categories_videos (video_id) VALUES (1)');
        $db->exec('INSERT INTO ktvs_tags_videos (video_id) VALUES (1)');
        $db->exec('INSERT INTO ktvs_models_videos (video_id) VALUES (1)');
        $db->exec('INSERT INTO ktvs_stats_videos_users_views (video_id) VALUES (1)');
        $db->exec(
            'INSERT INTO ktvs_comments (comment_id, object_id, object_type_id) VALUES ' .
            '(1, 1, 1), (2, 1, 2), (3, 1, 13), (4, 2, 1)'
        );

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends VideoCommand {
            /** @var list<int> */
            public array $deletedVideoIds = [];

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:video');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deleteVideoWithKvs(int $videoId): void
            {
                $this->deletedVideoIds[] = $videoId;
            }
        };

        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'delete', 'id' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $command->deletedVideoIds);

        $remainingResult = $db->query('SELECT comment_id FROM ktvs_comments ORDER BY comment_id');
        $remaining = $remainingResult->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([1, 2, 3, 4], array_map('intval', $remaining));

        $videos = $db->query('SELECT video_id FROM ktvs_videos ORDER BY video_id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([1, 2], array_map('intval', $videos));
    }

    public function testDeleteVideoDoesNotCallKvsCleanupWhenVideoDoesNotExist(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends VideoCommand {
            public bool $kvsCleanupCalled = false;

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:video');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deleteVideoWithKvs(int $videoId): void
            {
                $this->kvsCleanupCalled = true;
            }
        };

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'delete', 'id' => '999']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertFalse($command->kvsCleanupCalled);
        $this->assertStringContainsString('Video not found', $tester->getDisplay());
    }

    public function testDeleteVideoNoInteractionFailsWithoutCleanup(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);
        $db->exec('INSERT INTO ktvs_videos (video_id) VALUES (1)');

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends VideoCommand {
            public bool $kvsCleanupCalled = false;

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:video');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deleteVideoWithKvs(int $videoId): void
            {
                $this->kvsCleanupCalled = true;
            }
        };

        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'delete',
            'id' => '1',
        ], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertFalse($command->kvsCleanupCalled);
        $this->assertStringContainsString('confirmation was not provided', $tester->getDisplay());
    }

    private function createDeleteSchema(\PDO $db): void
    {
        $db->exec('CREATE TABLE ktvs_videos (video_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories_videos (video_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags_videos (video_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_models_videos (video_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_stats_videos_users_views (video_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (comment_id INTEGER, object_id INTEGER, object_type_id INTEGER)');
    }
}
