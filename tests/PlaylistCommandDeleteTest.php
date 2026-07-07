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

    public function testDeletePlaylistCallsCleanupAfterConfirmation(): void
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

    public function testDeletePlaylistTreatsStringLockedFlagAsLocked(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
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

    public function testDeletePlaylistYesRunsKvsCompatibleSqlCleanupWithoutPrompt(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);

        $db->exec("INSERT INTO ktvs_playlists (playlist_id, title, is_locked) VALUES (1, 'Test', 0), (2, 'Other', 0)");
        $db->exec('INSERT INTO ktvs_fav_videos (playlist_id, video_id) VALUES (1, 10), (2, 10), (1, 11)');
        $db->exec('INSERT INTO ktvs_videos (video_id, favourites_count) VALUES (10, 2), (11, 1)');
        $db->exec('INSERT INTO ktvs_categories (category_id, total_playlists) VALUES (20, 2), (21, 1)');
        $db->exec('INSERT INTO ktvs_categories_playlists (playlist_id, category_id) VALUES (1, 20), (2, 20), (1, 21)');
        $db->exec('INSERT INTO ktvs_tags (tag_id, total_playlists) VALUES (30, 2), (31, 1)');
        $db->exec('INSERT INTO ktvs_tags_playlists (playlist_id, tag_id) VALUES (1, 30), (2, 30), (1, 31)');
        $db->exec('INSERT INTO ktvs_flags_playlists (playlist_id) VALUES (1)');
        $db->exec('INSERT INTO ktvs_flags_history (playlist_id) VALUES (1)');
        $db->exec('INSERT INTO ktvs_flags_messages (playlist_id) VALUES (1)');
        $db->exec('INSERT INTO ktvs_users_events (playlist_id) VALUES (1)');
        $db->exec(
            'INSERT INTO ktvs_comments (comment_id, object_id, object_type_id, user_id, is_approved) VALUES ' .
            '(1, 1, 13, 5, 1), (2, 2, 13, 5, 1), (3, 1, 12, 5, 1)'
        );
        $db->exec(
            'INSERT INTO ktvs_users (user_id, comments_playlists_count, comments_total_count) VALUES ' .
            '(5, 2, 3)'
        );
        $db->exec(
            'INSERT INTO ktvs_users_subscriptions (subscribed_object_id, subscribed_object_type_id) VALUES (1, 13)'
        );

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends PlaylistCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:playlist');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };

        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'delete',
            'id' => '1',
            '--yes' => true,
        ], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('deleted with KVS-compatible cleanup', $tester->getDisplay());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_playlists WHERE playlist_id = 1')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM ktvs_playlists WHERE playlist_id = 2')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_fav_videos WHERE playlist_id = 1')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_categories_playlists WHERE playlist_id = 1')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_tags_playlists WHERE playlist_id = 1')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_flags_playlists WHERE playlist_id = 1')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_flags_history WHERE playlist_id = 1')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_flags_messages WHERE playlist_id = 1')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_users_events WHERE playlist_id = 1')->fetchColumn());
        $this->assertSame(
            0,
            (int) $db->query('SELECT COUNT(*) FROM ktvs_comments WHERE object_id = 1 AND object_type_id = 13')->fetchColumn()
        );
        $this->assertSame(
            0,
            (int) $db->query(
                'SELECT COUNT(*) FROM ktvs_users_subscriptions ' .
                'WHERE subscribed_object_id = 1 AND subscribed_object_type_id = 13'
            )->fetchColumn()
        );
        $this->assertSame(1, (int) $db->query('SELECT total_playlists FROM ktvs_categories WHERE category_id = 20')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT total_playlists FROM ktvs_categories WHERE category_id = 21')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT total_playlists FROM ktvs_tags WHERE tag_id = 30')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT total_playlists FROM ktvs_tags WHERE tag_id = 31')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT favourites_count FROM ktvs_videos WHERE video_id = 10')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT favourites_count FROM ktvs_videos WHERE video_id = 11')->fetchColumn());
        $this->assertSame(
            1,
            (int) $db->query('SELECT comments_playlists_count FROM ktvs_users WHERE user_id = 5')->fetchColumn()
        );
        $this->assertSame(2, (int) $db->query('SELECT comments_total_count FROM ktvs_users WHERE user_id = 5')->fetchColumn());
        $this->assertSame(
            1,
            (int) $db->query(
                'SELECT COUNT(*) FROM ktvs_admin_audit_log ' .
                'WHERE action_id = 180 AND object_id = 1 AND object_type_id = 13 AND username = \'kvs-cli\''
            )->fetchColumn()
        );
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
        $db->exec('CREATE TABLE ktvs_videos (video_id INTEGER, favourites_count INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories (category_id INTEGER, total_playlists INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories_playlists (playlist_id INTEGER, category_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags (tag_id INTEGER, total_playlists INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags_playlists (playlist_id INTEGER, tag_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_flags_playlists (playlist_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_flags_history (playlist_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_flags_messages (playlist_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users_events (playlist_id INTEGER)');
        $db->exec(
            'CREATE TABLE ktvs_comments (' .
            'comment_id INTEGER, object_id INTEGER, object_type_id INTEGER, user_id INTEGER, is_approved INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ktvs_users (' .
            'user_id INTEGER, comments_playlists_count INTEGER, comments_total_count INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ktvs_users_subscriptions (subscribed_object_id INTEGER, subscribed_object_type_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ktvs_admin_audit_log (' .
            'record_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, username TEXT, action_id INTEGER, ' .
            'object_id INTEGER, object_type_id INTEGER, action_details TEXT, added_date TEXT)'
        );
    }
}
