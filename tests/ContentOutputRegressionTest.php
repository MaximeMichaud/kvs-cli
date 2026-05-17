<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Command\Content\CommentCommand;
use KVS\CLI\Command\Content\TagCommand;
use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Command\Content\VideoCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ContentOutputRegressionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kvs-content-output-regression-' . uniqid();
        TestHelper::createMockKvsInstallation($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            TestHelper::removeDir($this->tempDir);
        }
    }

    public function testVideoListExposesDocumentedCalculatedFields(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, user_id INTEGER, status_id INTEGER, title TEXT, post_date TEXT, ' .
            'video_viewed INTEGER, rating_amount INTEGER, rating REAL, resolution_type INTEGER, ' .
            'file_size INTEGER, favourites_count INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'author')");
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(20, 1, 1, 'Daily news', '2024-01-02 00:00:00', 15, 4, 16, 2, 5050, 7)"
        );

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,title,filesize,resolution,favourites',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(20, (int) $rows[0]['id']);
        $this->assertSame('5050', (string) $rows[0]['filesize']);
        $this->assertSame('FHD', $rows[0]['resolution']);
        $this->assertSame(7, (int) $rows[0]['favourites']);
    }

    public function testVideoListFormatsKvsPrivacyField(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, user_id INTEGER, status_id INTEGER, title TEXT, post_date TEXT, ' .
            'video_viewed INTEGER, rating_amount INTEGER, rating REAL, resolution_type INTEGER, ' .
            'is_private INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'author')");
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(20, 1, 1, 'Premium news', '2024-01-02 00:00:00', 15, 4, 16, 2, 2)"
        );

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,title,is_private',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('Premium', $rows[0]['is_private']);
    }

    public function testVideoStatsFlagShowsStatsWithoutAction(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, title TEXT, status_id INTEGER, video_viewed INTEGER, duration INTEGER, ' .
            'rating REAL, rating_amount INTEGER, file_size INTEGER)'
        );
        $db->exec("INSERT INTO ktvs_videos VALUES (20, 'Daily news', 1, 15, 120, 20, 5, 5050)");

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute(['--stats' => true]);
        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Total Videos', $output);
        $this->assertStringNotContainsString('Available actions', $output);
    }

    public function testVideoShowLabelsDimensionsSeparatelyFromResolution(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, title TEXT, status_id INTEGER, resolution_type INTEGER, is_private INTEGER, ' .
            'duration INTEGER, file_size INTEGER, file_dimensions TEXT, post_date TEXT, rating REAL, ' .
            'rating_amount INTEGER, video_viewed INTEGER, favourites_count INTEGER, description TEXT)'
        );
        $db->exec('CREATE TABLE ktvs_categories (category_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ktvs_categories_videos (category_id INTEGER, video_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags (tag_id INTEGER, tag TEXT)');
        $db->exec('CREATE TABLE ktvs_tags_videos (tag_id INTEGER, video_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(20, 'Daily news', 1, 2, 0, 120, 5050, '1280x720', '2024-01-02 00:00:00', 20, 5, 15, 7, '')"
        );

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute(['action' => 'show', 'id' => '20']);
        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(1, substr_count($output, 'Resolution'));
        $this->assertStringContainsString('Dimensions', $output);
    }

    public function testVideoShowDisplaysPremiumPrivacyLabel(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, title TEXT, status_id INTEGER, resolution_type INTEGER, is_private INTEGER, ' .
            'duration INTEGER, file_size INTEGER, file_dimensions TEXT, post_date TEXT, rating REAL, ' .
            'rating_amount INTEGER, video_viewed INTEGER, favourites_count INTEGER, description TEXT)'
        );
        $db->exec('CREATE TABLE ktvs_categories (category_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ktvs_categories_videos (category_id INTEGER, video_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags (tag_id INTEGER, tag TEXT)');
        $db->exec('CREATE TABLE ktvs_tags_videos (tag_id INTEGER, video_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(20, 'Premium news', 1, 2, 2, 120, 5050, '1280x720', '2024-01-02 00:00:00', 20, 5, 15, 7, '')"
        );

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute(['action' => 'show', 'id' => '20']);
        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/Access\W+Premium/', $output);
        $this->assertStringNotContainsString('Private    │ Yes', $output);
    }

    public function testCommentListExposesDocumentedContentFields(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_comments (' .
            'comment_id INTEGER, object_id INTEGER, object_type_id INTEGER, user_id INTEGER, ' .
            'comment TEXT, added_date TEXT, is_approved INTEGER, is_review_needed INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec('CREATE TABLE ktvs_videos (video_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ktvs_albums (album_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ktvs_content_sources (content_source_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ktvs_models (model_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ktvs_dvds (dvd_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ktvs_posts (post_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ktvs_playlists (playlist_id INTEGER, title TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'commenter')");
        $db->exec("INSERT INTO ktvs_videos VALUES (20, 'Daily news')");
        $db->exec(
            "INSERT INTO ktvs_comments VALUES " .
            "(198, 20, 1, 1, 'Nice post', '2024-01-02 00:00:00', 1, 0)"
        );

        $tester = new CommandTester($this->createCommentCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,type,content,content_title',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(198, (int) $rows[0]['id']);
        $this->assertSame('Video', $rows[0]['type']);
        $this->assertSame(20, (int) $rows[0]['content']);
        $this->assertSame('Daily news', $rows[0]['content_title']);
    }

    public function testUserListExposesFormattedUserStatus(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_users (' .
            'user_id INTEGER, username TEXT, display_name TEXT, email TEXT, status_id INTEGER, added_date TEXT)'
        );
        $db->exec('CREATE TABLE ktvs_videos (user_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums (user_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_users VALUES " .
            "(1, 'member', 'Member', 'member@example.com', 2, '2024-01-01 00:00:00'), " .
            "(2, 'anonymous', 'Anonymous', 'anonymous@example.com', 4, '2024-01-02 00:00:00')"
        );

        $tester = new CommandTester($this->createUserCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,username,status',
            '--format' => 'json',
            '--limit' => '2',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('Anonymous', $rows[0]['status']);
        $this->assertSame('Active', $rows[1]['status']);

        $defaultTester = new CommandTester($this->createUserCommand($db));
        $defaultTester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--limit' => '1',
        ]);
        $defaultRows = $this->decodeJsonRows($defaultTester->getDisplay());

        $this->assertSame(0, $defaultTester->getStatusCode());
        $this->assertSame('Anonymous', $defaultRows[0]['status']);
        $this->assertArrayNotHasKey('status_id', $defaultRows[0]);
    }

    public function testUserShowUsesKvsGenderLabels(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_users (' .
            'user_id INTEGER, username TEXT, email TEXT, display_name TEXT, status_id INTEGER, country_id TEXT, ' .
            'gender_id INTEGER, birth_date TEXT, added_date TEXT, last_login_date TEXT, ip TEXT, ' .
            'profile_viewed INTEGER, logins_count INTEGER, activity INTEGER, ' .
            'tokens_available INTEGER, tokens_required INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_videos (user_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums (user_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (user_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_users VALUES " .
            "(1, 'unset', 'unset@example.com', 'Unset', 2, '', 0, '', " .
            "'2024-01-01 00:00:00', '', '', 0, 0, 0, 0, 0), " .
            "(2, 'couple', 'couple@example.com', 'Couple', 2, '', 3, '', " .
            "'2024-01-01 00:00:00', '', '', 0, 0, 0, 0, 0), " .
            "(3, 'transsexual', 'transsexual@example.com', 'Transsexual', 2, '', 4, '', " .
            "'2024-01-01 00:00:00', '', '', 0, 0, 0, 0, 0)"
        );

        foreach ([1 => 'N/A', 2 => 'Couple', 3 => 'Transsexual'] as $userId => $expectedGender) {
            $tester = new CommandTester($this->createUserCommand($db));
            $tester->execute(['action' => 'show', 'id' => (string) $userId]);

            $this->assertSame(0, $tester->getStatusCode());
            $this->assertMatchesRegularExpression(
                '/Gender\W+' . preg_quote($expectedGender, '/') . '/',
                $tester->getDisplay()
            );
        }
    }

    public function testCategoryListExposesFormattedStatus(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec('CREATE TABLE ktvs_categories (category_id INTEGER, title TEXT, status_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories_videos (category_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories_albums (category_id INTEGER)');
        $db->exec("INSERT INTO ktvs_categories VALUES (31, 'Art', 1)");

        $tester = new CommandTester($this->createCategoryCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,title,status',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(31, (int) $rows[0]['id']);
        $this->assertSame('Active', $rows[0]['status']);
    }

    public function testTagShowCountsAllKvsTagRelations(): void
    {
        $db = $this->createSqliteConnection();
        $this->createTagTables($db);
        $db->exec("INSERT INTO ktvs_tags VALUES (34, 'review', 'review', 1, '2024-01-01 00:00:00')");
        $db->exec('INSERT INTO ktvs_tags_videos VALUES (34), (34), (34), (34)');
        $db->exec('INSERT INTO ktvs_tags_albums VALUES (34)');
        $db->exec('INSERT INTO ktvs_tags_posts VALUES (34)');

        $tester = new CommandTester($this->createTagCommand($db));
        $tester->execute(['action' => 'show', 'identifier' => '34']);
        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/Posts\s*│\s*1/', $output);
        $this->assertMatchesRegularExpression('/Total Usage\s*│\s*6/', $output);
    }

    public function testTagStatsCountsTagsUsedOutsideVideosAndAlbums(): void
    {
        $db = $this->createSqliteConnection();
        $this->createTagTables($db);
        $db->exec(
            "INSERT INTO ktvs_tags VALUES " .
            "(34, 'review', 'review', 1, '2024-01-01 00:00:00'), " .
            "(35, 'post only', 'post-only', 1, '2024-01-01 00:00:00')"
        );
        $db->exec('INSERT INTO ktvs_tags_videos VALUES (34)');
        $db->exec('INSERT INTO ktvs_tags_posts VALUES (35)');

        $tester = new CommandTester($this->createTagCommand($db));
        $tester->execute(['action' => 'stats']);
        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/Used Tags\s*│\s*2/', $output);
        $this->assertStringContainsString('Other', $output);
    }

    private function createSqliteConnection(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $db;
    }

    private function createTagTables(\PDO $db): void
    {
        $db->exec(
            'CREATE TABLE ktvs_tags ' .
            '(tag_id INTEGER, tag TEXT, tag_dir TEXT, status_id INTEGER, added_date TEXT)'
        );
        $tagRelations = ['videos', 'albums', 'posts', 'playlists', 'content_sources', 'models', 'dvds', 'dvds_groups'];
        foreach ($tagRelations as $suffix) {
            $db->exec("CREATE TABLE ktvs_tags_{$suffix} (tag_id INTEGER)");
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeJsonRows(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    private function createVideoCommand(\PDO $db): VideoCommand
    {
        return new class ($this->createConfig(), $db) extends VideoCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:video');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createCommentCommand(\PDO $db): CommentCommand
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

    private function createTagCommand(\PDO $db): TagCommand
    {
        return new class ($this->createConfig(), $db) extends TagCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:tag');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createCategoryCommand(\PDO $db): CategoryCommand
    {
        return new class ($this->createConfig(), $db) extends CategoryCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:category');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createUserCommand(\PDO $db): UserCommand
    {
        return new class ($this->createConfig(), $db) extends UserCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:user');
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
}
