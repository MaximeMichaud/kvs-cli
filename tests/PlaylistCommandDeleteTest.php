<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\PlaylistCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PlaylistCommandDeleteTest extends TestCase
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

    public function testDeletePlaylistUsesKvsNativeCleanup(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);

        $db->exec("INSERT INTO ktvs_playlists (playlist_id, title, is_locked) VALUES (1, 'Test', 0), (2, 'Other', 0)");
        $db->exec('INSERT INTO ktvs_fav_videos (playlist_id, video_id) VALUES (1, 10)');
        $db->exec('INSERT INTO ktvs_categories_playlists (playlist_id, category_id) VALUES (1, 20)');
        $db->exec('INSERT INTO ktvs_tags_playlists (playlist_id, tag_id) VALUES (1, 30)');
        $db->exec('INSERT INTO ktvs_comments (comment_id, object_id, object_type_id) VALUES (1, 1, 13)');
        $db->exec(
            'INSERT INTO ktvs_users_subscriptions (subscribed_object_id, subscribed_object_type_id) VALUES (1, 13)'
        );

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends PlaylistCommand {
            /** @var list<int> */
            public array $deletedPlaylistIds = [];

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:playlist');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deletePlaylistWithKvs(int $playlistId): void
            {
                $this->deletedPlaylistIds[] = $playlistId;
            }
        };

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'delete', 'id' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $command->deletedPlaylistIds);

        $playlistResult = $db->query('SELECT playlist_id FROM ktvs_playlists ORDER BY playlist_id');
        $playlists = $playlistResult->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([1, 2], array_map('intval', $playlists));

        $favVideos = $db->query('SELECT playlist_id FROM ktvs_fav_videos')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([1], array_map('intval', $favVideos));

        $comments = $db->query('SELECT comment_id FROM ktvs_comments')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([1], array_map('intval', $comments));
    }

    public function testDeletePlaylistDoesNotCallKvsCleanupWhenPlaylistDoesNotExist(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends PlaylistCommand {
            public bool $kvsCleanupCalled = false;

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:playlist');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deletePlaylistWithKvs(int $playlistId): void
            {
                $this->kvsCleanupCalled = true;
            }
        };

        $tester = new CommandTester($command);
        $tester->execute(['action' => 'delete', 'id' => '999']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertFalse($command->kvsCleanupCalled);
        $this->assertStringContainsString('Playlist not found', $tester->getDisplay());
    }

    public function testDeletePlaylistDoesNotCallKvsCleanupWhenPlaylistIsLocked(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);
        $db->exec("INSERT INTO ktvs_playlists (playlist_id, title, is_locked) VALUES (1, 'Locked', 1)");

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends PlaylistCommand {
            public bool $kvsCleanupCalled = false;

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:playlist');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deletePlaylistWithKvs(int $playlistId): void
            {
                $this->kvsCleanupCalled = true;
            }
        };

        $tester = new CommandTester($command);
        $tester->execute(['action' => 'delete', 'id' => '1']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertFalse($command->kvsCleanupCalled);
        $this->assertStringContainsString('locked', $tester->getDisplay());
    }

    public function testDeletePlaylistNoInteractionFailsWithoutCleanup(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);
        $db->exec("INSERT INTO ktvs_playlists (playlist_id, title, is_locked) VALUES (1, 'Test', 0)");

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends PlaylistCommand {
            public bool $kvsCleanupCalled = false;

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:playlist');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deletePlaylistWithKvs(int $playlistId): void
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
        $db->exec('CREATE TABLE ktvs_playlists (playlist_id INTEGER, title TEXT, is_locked INTEGER)');
        $db->exec('CREATE TABLE ktvs_fav_videos (playlist_id INTEGER, video_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories_playlists (playlist_id INTEGER, category_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags_playlists (playlist_id INTEGER, tag_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (comment_id INTEGER, object_id INTEGER, object_type_id INTEGER)');
        $db->exec(
            'CREATE TABLE ktvs_users_subscriptions (subscribed_object_id INTEGER, subscribed_object_type_id INTEGER)'
        );
    }
}
