<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\VideoCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class VideoCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private VideoCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation([
            'project_url' => 'https://example.test',
            'content_url_videos_screenshots' => 'https://cdn.example.test/videos_screenshots',
            'content_url_videos_screenshots_admin_panel' => 'https://admin-cdn.example.test/videos_screenshots',
        ]);
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

    public function testVideoListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 2
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Video id', $output);
        $this->assertStringContainsString('Older Active Clip', $output);
        $this->assertStringContainsString('Disabled Clip', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testVideoListWithStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 1,
            '--format' => 'json',
            '--fields' => 'video_id,title,status,views,username,duration,filesize,rating',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(2, $rows);
        $this->assertSame([30, 10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        $rowsById = array_column($rows, null, 'video_id');
        $this->assertSame('Featured Clip', $rowsById[10]['title']);
        $this->assertSame('Active', $rowsById[10]['status']);
        $this->assertSame(123, (int) $rowsById[10]['views']);
        $this->assertSame('alice', $rowsById[10]['username']);
        $this->assertSame('2:05', $rowsById[10]['duration']);
        $this->assertSame('1.00 MB', $rowsById[10]['filesize']);
        $this->assertSame('4.0/5 (10 votes)', $rowsById[10]['rating']);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testVideoListFormats(): void
    {
        // Test JSON format
        $testerJson = new CommandTester($this->command);
        $testerJson->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json'
        ]);

        $output = $testerJson->getDisplay();
        $this->assertJson($output);
        $jsonRows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $jsonRows);
        $this->assertSame(30, (int) $jsonRows[0]['video_id']);
        $this->assertSame('Older Active Clip', $jsonRows[0]['title']);
        $this->assertEquals(0, $testerJson->getStatusCode());

        // Test CSV format
        $testerCsv = new CommandTester($this->command);
        ob_start();
        $testerCsv->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'csv'
        ]);
        $csvOutput = ob_get_clean();

        $this->assertStringContainsString('video_id', $csvOutput);
        $this->assertStringContainsString('Older Active Clip', $csvOutput);
        $this->assertEquals(0, $testerCsv->getStatusCode());

        // Test count format
        $testerCount = new CommandTester($this->command);
        $testerCount->execute([
            'action' => 'list',
            '--format' => 'count'
        ]);

        $output = trim($testerCount->getDisplay());
        $this->assertSame('3', $output);
        $this->assertEquals(0, $testerCount->getStatusCode());
    }

    public function testVideoShow(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Video #10', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Featured Clip', $output);
        $this->assertStringContainsString('Featured description', $output);
        $this->assertStringContainsString('Action', $output);
        $this->assertStringContainsString('tag-two, tag-one', $output);
        $this->assertMatchesRegularExpression('/Duration\W+2:05/', $output);
        $this->assertMatchesRegularExpression('/Views\W+123/', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testVideoShowSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('10', $rows[0]['video_id']);
        $this->assertSame('Featured Clip', $rows[0]['title']);
        $this->assertSame('Featured description', $rows[0]['description']);
        $this->assertSame(['Drama', 'Action'], $rows[0]['categories']);
        $this->assertSame(['tag-two', 'tag-one'], $rows[0]['tags']);
        $this->assertStringNotContainsString('Video #10', $output);
    }

    public function testVideoListSeparatesKvsAccessTypeAndAccessLevel(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'video_id,is_private,type,access_level_id,access',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(30, (int) $rows[0]['video_id']);
        $this->assertSame('Private', $rows[0]['is_private']);
        $this->assertSame('Private', $rows[0]['type']);
        $this->assertSame(0, (int) $rows[0]['access_level_id']);
        $this->assertSame('From access type', $rows[0]['access']);
    }

    public function testVideoShowSeparatesKvsAccessTypeAndAccessLevel(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('Private', $rows[0]['type']);
        $this->assertSame('From access type', $rows[0]['access']);
    }

    public function testVideoShowRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Video ID', $this->tester->getDisplay());
    }

    public function testVideoListFormatsKvsResolutionTypesAboveFhd(): void
    {
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('videos') .
            " (video_id, user_id, title, status_id, resolution_type, is_private, duration, file_size, " .
            "file_dimensions, post_date, rating, rating_amount, video_viewed, favourites_count, r_ctr, description) VALUES " .
            "(40, 1, '4K Clip', 1, 4, 0, 60, 1048576, '3840x2160', '2026-05-27 10:00:00', 0, 0, 0, 0, 0, ''), " .
            "(50, 1, '5K Clip', 1, 5, 0, 60, 1048576, '5120x2880', '2026-05-27 09:00:00', 0, 0, 0, 0, 0, ''), " .
            "(60, 1, '6K Clip', 1, 6, 0, 60, 1048576, '6144x3456', '2026-05-27 08:00:00', 0, 0, 0, 0, 0, ''), " .
            "(80, 1, '8K Clip', 1, 8, 0, 60, 1048576, '7680x4320', '2026-05-27 07:00:00', 0, 0, 0, 0, 0, '')"
        );

        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'video_id,resolution',
            '--limit' => 10,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $resolutionById = array_column($rows, 'resolution', 'video_id');

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('4K', $resolutionById[40] ?? null);
        $this->assertSame('5K', $resolutionById[50] ?? null);
        $this->assertSame('6K', $resolutionById[60] ?? null);
        $this->assertSame('8K', $resolutionById[80] ?? null);
    }

    public function testVideoListExposesKvsAdminCalculatedFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Featured Clip',
            '--fields' => 'video_id,title,r_ctr,comments_count',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(10, (int) $rows[0]['video_id']);
        $this->assertSame('Featured Clip', $rows[0]['title']);
        $this->assertSame(12.5, (float) $rows[0]['r_ctr']);
        $this->assertSame(2, (int) $rows[0]['comments_count']);
    }

    public function testVideoListExposesKvsAdminRelationFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Featured Clip',
            '--fields' => 'video_id,title,thumb,content_source,dvd,admin_flag,server_group,format_video_group,tags,categories,models,ip',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(10, (int) $rows[0]['video_id']);
        $this->assertSame('Featured Clip', $rows[0]['title']);
        $this->assertSame('https://admin-cdn.example.test/videos_screenshots/0/10/320x180/3.jpg', $rows[0]['thumb']);
        $this->assertSame('Studio One', $rows[0]['content_source']);
        $this->assertSame('Series One', $rows[0]['dvd']);
        $this->assertSame('Needs Review', $rows[0]['admin_flag']);
        $this->assertSame('Primary Storage', $rows[0]['server_group']);
        $this->assertSame('HD Formats', $rows[0]['format_video_group']);
        $this->assertSame('tag-two,tag-one', $rows[0]['tags']);
        $this->assertSame('Drama,Action', $rows[0]['categories']);
        $this->assertSame('Model Two,Model One', $rows[0]['models']);
        $this->assertSame('127.0.0.1', $rows[0]['ip']);
    }

    public function testVideoListExposesKvsAdminUserStatusAndAdminUserFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Featured Clip',
            '--fields' => 'video_id,title,user,user_status_id,admin_user,admin_user_is_superadmin',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(10, (int) $rows[0]['video_id']);
        $this->assertSame('Featured Clip', $rows[0]['title']);
        $this->assertSame('alice', $rows[0]['user']);
        $this->assertSame(1, (int) $rows[0]['user_status_id']);
        $this->assertSame('moderator', $rows[0]['admin_user']);
        $this->assertSame(0, (int) $rows[0]['admin_user_is_superadmin']);
    }

    public function testVideoListAcceptsKvsLifecycleStatusAliases(): void
    {
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('videos') .
            ' (video_id, user_id, admin_user_id, title, status_id, resolution_type, is_private, duration, file_size, ' .
            'file_dimensions, post_date, rating, rating_amount, video_viewed, favourites_count, r_ctr, description, ' .
            'content_source_id, dvd_id, admin_flag_id, server_group_id, format_video_group_id, screen_main, ip) VALUES ' .
            "(40, 1, 0, 'Processing Clip', 3, 0, 0, 10, 0, '', '2026-05-27 10:00:00', 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0), " .
            "(50, 1, 0, 'Deleting Clip', 4, 0, 0, 10, 0, '', '2026-05-27 11:00:00', 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0), " .
            "(60, 1, 0, 'Deleted Clip', 5, 0, 0, 10, 0, '', '2026-05-27 12:00:00', 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0)"
        );

        $cases = [
            'in_process' => [40, 'In process'],
            'deleting' => [50, 'Deleting'],
            'deleted' => [60, 'Deleted'],
        ];

        foreach ($cases as $status => [$expectedId, $expectedLabel]) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--status' => $status,
                '--fields' => 'video_id,status',
                '--format' => 'json',
                '--limit' => 1,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($expectedId, (int) $rows[0]['video_id']);
            $this->assertSame($expectedLabel, $rows[0]['status']);
        }
    }

    public function testVideoStatsSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'stats',
            '--format' => 'json',
            '--fields' => 'section,metric,value,label',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsByMetric = array_column($rows, null, 'metric');

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('overall', $rowsByMetric['Total Videos']['section'] ?? null);
        $this->assertSame(3, (int) ($rowsByMetric['Total Videos']['value'] ?? 0));
        $this->assertStringNotContainsString('Top 10 Most Viewed Videos', $this->tester->getDisplay());
    }

    public function testVideoCommandMetadata(): void
    {
        $this->assertEquals('content:video', $this->command->getName());
        $this->assertStringContainsString('manage', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('video', $aliases);
    }

    public function testVideoUpdateIsNotAdvertisedAsSupportedAction(): void
    {
        $this->tester->execute([
            'action' => 'update',
            'id' => '10',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown video action "update"', $output);
        $this->assertMatchesRegularExpression('/Available actions: list, show, delete,\s+stats/', $output);
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('videos') . ' (' .
            'video_id INTEGER, user_id INTEGER, admin_user_id INTEGER, title TEXT, status_id INTEGER, resolution_type INTEGER, ' .
            'is_private INTEGER, access_level_id INTEGER, duration INTEGER, file_size INTEGER, file_dimensions TEXT, post_date TEXT, ' .
            'rating INTEGER, rating_amount INTEGER, video_viewed INTEGER, favourites_count INTEGER, r_ctr REAL, ' .
            'description TEXT, content_source_id INTEGER, dvd_id INTEGER, admin_flag_id INTEGER, ' .
            'server_group_id INTEGER, format_video_group_id INTEGER, screen_main INTEGER, ip INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('users') . ' (user_id INTEGER, username TEXT, status_id INTEGER)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_users') . ' (' .
            'user_id INTEGER, login TEXT, is_superadmin INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('content_sources') . ' (' .
            'content_source_id INTEGER, title TEXT, status_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('dvds') . ' (' .
            'dvd_id INTEGER, title TEXT, status_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('flags') . ' (' .
            'flag_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_servers_groups') . ' (' .
            'group_id INTEGER, title TEXT, status_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('formats_videos_groups') . ' (' .
            'format_video_group_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('comments') .
            ' (comment_id INTEGER, object_type_id INTEGER, object_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('formats_screenshots') .
            ' (format_screenshot_id INTEGER, size TEXT, status_id INTEGER, group_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('categories') . ' (category_id INTEGER, title TEXT)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_videos') .
            ' (id INTEGER, category_id INTEGER, video_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('tags') . ' (tag_id INTEGER, tag TEXT)');
        $db->exec('CREATE TABLE ' . TestHelper::table('tags_videos') . ' (id INTEGER, tag_id INTEGER, video_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('models') . ' (model_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ' . TestHelper::table('models_videos') . ' (id INTEGER, model_id INTEGER, video_id INTEGER)');

        $db->exec("INSERT INTO " . TestHelper::table('users') . " VALUES (1, 'alice', 1), (2, 'bob', 0)");
        $db->exec("INSERT INTO " . TestHelper::table('admin_users') . " VALUES (8, 'moderator', 0), (9, 'admin', 1)");
        $db->exec(
            "INSERT INTO " . TestHelper::table('videos') .
            " (video_id, user_id, admin_user_id, title, status_id, resolution_type, is_private, access_level_id, duration, file_size, " .
            "file_dimensions, post_date, rating, rating_amount, video_viewed, favourites_count, r_ctr, description, " .
            "content_source_id, dvd_id, admin_flag_id, server_group_id, format_video_group_id, screen_main, ip) VALUES " .
            "(10, 1, 8, 'Featured Clip', 1, 2, 0, 0, 125, 1048576, '1920x1080', " .
            "'2026-05-26 10:00:00', 40, 10, 123, 7, 0.125, 'Featured description', 3, 4, 5, 6, 7, 3, 2130706433), " .
            "(20, 2, 9, 'Disabled Clip', 0, 0, 2, 2, 61, 524288, '640x360', '2026-05-25 10:00:00', 0, 0, 5, 0, 0.050, '', 0, 0, 0, 0, 0, 0, 0), " .
            "(30, 1, 0, 'Older Active Clip', 1, 1, 1, 0, 3600, 2097152, '1280x720', '2026-05-24 10:00:00', 15, 5, 20, 1, 0, '', 0, 0, 0, 0, 0, 0, 0)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('content_sources') .
            " VALUES (3, 'Studio One', 1)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('dvds') .
            " VALUES (4, 'Series One', 1)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('flags') .
            " VALUES (5, 'Needs Review')"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('admin_servers_groups') .
            " VALUES (6, 'Primary Storage', 1)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('formats_videos_groups') .
            " VALUES (7, 'HD Formats')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('comments') .
            ' (comment_id, object_type_id, object_id) VALUES ' .
            '(1, 1, 10), (2, 1, 10), (3, 2, 10), (4, 1, 20)'
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('formats_screenshots') .
            " VALUES (1, 'source', 1, 1), (2, '640x360', 1, 1), (3, '320x180', 1, 1)"
        );
        $db->exec("INSERT INTO " . TestHelper::table('categories') . " VALUES (1, 'Action'), (2, 'Drama')");
        $db->exec("INSERT INTO " . TestHelper::table('categories_videos') . " VALUES (1, 2, 10), (2, 1, 10), (3, 2, 20)");
        $db->exec("INSERT INTO " . TestHelper::table('tags') . " VALUES (1, 'tag-one'), (2, 'tag-two')");
        $db->exec("INSERT INTO " . TestHelper::table('tags_videos') . " VALUES (1, 2, 10), (2, 1, 10)");
        $db->exec("INSERT INTO " . TestHelper::table('models') . " VALUES (1, 'Model One'), (2, 'Model Two')");
        $db->exec("INSERT INTO " . TestHelper::table('models_videos') . " VALUES (1, 2, 10), (2, 1, 10)");

        return $db;
    }

    private function createCommand(PDO $db): VideoCommand
    {
        return new class ($this->config, $db) extends VideoCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:video');
                $this->setDescription('Manage KVS videos');
                $this->setAliases(['video', 'videos']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
