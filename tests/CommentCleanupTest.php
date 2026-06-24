<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\CommentCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CommentCleanupTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        TestHelper::createMockDbConfig($this->tempDir);
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php $config = ["tables_prefix" => "ktvs_"];');
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->tempDir);
    }

    public function testRejectCommentUpdatesAlbumImageCountsAndAuditLog(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $db->exec("INSERT INTO ktvs_albums (album_id, comments_count) VALUES (1, 2)");
        $db->exec("INSERT INTO ktvs_albums_images (image_id, album_id, comments_count) VALUES (10, 1, 2)");
        $db->exec(
            "INSERT INTO ktvs_users (
                user_id, username, comments_albums_count, comments_total_count
            ) VALUES (5, 'tester', 2, 2)"
        );
        $db->exec(
            "INSERT INTO ktvs_comments (
                comment_id, object_id, object_sub_id, object_type_id, user_id, comment, is_approved, is_review_needed
            ) VALUES
                (1, 1, 10, 2, 5, 'Delete me', 1, 0),
                (2, 1, 10, 2, 5, 'Keep me', 1, 0)"
        );
        $db->exec('INSERT INTO ktvs_users_events (comment_id) VALUES (1)');

        $tester = new CommandTester($this->createCommand($db));
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'reject', 'id' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_comments WHERE comment_id = 1'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_comments WHERE comment_id = 2'));
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_users_events WHERE comment_id = 1'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT comments_count FROM ktvs_albums WHERE album_id = 1'));
        $this->assertSame(
            1,
            $this->fetchInt($db, 'SELECT comments_count FROM ktvs_albums_images WHERE image_id = 10')
        );
        $this->assertSame(
            1,
            $this->fetchInt($db, 'SELECT comments_albums_count FROM ktvs_users WHERE user_id = 5')
        );
        $this->assertSame(1, $this->fetchInt($db, 'SELECT comments_total_count FROM ktvs_users WHERE user_id = 5'));
        $this->assertSame(
            1,
            $this->fetchInt(
                $db,
                'SELECT COUNT(*) FROM ktvs_admin_audit_log
                 WHERE action_id = 180 AND object_id = 1 AND object_type_id = 15'
            )
        );
    }

    public function testApproveCommentUpdatesAlbumImageCountsAndAuditLog(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $db->exec("INSERT INTO ktvs_albums (album_id, title, comments_count) VALUES (1, 'Album One', 1)");
        $db->exec("INSERT INTO ktvs_albums_images (image_id, album_id, comments_count) VALUES (10, 1, 1)");
        $db->exec(
            "INSERT INTO ktvs_users (
                user_id, username, comments_albums_count, comments_total_count
            ) VALUES (5, 'tester', 1, 1)"
        );
        $db->exec(
            "INSERT INTO ktvs_comments (
                comment_id, object_id, object_sub_id, object_type_id, user_id, comment, is_approved, is_review_needed
            ) VALUES
                (3, 1, 10, 2, 5, 'Approve me', 0, 1),
                (4, 1, 10, 2, 5, 'Already approved', 1, 0)"
        );

        $tester = new CommandTester($this->createCommand($db));
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'approve', 'id' => '3']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(1, $this->fetchInt($db, 'SELECT is_approved FROM ktvs_comments WHERE comment_id = 3'));
        $this->assertSame(0, $this->fetchInt($db, 'SELECT is_review_needed FROM ktvs_comments WHERE comment_id = 3'));
        $this->assertSame(2, $this->fetchInt($db, 'SELECT comments_count FROM ktvs_albums WHERE album_id = 1'));
        $this->assertSame(
            2,
            $this->fetchInt($db, 'SELECT comments_count FROM ktvs_albums_images WHERE image_id = 10')
        );
        $this->assertSame(
            2,
            $this->fetchInt($db, 'SELECT comments_albums_count FROM ktvs_users WHERE user_id = 5')
        );
        $this->assertSame(2, $this->fetchInt($db, 'SELECT comments_total_count FROM ktvs_users WHERE user_id = 5'));
        $this->assertSame(
            1,
            $this->fetchInt(
                $db,
                "SELECT COUNT(*) FROM ktvs_admin_audit_log
                 WHERE action_id = 150 AND object_id = 3
                   AND object_type_id = 15 AND action_details = 'is_approved'"
            )
        );
    }

    public function testApproveAlreadyApprovedReviewNeededCommentMarksReviewed(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $db->exec("INSERT INTO ktvs_albums (album_id, title, comments_count) VALUES (1, 'Album One', 1)");
        $db->exec(
            "INSERT INTO ktvs_users (
                user_id, username, comments_albums_count, comments_total_count
            ) VALUES (5, 'tester', 1, 1)"
        );
        $db->exec(
            "INSERT INTO ktvs_comments (
                comment_id, object_id, object_sub_id, object_type_id, user_id, comment, is_approved, is_review_needed
            ) VALUES (5, 1, 0, 2, 5, 'Already approved but still needs review', 1, 1)"
        );

        $tester = new CommandTester($this->createCommand($db));
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'approve', 'id' => '5']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(1, $this->fetchInt($db, 'SELECT is_approved FROM ktvs_comments WHERE comment_id = 5'));
        $this->assertSame(0, $this->fetchInt($db, 'SELECT is_review_needed FROM ktvs_comments WHERE comment_id = 5'));
        $this->assertSame(
            0,
            $this->fetchInt(
                $db,
                "SELECT COUNT(*) FROM ktvs_admin_audit_log
                 WHERE action_id = 150 AND object_id = 5
                   AND object_type_id = 15 AND action_details = 'is_approved'"
            )
        );
    }

    public function testApproveCommentGrantsKvsCommentAwardTokens(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->createMemberzoneParams([
            'AWARDS_COMMENT_VIDEO' => '7',
            'AWARDS_COMMENT_VIDEO_CONDITION' => '10',
        ]);

        $db->exec("INSERT INTO ktvs_videos (video_id, title, comments_count) VALUES (1, 'Video One', 0)");
        $db->exec(
            "INSERT INTO ktvs_users (
                user_id, username, status_id, tokens_available, comments_videos_count, comments_total_count
            ) VALUES
                (4, 'anonymous', 4, 0, 0, 0),
                (5, 'tester', 2, 1, 0, 0)"
        );
        $db->exec(
            "INSERT INTO ktvs_comments (
                comment_id, object_id, object_sub_id, object_type_id, user_id, comment, is_approved, is_review_needed
            ) VALUES (13, 1, 0, 1, 5, 'Long enough comment for award', 0, 1)"
        );

        $tester = new CommandTester($this->createCommand($db));
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'approve', 'id' => '13']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(8, $this->fetchInt($db, 'SELECT tokens_available FROM ktvs_users WHERE user_id = 5'));
        $this->assertSame(
            1,
            $this->fetchInt(
                $db,
                'SELECT COUNT(*) FROM ktvs_log_awards_users
                 WHERE award_type = 3 AND user_id = 5 AND comment_id = 13 AND tokens_granted = 7'
            )
        );
    }

    public function testApproveWithoutInteractiveConfirmationFailsWithoutWrites(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $db->exec("INSERT INTO ktvs_albums (album_id, title, comments_count) VALUES (1, 'Album One', 1)");
        $db->exec(
            "INSERT INTO ktvs_users (
                user_id, username, comments_albums_count, comments_total_count
            ) VALUES (5, 'tester', 1, 1)"
        );
        $db->exec(
            "INSERT INTO ktvs_comments (
                comment_id, object_id, object_sub_id, object_type_id, user_id, comment, is_approved, is_review_needed
            ) VALUES (8, 1, 0, 2, 5, 'Approve cancelled', 0, 1)"
        );

        $tester = new CommandTester($this->createCommand($db));
        $tester->execute(
            ['action' => 'approve', 'id' => '8'],
            ['interactive' => false]
        );

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString(
            'Comment moderation cancelled because confirmation was not provided.',
            $tester->getDisplay()
        );
        $this->assertSame(0, $this->fetchInt($db, 'SELECT is_approved FROM ktvs_comments WHERE comment_id = 8'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT is_review_needed FROM ktvs_comments WHERE comment_id = 8'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT comments_count FROM ktvs_albums WHERE album_id = 1'));
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_admin_audit_log'));
    }

    public function testRejectWithoutInteractiveConfirmationFailsWithoutWrites(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $db->exec("INSERT INTO ktvs_albums (album_id, title, comments_count) VALUES (1, 'Album One', 1)");
        $db->exec(
            "INSERT INTO ktvs_users (
                user_id, username, comments_albums_count, comments_total_count
            ) VALUES (5, 'tester', 1, 1)"
        );
        $db->exec(
            "INSERT INTO ktvs_comments (
                comment_id, object_id, object_sub_id, object_type_id, user_id, comment, is_approved, is_review_needed
            ) VALUES (9, 1, 0, 2, 5, 'Reject cancelled', 1, 0)"
        );
        $db->exec('INSERT INTO ktvs_users_events (comment_id) VALUES (9)');

        $tester = new CommandTester($this->createCommand($db));
        $tester->execute(
            ['action' => 'reject', 'id' => '9'],
            ['interactive' => false]
        );

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString(
            'Comment moderation cancelled because confirmation was not provided.',
            $tester->getDisplay()
        );
        $this->assertSame(1, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_comments WHERE comment_id = 9'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_users_events WHERE comment_id = 9'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT comments_count FROM ktvs_albums WHERE album_id = 1'));
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_admin_audit_log'));
    }

    public function testListPendingUsesReviewNeededFlagLikeKvs(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $db->exec("INSERT INTO ktvs_albums (album_id, title, comments_count) VALUES (1, 'Album One', 1)");
        $db->exec(
            "INSERT INTO ktvs_users (
                user_id, username, comments_albums_count, comments_total_count
            ) VALUES (5, 'tester', 1, 1)"
        );
        $db->exec(
            "INSERT INTO ktvs_comments (
                comment_id, object_id, object_sub_id, object_type_id, user_id, comment, is_approved, is_review_needed
            ) VALUES (6, 1, 0, 2, 5, 'Review me even though approved', 1, 1)"
        );

        $tester = new CommandTester($this->createCommand($db));
        $tester->execute([
            'action' => 'list',
            '--pending' => true,
            '--search' => 'Review me even though approved',
            '--format' => 'json',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('"comment_id": 6', $output);
        $this->assertStringContainsString('"object_title": "Album One"', $output);
    }

    public function testListCommentsShowsPlaylistObjectTitle(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $db->exec("INSERT INTO ktvs_playlists (playlist_id, title, comments_count) VALUES (7, 'Playlist Seven', 1)");
        $db->exec(
            "INSERT INTO ktvs_users (
                user_id, username, comments_playlists_count, comments_total_count
            ) VALUES (5, 'tester', 1, 1)"
        );
        $db->exec(
            "INSERT INTO ktvs_comments (
                comment_id, object_id, object_sub_id, object_type_id, user_id, comment, is_approved, is_review_needed
            ) VALUES (7, 7, 0, 13, 5, 'Playlist comment marker', 1, 0)"
        );

        $tester = new CommandTester($this->createCommand($db));
        $tester->execute([
            'action' => 'list',
            '--search' => 'Playlist comment marker',
            '--format' => 'json',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('"object_type": "Playlist"', $output);
        $this->assertStringContainsString('"object_title": "Playlist Seven"', $output);
    }

    public function testListCountIgnoresPaginationLimit(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $db->exec(
            "INSERT INTO ktvs_comments (
                comment_id, object_id, object_sub_id, object_type_id, user_id, comment, is_approved, is_review_needed
            ) VALUES
                (10, 1, 0, 1, 0, 'First', 1, 0),
                (11, 1, 0, 1, 0, 'Second', 1, 0),
                (12, 1, 0, 1, 0, 'Third', 1, 0)"
        );

        $tester = new CommandTester($this->createCommand($db));
        $tester->execute([
            'action' => 'list',
            '--limit' => 2,
            '--format' => 'count',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('3', trim($tester->getDisplay()));
    }

    public function testApproveAllWithNoPendingCommentsIsSuccessfulNoop(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $tester = new CommandTester($this->createCommand($db));
        $tester->execute([
            'action' => 'approve',
            '--all' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('No pending comments to process', $tester->getDisplay());
    }

    public function testRejectAllWithNoPendingCommentsIsSuccessfulNoop(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $tester = new CommandTester($this->createCommand($db));
        $tester->execute([
            'action' => 'reject',
            '--all' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('No pending comments to process', $tester->getDisplay());
    }

    public function testRejectWithoutIdShowsRejectUsage(): void
    {
        $tester = new CommandTester($this->createCommand($this->createDatabase()));
        $tester->execute(['action' => 'reject']);

        $output = $tester->getDisplay();
        $this->assertSame(1, $tester->getStatusCode(), $output);
        $this->assertStringContainsString('Usage: kvs comment reject ID or reject 1,2,3 or reject --all', $output);
        $this->assertStringNotContainsString('Usage: kvs comment approve ID', $output);
    }

    public function testDeleteWithoutIdShowsDeleteUsage(): void
    {
        $tester = new CommandTester($this->createCommand($this->createDatabase()));
        $tester->execute(['action' => 'delete']);

        $output = $tester->getDisplay();
        $this->assertSame(1, $tester->getStatusCode(), $output);
        $this->assertStringContainsString('Usage: kvs comment delete ID or delete 1,2,3 or delete --all', $output);
        $this->assertStringNotContainsString('Usage: kvs comment approve ID', $output);
    }

    public function testApproveRejectsDecimalCommentIdWithoutWrites(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);

        $db->exec("INSERT INTO ktvs_albums (album_id, title, comments_count) VALUES (1, 'Album One', 0)");
        $db->exec(
            "INSERT INTO ktvs_users (
                user_id, username, comments_albums_count, comments_total_count
            ) VALUES (5, 'tester', 0, 0)"
        );
        $db->exec(
            "INSERT INTO ktvs_comments (
                comment_id, object_id, object_sub_id, object_type_id, user_id, comment, is_approved, is_review_needed
            ) VALUES (8, 1, 0, 2, 5, 'Approve decimal id', 0, 1)"
        );

        $tester = new CommandTester($this->createCommand($db));
        $tester->execute([
            'action' => 'approve',
            'id' => '8.9',
            '--yes' => true,
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Invalid comment ID: 8.9', $tester->getDisplay());
        $this->assertSame(0, $this->fetchInt($db, 'SELECT is_approved FROM ktvs_comments WHERE comment_id = 8'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT is_review_needed FROM ktvs_comments WHERE comment_id = 8'));
        $this->assertSame(0, $this->fetchInt($db, 'SELECT comments_count FROM ktvs_albums WHERE album_id = 1'));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function createMemberzoneParams(array $overrides): void
    {
        $dir = $this->tempDir . '/admin/data/system';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $defaults = [
            'AWARDS_COMMENT_VIDEO' => '0',
            'AWARDS_COMMENT_VIDEO_CONDITION' => '0',
            'AWARDS_COMMENT_ALBUM' => '0',
            'AWARDS_COMMENT_ALBUM_CONDITION' => '0',
            'AWARDS_COMMENT_CS' => '0',
            'AWARDS_COMMENT_CS_CONDITION' => '0',
            'AWARDS_COMMENT_MODEL' => '0',
            'AWARDS_COMMENT_MODEL_CONDITION' => '0',
            'AWARDS_COMMENT_DVD' => '0',
            'AWARDS_COMMENT_DVD_CONDITION' => '0',
            'AWARDS_COMMENT_POST' => '0',
            'AWARDS_COMMENT_POST_CONDITION' => '0',
            'AWARDS_COMMENT_PLAYLIST' => '0',
            'AWARDS_COMMENT_PLAYLIST_CONDITION' => '0',
        ];

        file_put_contents($dir . '/memberzone_params.dat', serialize(array_merge($defaults, $overrides)));
    }

    private function createCommand(\PDO $db): CommentCommand
    {
        return new class ($this->createConfig(), $db) extends CommentCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:comment');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createConfig(): Configuration
    {
        return new Configuration(['path' => $this->tempDir]);
    }

    private function createDatabase(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    private function createSchema(\PDO $db): void
    {
        $db->exec(
            'CREATE TABLE ktvs_comments (
                comment_id INTEGER,
                object_id INTEGER,
                object_sub_id INTEGER,
                object_type_id INTEGER,
                user_id INTEGER,
                comment TEXT,
                is_approved INTEGER,
                is_review_needed INTEGER,
                added_date TEXT DEFAULT "2026-01-01 00:00:00"
            )'
        );
        $db->exec('CREATE TABLE ktvs_users_events (comment_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums (album_id INTEGER, title TEXT, comments_count INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums_images (image_id INTEGER, album_id INTEGER, comments_count INTEGER)');
        foreach (['videos', 'content_sources', 'models', 'dvds', 'posts', 'playlists'] as $table) {
            $idColumn = [
                'videos' => 'video_id',
                'content_sources' => 'content_source_id',
                'models' => 'model_id',
                'dvds' => 'dvd_id',
                'posts' => 'post_id',
                'playlists' => 'playlist_id',
            ][$table];
            $db->exec("CREATE TABLE ktvs_{$table} ({$idColumn} INTEGER, title TEXT, comments_count INTEGER)");
        }
        $db->exec(
            'CREATE TABLE ktvs_users (
                user_id INTEGER,
                username TEXT,
                status_id INTEGER DEFAULT 2,
                tokens_available INTEGER DEFAULT 0,
                comments_videos_count INTEGER DEFAULT 0,
                comments_albums_count INTEGER DEFAULT 0,
                comments_cs_count INTEGER DEFAULT 0,
                comments_models_count INTEGER DEFAULT 0,
                comments_dvds_count INTEGER DEFAULT 0,
                comments_posts_count INTEGER DEFAULT 0,
                comments_playlists_count INTEGER DEFAULT 0,
                comments_total_count INTEGER DEFAULT 0
            )'
        );
        $db->exec(
            'CREATE TABLE ktvs_admin_audit_log (
                user_id INTEGER,
                username TEXT,
                action_id INTEGER,
                object_id INTEGER,
                object_type_id INTEGER,
                action_details TEXT,
                added_date TEXT
            )'
        );
        $db->exec(
            'CREATE TABLE ktvs_log_awards_users (
                award_type INTEGER,
                user_id INTEGER,
                comment_id INTEGER,
                tokens_granted INTEGER,
                added_date TEXT
            )'
        );
    }

    private function fetchInt(\PDO $db, string $sql): int
    {
        $value = $db->query($sql)->fetchColumn();
        return is_numeric($value) ? (int) $value : 0;
    }
}
