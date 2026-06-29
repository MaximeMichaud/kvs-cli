<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\StatsCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class StatsCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        file_put_contents(
            $this->tempDir . '/admin/include/setup_db.php',
            "<?php\n"
            . "define('DB_HOST', 'localhost');\n"
            . "define('DB_LOGIN', 'user');\n"
            . "define('DB_PASS', 'pass');\n"
            . "define('DB_DEVICE', 'kvs');\n"
        );
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            "<?php\n\$config = ['tables_prefix' => 'ktvs_'];\n"
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testVideoStatsUseKvsRatingPercent(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, title TEXT, dir TEXT, status_id INTEGER, has_errors INTEGER, ' .
            'video_viewed INTEGER, video_viewed_unique INTEGER, rating REAL, rating_amount INTEGER, ' .
            'comments_count INTEGER, favourites_count INTEGER, duration INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_comments (comment_id INTEGER, object_id INTEGER, object_type_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(1, 'Many Votes Lower Average', 'many', 1, 0, 100, 10, 80, 20, 0, 0, 60), " .
            "(2, 'Better Average', 'better', 1, 0, 200, 20, 45, 5, 0, 0, 120)"
        );

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--videos' => true, '--top' => '2']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Avg Rating %', $output);
        $this->assertStringContainsString('100.00%', $output);
        $this->assertStringContainsString('100.0% (5)', $output);
        $this->assertStringContainsString('80.0% (20)', $output);
        $this->assertStringNotContainsString('180.0% (5)', $output);

        $ratingSectionStart = strpos($output, 'Top by Rating %:');
        $this->assertIsInt($ratingSectionStart);
        $ratingSection = substr($output, $ratingSectionStart);
        $betterAveragePosition = strpos($ratingSection, 'Better Average');
        $manyVotesPosition = strpos($ratingSection, 'Many Votes Lower Average');
        $this->assertIsInt($betterAveragePosition);
        $this->assertIsInt($manyVotesPosition);
        $this->assertLessThan($manyVotesPosition, $betterAveragePosition);
    }

    public function testVideoStatsUseKvsAdminCommentRelationCounts(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, title TEXT, dir TEXT, status_id INTEGER, has_errors INTEGER, ' .
            'video_viewed INTEGER, video_viewed_unique INTEGER, rating REAL, rating_amount INTEGER, ' .
            'comments_count INTEGER, favourites_count INTEGER, duration INTEGER, added_date TEXT)'
        );
        $db->exec('CREATE TABLE ktvs_comments (comment_id INTEGER, object_id INTEGER, object_type_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(1, 'Stale Counter', 'stale', 1, 0, 100, 10, 45, 5, 0, 0, 60, '2026-01-01 00:00:00'), " .
            "(2, 'Other Video', 'other', 1, 0, 50, 5, 45, 5, 99, 0, 60, '2026-01-01 00:00:00')"
        );
        $db->exec(
            'INSERT INTO ktvs_comments VALUES ' .
            '(1, 1, 1), (2, 1, 1), (3, 2, 2)'
        );

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--videos' => true, '--top' => '1']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode(), $output);
        $this->assertMatchesRegularExpression('/Total Comments\W+2/', $output);
        $this->assertStringNotContainsString('99', $output);
    }

    public function testAlbumStatsUseKvsRatingPercent(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_albums (' .
            'album_id INTEGER, title TEXT, status_id INTEGER, album_viewed INTEGER, rating REAL, ' .
            'rating_amount INTEGER, photos_amount INTEGER)'
        );
        $db->exec(
            "INSERT INTO ktvs_albums VALUES " .
            "(1, 'High Rated Album', 1, 300, 90, 10, 8), " .
            "(2, 'Lower Rated Album', 1, 100, 25, 5, 4)"
        );

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--albums' => true, '--top' => '2']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Avg Rating %', $output);
        $this->assertStringContainsString('100.00%', $output);
        $this->assertStringContainsString('100.0% (10)', $output);
        $this->assertStringContainsString('100.0% (5)', $output);
        $this->assertStringNotContainsString('180.0% (10)', $output);
    }

    public function testModelAndDvdStatsUseKvsRatingPercent(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_models (' .
            'model_id INTEGER, title TEXT, status_id INTEGER, total_videos INTEGER, total_albums INTEGER, ' .
            'model_viewed INTEGER, rating REAL, rating_amount INTEGER)'
        );
        $db->exec(
            "INSERT INTO ktvs_models VALUES " .
            "(1, 'Model One', 1, 5, 2, 100, 80, 10)"
        );
        $db->exec(
            'CREATE TABLE ktvs_dvds (' .
            'dvd_id INTEGER, title TEXT, status_id INTEGER, total_videos INTEGER, dvd_viewed INTEGER, ' .
            'rating REAL, rating_amount INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_videos (video_id INTEGER, dvd_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_dvds VALUES " .
            "(1, 'DVD One', 1, 4, 90, 45, 5)"
        );
        $db->exec('INSERT INTO ktvs_videos VALUES (1, 1), (2, 1), (3, 1), (4, 1)');

        $modelTester = new CommandTester($this->createStatsCommand($db));
        $modelTester->execute(['--models' => true, '--top' => '1']);

        $dvdTester = new CommandTester($this->createStatsCommand($db));
        $dvdTester->execute(['--dvds' => true, '--top' => '1']);

        $this->assertSame(0, $modelTester->getStatusCode());
        $this->assertStringContainsString('100.0% (10)', $modelTester->getDisplay());
        $this->assertStringNotContainsString('8.0 (10)', $modelTester->getDisplay());
        $this->assertStringNotContainsString('160.0% (10)', $modelTester->getDisplay());

        $this->assertSame(0, $dvdTester->getStatusCode());
        $this->assertStringContainsString('100.0% (5)', $dvdTester->getDisplay());
        $this->assertStringNotContainsString('9.0 (5)', $dvdTester->getDisplay());
        $this->assertStringNotContainsString('180.0% (5)', $dvdTester->getDisplay());
    }

    public function testDvdStatsUseKvsAdminVideoRelationCounts(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_dvds (' .
            'dvd_id INTEGER, title TEXT, status_id INTEGER, total_videos INTEGER, dvd_viewed INTEGER, ' .
            'rating REAL, rating_amount INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_videos (video_id INTEGER, dvd_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_dvds VALUES " .
            "(1, 'Stored Counter Winner', 1, 50, 10, 45, 5), " .
            "(2, 'Relation Winner', 1, 1, 20, 45, 5)"
        );
        $db->exec('INSERT INTO ktvs_videos VALUES (1, 1), (2, 2), (3, 2), (4, 2), (5, 2), (6, 2), (7, 2)');

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--dvds' => true, '--top' => '1']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode(), $output);
        $this->assertStringContainsString('Total Videos in DVDs', $output);
        $this->assertStringContainsString('Relation Winner', $output);
        $this->assertStringNotContainsString('Stored Counter Winner', $output);
        $this->assertMatchesRegularExpression('/Total Videos in DVDs\W+7/', $output);
        $this->assertMatchesRegularExpression('/Relation Winner\W+6\W+20/', $output);
    }

    public function testUserStatsUseKvsAdminRelationCounts(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_users (' .
            'user_id INTEGER, username TEXT, status_id INTEGER, total_videos_count INTEGER, ' .
            'total_albums_count INTEGER, comments_total_count INTEGER, profile_viewed INTEGER, ' .
            'logins_count INTEGER, added_date TEXT)'
        );
        $db->exec('CREATE TABLE ktvs_videos (video_id INTEGER, user_id INTEGER, status_id INTEGER, video_viewed INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums (album_id INTEGER, user_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (comment_id INTEGER, user_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_users VALUES " .
            "(1, 'Stored Counter Winner', 2, 50, 0, 90, 5, 1, '2026-01-01 00:00:00'), " .
            "(2, 'Relation Winner', 2, 1, 0, 0, 10, 3, '2026-01-02 00:00:00')"
        );
        $db->exec(
            'INSERT INTO ktvs_videos VALUES ' .
            '(1, 2, 1, 100), (2, 2, 1, 200), (3, 2, 1, 300)'
        );
        $db->exec('INSERT INTO ktvs_albums VALUES (1, 2)');
        $db->exec('INSERT INTO ktvs_comments VALUES (1, 2), (2, 2)');

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--users' => true, '--top' => '1']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode(), $output);
        $this->assertStringContainsString('Relation Winner', $output);
        $this->assertStringNotContainsString('Stored Counter Winner', $output);
        $this->assertMatchesRegularExpression('/User Videos\W+3/', $output);
        $this->assertMatchesRegularExpression('/User Albums\W+1/', $output);
        $this->assertMatchesRegularExpression('/User Comments\W+2/', $output);
        $this->assertMatchesRegularExpression('/Relation Winner\W+3 videos\W+1 albums/', $output);
    }

    public function testVideoStatsPeriodFiltersByAddedDate(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, title TEXT, dir TEXT, status_id INTEGER, has_errors INTEGER, ' .
            'video_viewed INTEGER, video_viewed_unique INTEGER, rating REAL, rating_amount INTEGER, ' .
            'comments_count INTEGER, favourites_count INTEGER, duration INTEGER, added_date TEXT)'
        );
        $db->exec('CREATE TABLE ktvs_comments (comment_id INTEGER, object_id INTEGER, object_type_id INTEGER)');

        $today = date('Y-m-d H:i:s');
        $old = date('Y-m-d H:i:s', strtotime('-40 days'));
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(1, 'Today Video', 'today', 1, 0, 200, 20, 45, 5, 0, 0, 120, " . $db->quote($today) . "), " .
            "(2, 'Old Video', 'old', 1, 0, 100, 10, 80, 20, 0, 0, 60, " . $db->quote($old) . ")"
        );

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--videos' => true, '--period' => 'today', '--top' => '2']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Total Videos', $output);
        $this->assertStringContainsString('Today Video', $output);
        $this->assertStringNotContainsString('Old Video', $output);
        $this->assertStringContainsString('100.0% (5)', $output);
        $this->assertStringNotContainsString('80.0% (20)', $output);
        $this->assertStringNotContainsString('180.0% (5)', $output);
    }

    public function testOverviewRecentActivityUsesSelectedPeriod(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, title TEXT, dir TEXT, status_id INTEGER, video_viewed INTEGER, ' .
            'rating REAL, rating_amount INTEGER, duration INTEGER, added_date TEXT)'
        );
        $db->exec(
            'CREATE TABLE ktvs_albums (' .
            'album_id INTEGER, title TEXT, status_id INTEGER, album_viewed INTEGER, rating REAL, ' .
            'rating_amount INTEGER, photos_amount INTEGER, added_date TEXT)'
        );
        $db->exec('CREATE TABLE ktvs_users (status_id INTEGER, added_date TEXT)');
        $db->exec('CREATE TABLE ktvs_comments (added_date TEXT)');

        $today = date('Y-m-d H:i:s');
        $todayDate = date('Y-m-d');
        $old = date('Y-m-d H:i:s', strtotime('-5 days'));
        $oldDate = date('Y-m-d', strtotime('-5 days'));

        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(1, 'Today Video', 'today', 1, 200, 45, 5, 120, " . $db->quote($today) . "), " .
            "(2, 'Old Video', 'old', 1, 100, 80, 20, 60, " . $db->quote($old) . ")"
        );
        $db->exec(
            "INSERT INTO ktvs_albums VALUES " .
            "(1, 'Today Album', 1, 50, 40, 4, 8, " . $db->quote($today) . ")"
        );
        $db->exec("INSERT INTO ktvs_users VALUES (2, " . $db->quote($old) . ")");
        $db->exec("INSERT INTO ktvs_comments VALUES (" . $db->quote($old) . ")");

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--period' => 'today', '--top' => '2']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Recent Activity (today)', $output);
        $this->assertStringContainsString($todayDate, $output);
        $this->assertStringNotContainsString($oldDate, $output);
        $this->assertStringContainsString('Today Total', $output);
        $this->assertStringContainsString('+0 users', $output);
        $this->assertStringContainsString('+0 comments', $output);
    }

    public function testCategoryStatsOrderByKvsAdminTotalUsage(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_categories (' .
            'category_id INTEGER, title TEXT, status_id INTEGER, today_videos INTEGER, today_albums INTEGER, ' .
            'total_content_sources INTEGER, total_playlists INTEGER, total_models INTEGER, total_dvds INTEGER, ' .
            'total_dvd_groups INTEGER, added_date TEXT)'
        );
        foreach (['videos' => 'video_id', 'albums' => 'album_id', 'posts' => 'post_id'] as $suffix => $column) {
            $db->exec("CREATE TABLE ktvs_categories_{$suffix} (category_id INTEGER, {$column} INTEGER)");
        }
        $db->exec(
            "INSERT INTO ktvs_categories VALUES " .
            "(1, 'Video Category', 1, 0, 0, 0, 0, 0, 0, 0, '2026-01-01 00:00:00'), " .
            "(2, 'Post Category', 1, 0, 0, 0, 0, 0, 0, 0, '2026-01-01 00:00:00')"
        );
        $db->exec(
            'INSERT INTO ktvs_categories_videos VALUES ' .
            '(1, 1), (1, 2), (1, 3), (1, 4), (1, 5)'
        );
        $db->exec(
            'INSERT INTO ktvs_categories_posts VALUES ' .
            '(2, 1), (2, 2), (2, 3), (2, 4), (2, 5), (2, 6)'
        );

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--categories' => true, '--top' => '1']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode(), $output);
        $this->assertStringContainsString('Post Category', $output);
        $this->assertStringNotContainsString('Video Category', $output);
        $this->assertMatchesRegularExpression('/Post Category\W+0\W+\\+0\W+0\W+6\W+0\W+6/', $output);
    }

    public function testTagStatsOrderByKvsAdminTotalUsage(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_tags (' .
            'tag_id INTEGER, tag TEXT, status_id INTEGER, added_date TEXT, total_content_sources INTEGER, ' .
            'total_playlists INTEGER, total_models INTEGER, total_dvds INTEGER, total_dvd_groups INTEGER)'
        );
        foreach (['videos' => 'video_id', 'albums' => 'album_id', 'posts' => 'post_id'] as $suffix => $column) {
            $db->exec("CREATE TABLE ktvs_tags_{$suffix} (tag_id INTEGER, {$column} INTEGER)");
        }
        $db->exec(
            "INSERT INTO ktvs_tags VALUES " .
            "(1, 'audio', 1, '2026-01-01 00:00:00', 0, 0, 0, 0, 0), " .
            "(2, 'English', 1, '2026-01-01 00:00:00', 0, 0, 0, 0, 0)"
        );
        $db->exec(
            'INSERT INTO ktvs_tags_videos VALUES ' .
            '(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8), (1, 9), (1, 10), (1, 11), (1, 12), ' .
            '(2, 1), (2, 2), (2, 3), (2, 4), (2, 5), (2, 6)'
        );
        $db->exec(
            'INSERT INTO ktvs_tags_posts VALUES ' .
            '(2, 1), (2, 2), (2, 3), (2, 4), (2, 5), (2, 6), (2, 7), (2, 8), (2, 9), (2, 10), ' .
            '(2, 11), (2, 12), (2, 13), (2, 14), (2, 15), (2, 16), (2, 17), (2, 18), (2, 19)'
        );

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--tags' => true, '--top' => '1']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode(), $output);
        $this->assertStringContainsString('English', $output);
        $this->assertStringNotContainsString('audio', $output);
        $this->assertMatchesRegularExpression('/English\W+6\W+0\W+19\W+0\W+25/', $output);
    }

    public function testStatsRejectsInvalidPeriod(): void
    {
        $tester = new CommandTester($this->createStatsCommand($this->createSqliteConnection()));
        $tester->execute(['--period' => 'yesterday']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid period', $tester->getDisplay());
    }

    public function testStatsRejectsInvalidTopBeforeSql(): void
    {
        $tester = new CommandTester($this->createStatsCommand($this->createSqliteConnection()));
        $tester->execute(['--videos' => true, '--top' => '-1']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --top', $tester->getDisplay());
    }

    public function testStatsHelpDocumentsPeriodCreationDateSemantics(): void
    {
        $command = $this->createStatsCommand($this->createSqliteConnection());
        $periodOptionDescription = $command->getDefinition()->getOption('period')->getDescription();

        $this->assertSame(
            'Filter items by creation date: today, week, month, year, all',
            $periodOptionDescription
        );
        $this->assertNotSame(
            'Time period: today, week, month, year, all',
            $periodOptionDescription
        );
    }

    private function createSqliteConnection(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $db;
    }

    private function createStatsCommand(\PDO $db): StatsCommand
    {
        return new class ($this->createConfig(), $db) extends StatsCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:stats');
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
