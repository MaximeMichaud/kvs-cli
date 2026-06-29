<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class CategoryCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private CategoryCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
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

    public function testCategoryListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 2
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Category id', $output);
        $this->assertStringContainsString('Unused Category', $output);
        $this->assertStringContainsString('Drama', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCategoryListWithStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 1,
            '--format' => 'json',
            '--fields' => 'category_id,title,video_count,album_count,total_usage,status',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(2, $rows);
        $this->assertSame(['Unused Category', 'Action'], array_column($rows, 'title'));
        $this->assertSame('Active', $rows[1]['status']);
        $this->assertSame(2, (int) $rows[1]['video_count']);
        $this->assertSame(1, (int) $rows[1]['album_count']);
        $this->assertSame(15, (int) $rows[1]['total_usage']);
        $this->assertSame(0, (int) $rows[0]['total_usage']);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCategoryListExposesKvsAdminCountFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Action',
            '--format' => 'json',
            '--fields' => 'category_id,title,videos_amount,albums_amount,posts_amount,other_amount,all_amount',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(10, (int) $rows[0]['category_id']);
        $this->assertSame('Action', $rows[0]['title']);
        $this->assertSame(2, (int) $rows[0]['videos_amount']);
        $this->assertSame(1, (int) $rows[0]['albums_amount']);
        $this->assertSame(2, (int) $rows[0]['posts_amount']);
        $this->assertSame(10, (int) $rows[0]['other_amount']);
        $this->assertSame(15, (int) $rows[0]['all_amount']);
    }

    public function testCategoryListExposesKvsAdminGroupField(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Action',
            '--format' => 'json',
            '--fields' => 'category_id,title,category_group,category_group_id',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(10, (int) $rows[0]['category_id']);
        $this->assertSame('Action', $rows[0]['title']);
        $this->assertSame('Genres', $rows[0]['category_group']);
        $this->assertSame(2, (int) $rows[0]['category_group_id']);
    }

    public function testCategoryListSearchesDirectoryLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'action-scenes',
            '--format' => 'json',
            '--fields' => 'category_id,title,dir',
            '--limit' => 5,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([10], array_map(static fn (array $row): int => (int) $row['category_id'], $rows));
        $this->assertSame('Action', $rows[0]['title']);
        $this->assertSame('action-scenes', $rows[0]['dir']);
    }

    public function testCategoryListSearchesIdLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '20',
            '--format' => 'json',
            '--fields' => 'category_id,title',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20], array_map(static fn (array $row): int => (int) $row['category_id'], $rows));
        $this->assertSame('Drama', $rows[0]['title']);
    }

    public function testCategoryListFiltersByGroupTitleLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--group' => 'Genres',
            '--format' => 'json',
            '--fields' => 'category_id,title,category_group_id',
            '--limit' => 5,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['category_id']);
        $this->assertSame('Action', $rows[0]['title']);
        $this->assertSame(2, (int) $rows[0]['category_group_id']);
    }

    public function testCategoryListMissingGroupTitleMatchesKvsAdminEmptyResult(): void
    {
        foreach (['--group', '--parent'] as $option) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                $option => '__missing_group__',
                '--format' => 'count',
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame('0', trim($tester->getDisplay()), $option);
        }
    }

    public function testCategoryListHonorsDeprecatedParentAliasForGroupFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--parent' => '2',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('1', trim($this->tester->getDisplay()));
    }

    public function testCategoryListFiltersByKvsAdminFieldFilter(): void
    {
        $cases = [
            'filled/description' => [10],
            'empty/description' => [30, 20],
            'filled/screenshot1' => [10],
            'empty/screenshot1' => [30, 20],
            'filled/group' => [10],
            'empty/group' => [30, 20],
        ];

        foreach ($cases as $filter => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--field-filter' => $filter,
                '--format' => 'json',
                '--fields' => 'category_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['category_id'], $rows), $filter);
        }
    }

    public function testCategoryListFiltersByKvsAdminUsageBuckets(): void
    {
        $cases = [
            'used/videos' => [20, 10],
            'notused/videos' => [30],
            'used/albums' => [10],
            'notused/albums' => [30, 20],
            'used/posts' => [10],
            'notused/posts' => [30, 20],
            'used/other' => [10],
            'notused/other' => [30, 20],
            'used/all' => [20, 10],
            'notused/all' => [30],
        ];

        foreach ($cases as $usage => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--usage' => $usage,
                '--format' => 'json',
                '--fields' => 'category_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['category_id'], $rows), $usage);
        }
    }

    public function testCategoryListExposesKvsAdminThumbField(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Action',
            '--format' => 'json',
            '--fields' => 'category_id,thumb,screenshot1,screenshot2',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(10, (int) $rows[0]['category_id']);
        $this->assertSame('action-1.jpg', $rows[0]['thumb']);
        $this->assertSame('action-1.jpg', $rows[0]['screenshot1']);
        $this->assertSame('action-2.jpg', $rows[0]['screenshot2']);
    }

    public function testCategoryListFormats(): void
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
        $this->assertSame(30, (int) $jsonRows[0]['category_id']);
        $this->assertSame('Unused Category', $jsonRows[0]['title']);
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

        $this->assertStringContainsString('category_id', $csvOutput);
        $this->assertStringContainsString('Unused Category', $csvOutput);
        $this->assertEquals(0, $testerCsv->getStatusCode());

        // Test count format
        $testerCount = new CommandTester($this->command);
        $testerCount->execute([
            'action' => 'list',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $output = trim($testerCount->getDisplay());
        $this->assertSame('3', $output);
        $this->assertEquals(0, $testerCount->getStatusCode());

        $testerActiveCount = new CommandTester($this->command);
        $testerActiveCount->execute([
            'action' => 'list',
            '--status' => 'active',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $this->assertSame('2', trim($testerActiveCount->getDisplay()));
        $this->assertEquals(0, $testerActiveCount->getStatusCode());
    }

    public function testCategoryShow(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Category: Action', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Action', $output);
        $this->assertMatchesRegularExpression('/Videos\W+2/', $output);
        $this->assertMatchesRegularExpression('/Albums\W+1/', $output);
        $this->assertStringContainsString('High energy scenes', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCategoryShowSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('10', $rows[0]['id']);
        $this->assertSame('Action', $rows[0]['title']);
        $this->assertSame('High energy scenes', $rows[0]['description']);
        $this->assertStringNotContainsString('Category: Action', $output);
    }

    public function testCategoryShowSupportsExactTitle(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => 'Action',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('10', $rows[0]['id']);
        $this->assertSame('Action', $rows[0]['title']);
    }

    public function testCategoryShowReportsMissingTextIdentifier(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Category not found: 10abc', $this->tester->getDisplay());
    }

    public function testCategoryUpdateRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            'action' => 'update',
            'id' => '10abc',
            '--title' => 'Renamed',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Category ID', $this->tester->getDisplay());
    }

    public function testCategoryTreeSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'tree',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(3, $rows);
        $this->assertContains('Action', array_column($rows, 'title'));
        $this->assertArrayHasKey('video_count', $rows[0]);
        $this->assertStringNotContainsString('Category Tree', $output);
    }

    public function testCategoryCommandMetadata(): void
    {
        $this->assertEquals('content:category', $this->command->getName());
        $this->assertStringContainsString('manage', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('category', $aliases);
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories') . ' (' .
            'category_id INTEGER, title TEXT, dir TEXT, description TEXT, synonyms TEXT, category_group_id INTEGER, ' .
            'status_id INTEGER, screenshot1 TEXT, screenshot2 TEXT, added_date TEXT, ' .
            'total_content_sources INTEGER, total_playlists INTEGER, ' .
            'total_models INTEGER, total_dvds INTEGER, total_dvd_groups INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_groups') . ' (' .
            'category_group_id INTEGER, title TEXT)'
        );

        foreach ($this->relationTables() as $suffix => $objectColumn) {
            $db->exec(
                'CREATE TABLE ' . TestHelper::table('categories_' . $suffix) . ' (' .
                'category_id INTEGER, ' . $objectColumn . ' INTEGER)'
            );
        }

        $db->exec(
            "INSERT INTO " . TestHelper::table('categories') .
            " (category_id, title, dir, description, synonyms, category_group_id, status_id, screenshot1, " .
            "screenshot2, added_date, " .
            "total_content_sources, total_playlists, total_models, total_dvds, total_dvd_groups) VALUES " .
            "(10, 'Action', 'action-scenes', 'High energy scenes', 'stunts', 2, 1, 'action-1.jpg', " .
            "'action-2.jpg', '2026-05-25 10:00:00', 1, 2, 3, 4, 0), " .
            "(20, 'Drama', 'drama', '', '', 0, 0, '', '', '2026-05-26 10:00:00', 0, 0, 0, 0, 0), " .
            "(30, 'Unused Category', 'unused-category', '', '', 0, 1, '', '', '2026-05-26 11:00:00', 0, 0, 0, 0, 0)"
        );
        $db->exec("INSERT INTO " . TestHelper::table('categories_groups') . " VALUES (2, 'Genres')");
        $db->exec("INSERT INTO " . TestHelper::table('categories_videos') . " VALUES (10, 100), (10, 101), (20, 200)");
        $db->exec("INSERT INTO " . TestHelper::table('categories_albums') . " VALUES (10, 300)");
        $db->exec("INSERT INTO " . TestHelper::table('categories_posts') . " VALUES (10, 400), (10, 401)");

        return $db;
    }

    /**
     * @return array<string, string>
     */
    private function relationTables(): array
    {
        return [
            'videos' => 'video_id',
            'content_sources' => 'content_source_id',
            'albums' => 'album_id',
            'posts' => 'post_id',
            'playlists' => 'playlist_id',
            'dvds' => 'dvd_id',
            'dvds_groups' => 'dvd_group_id',
            'models' => 'model_id',
        ];
    }

    private function createCommand(PDO $db): CategoryCommand
    {
        return new class ($this->config, $db) extends CategoryCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:category');
                $this->setDescription('Manage KVS categories');
                $this->setAliases(['category', 'categories', 'cat']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
