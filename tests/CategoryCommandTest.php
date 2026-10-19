<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testCategoryListRejectsGroupAndParentTogether(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--group' => 'Genres',
            '--parent' => '2',
            '--format' => 'count',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString(
            'Options --group and --parent cannot be used together',
            $this->tester->getDisplay()
        );
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

    public function testCategoryShowSupportsRequestedAdminFields(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
            '--fields' => 'category_id,status_id',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('10', $rows[0]['category_id']);
        $this->assertSame(1, $rows[0]['status_id']);
    }

    public function testCategoryShowSupportsRequestedAdminListFields(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
            '--fields' => implode(',', [
                'category_id',
                'title',
                'dir',
                'description',
                'status_id',
                'category_group',
                'videos_amount',
                'albums_amount',
                'posts_amount',
                'other_amount',
                'all_amount',
                'added_date',
                'sort_id',
            ]),
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('10', $rows[0]['category_id']);
        $this->assertSame('Action', $rows[0]['title']);
        $this->assertSame('action-scenes', $rows[0]['dir']);
        $this->assertSame('High energy scenes', $rows[0]['description']);
        $this->assertSame(1, (int) $rows[0]['status_id']);
        $this->assertSame('Genres', $rows[0]['category_group']);
        $this->assertSame(2, (int) $rows[0]['videos_amount']);
        $this->assertSame(1, (int) $rows[0]['albums_amount']);
        $this->assertSame(2, (int) $rows[0]['posts_amount']);
        $this->assertSame(10, (int) $rows[0]['other_amount']);
        $this->assertSame(15, (int) $rows[0]['all_amount']);
        $this->assertSame('2026-05-25 10:00:00', $rows[0]['added_date']);
        $this->assertSame(5, (int) $rows[0]['sort_id']);
    }

    public function testCategoryShowRejectsCountFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
            '--format' => 'count',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('show action does not support --format=count', $this->tester->getDisplay());
    }

    public function testCategoryShowHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
            '--fields' => 'title',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Action', $output);
        $this->assertStringNotContainsString('Category: Action', $output);
        $this->assertStringNotContainsString('Videos', $output);
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

    public function testCategoryTreeHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'tree',
            '--fields' => 'title',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Action', $output);
        $this->assertStringNotContainsString('Category Tree', $output);
        $this->assertStringNotContainsString('videos)', $output);
    }

    public function testCategoryCommandMetadata(): void
    {
        $this->assertEquals('content:category', $this->command->getName());
        $this->assertStringContainsString('manage', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('category', $aliases);
    }

    #[DataProvider('categoryCleanupFailures')]
    public function testDeleteRejectsStorageFailuresBeforeDeletingCategory(string $failure): void
    {
        $root = vfsStream::setup('category-files');
        $categories = vfsStream::newDirectory('categories')->at($root);
        $directory = vfsStream::newDirectory('30')->at($categories);
        $image = null;
        if ($failure !== 'root directory') {
            if ($failure === 'child directory') {
                vfsStream::newDirectory('nested')->at($directory);
            }
            $image = vfsStream::newFile('image.png')->withContent('owned category image')->at($directory);
        }
        if ($failure === 'file' || $failure === 'child directory') {
            $directory->chmod(0555);
        } elseif ($failure === 'root directory') {
            $categories->chmod(0555);
        } else {
            $categories->chmod(0000);
        }
        $tester = $this->createCategoryCleanupTester($categories->url());

        $exitCode = $tester->execute(['action' => 'delete', 'id' => '30']);

        $output = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertSame(1, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString('Failed to delete category', $output);
        $this->assertStringContainsString($failure === 'ancestor' ? 'Cannot inspect' : 'Could not remove', $output);
        $this->assertStringNotContainsString('deleted successfully', $output);
        $this->assertSame(1, (int) $this->db->query(
            'SELECT COUNT(*) FROM ' . TestHelper::table('categories') . ' WHERE category_id = 30'
        )->fetchColumn());
        $this->assertSame(30, (int) $this->db->query(
            'SELECT favourite_category_id FROM ' . TestHelper::table('users') . ' WHERE user_id = 1'
        )->fetchColumn());
        $this->assertTrue($categories->hasChild('30'));
        if ($image !== null) {
            $this->assertSame('owned category image', $image->getContent());
            $this->assertTrue($directory->hasChild('image.png'));
        }
        if ($failure === 'child directory') {
            $this->assertTrue($directory->hasChild('nested'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function categoryCleanupFailures(): iterable
    {
        foreach (['file', 'child directory', 'root directory', 'ancestor'] as $failure) {
            yield $failure => [$failure];
        }
    }

    #[DataProvider('categoryCleanupSuccesses')]
    public function testDeleteRemovesOwnedFilesAndReferencesWhilePreservingOtherCategories(string $state): void
    {
        $root = vfsStream::setup('category-files');
        $categories = vfsStream::newDirectory('categories')->at($root);
        $sentinel = vfsStream::newDirectory('20')->at($categories);
        vfsStream::newFile('sentinel.bin')->withContent('preserve other category')->at($sentinel);
        if ($state !== 'absent') {
            $directory = vfsStream::newDirectory('30')->at($categories);
            if ($state === 'files') {
                $nested = vfsStream::newDirectory('nested')->at($directory);
                vfsStream::newFile('image.png')->withContent('owned category image')->at($nested);
            }
        }
        $tester = $this->createCategoryCleanupTester($categories->url());

        $exitCode = $tester->execute(['action' => 'delete', 'id' => '30']);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertFalse($categories->hasChild('30'));
        $this->assertSame('preserve other category', file_get_contents($sentinel->url() . '/sentinel.bin'));
        $this->assertSame(0, (int) $this->db->query(
            'SELECT COUNT(*) FROM ' . TestHelper::table('categories') . ' WHERE category_id = 30'
        )->fetchColumn());
        $this->assertSame(1, (int) $this->db->query(
            'SELECT COUNT(*) FROM ' . TestHelper::table('categories') . ' WHERE category_id = 20'
        )->fetchColumn());
        $this->assertSame([0, 20], array_map('intval', $this->db->query(
            'SELECT favourite_category_id FROM ' . TestHelper::table('users') . ' ORDER BY user_id'
        )->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([0, 20], array_map('intval', $this->db->query(
            'SELECT category_id FROM ' . TestHelper::table('stats_referers_list') . ' ORDER BY referer_id'
        )->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return iterable<string, array{string}> */
    public static function categoryCleanupSuccesses(): iterable
    {
        foreach (['absent', 'empty', 'files'] as $state) {
            yield $state => [$state];
        }
    }

    public function testDeleteStillReportsDatabaseFailureAfterFilesWereRemoved(): void
    {
        $root = vfsStream::setup('category-files');
        $categories = vfsStream::newDirectory('categories')->at($root);
        $directory = vfsStream::newDirectory('30')->at($categories);
        vfsStream::newFile('image.png')->withContent('owned category image')->at($directory);
        $tester = $this->createCategoryCleanupTester($categories->url());
        $this->db->exec('CREATE TRIGGER category_delete_refusal BEFORE DELETE ON '
            . TestHelper::table('categories') . " WHEN OLD.category_id = 30 BEGIN "
            . "SELECT RAISE(ABORT, 'synthetic category delete refusal'); END");

        $exitCode = $tester->execute(['action' => 'delete', 'id' => '30']);

        $this->assertSame(1, $exitCode, $tester->getDisplay());
        $output = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertStringContainsString('synthetic category delete refusal', $output);
        $this->assertStringNotContainsString('deleted successfully', $output);
        $this->assertFalse($categories->hasChild('30'));
        $this->assertSame(1, (int) $this->db->query(
            'SELECT COUNT(*) FROM ' . TestHelper::table('categories') . ' WHERE category_id = 30'
        )->fetchColumn());
        $this->assertSame(30, (int) $this->db->query(
            'SELECT favourite_category_id FROM ' . TestHelper::table('users') . ' WHERE user_id = 1'
        )->fetchColumn());
    }

    #[DataProvider('categoryStorageLinks')]
    public function testDeleteRemovesStorageLinksWhilePreservingTheirTargets(string $targetType, bool $nested): void
    {
        $categories = $this->kvsPath . '/contents/categories';
        $directory = $categories . '/30';
        $target = $this->kvsPath . '/linked-storage';
        $content = 'Unselected storage must remain unchanged';
        if ($targetType === 'directory') {
            mkdir($target);
            file_put_contents($target . '/sentinel.bin', $content);
        } elseif ($targetType === 'file') {
            file_put_contents($target, $content);
        }
        if ($nested) {
            mkdir($directory);
        }
        $link = $nested ? $directory . '/linked-item' : $directory;
        symlink($target, $link);
        $tester = $this->createCategoryCleanupTester($categories);

        $exitCode = $tester->execute(['action' => 'delete', 'id' => '30']);

        if ($targetType !== 'missing') {
            $sentinel = $targetType === 'directory' ? $target . '/sentinel.bin' : $target;
            $this->assertFileExists($sentinel);
            $this->assertSame($content, file_get_contents($sentinel));
        } else {
            $this->assertFileDoesNotExist($target);
        }
        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertFalse(is_link($link));
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame(0, (int) $this->db->query(
            'SELECT COUNT(*) FROM ' . TestHelper::table('categories') . ' WHERE category_id = 30'
        )->fetchColumn());
        $this->assertSame(1, (int) $this->db->query(
            'SELECT COUNT(*) FROM ' . TestHelper::table('categories') . ' WHERE category_id = 20'
        )->fetchColumn());
        $this->assertSame([0, 20], array_map('intval', $this->db->query(
            'SELECT favourite_category_id FROM ' . TestHelper::table('users') . ' ORDER BY user_id'
        )->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function categoryStorageLinks(): iterable
    {
        foreach (['directory', 'file', 'missing'] as $type) {
            yield 'category link to ' . $type => [$type, false];
            yield 'nested link to ' . $type => [$type, true];
        }
    }

    private function createCategoryCleanupTester(string $categoriesPath): CommandTester
    {
        $this->config = $this->getMockBuilder(Configuration::class)
            ->setConstructorArgs([['path' => $this->kvsPath, 'disable_db_env_overrides' => true]])
            ->onlyMethods(['getCategoriesPath'])
            ->getMock();
        $this->config->method('getCategoriesPath')->willReturn($categoriesPath);
        $this->db->exec('CREATE TABLE ' . TestHelper::table('users')
            . ' (user_id INTEGER, favourite_category_id INTEGER)');
        $this->db->exec('INSERT INTO ' . TestHelper::table('users') . ' VALUES (1, 30), (2, 20)');
        $this->db->exec('CREATE TABLE ' . TestHelper::table('stats_referers_list')
            . ' (referer_id INTEGER, category_id INTEGER)');
        $this->db->exec('INSERT INTO ' . TestHelper::table('stats_referers_list') . ' VALUES (1, 30), (2, 20)');

        $command = $this->createCommand($this->db);
        (new Application())->add($command);
        return new CommandTester($command);
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
            'status_id INTEGER, screenshot1 TEXT, screenshot2 TEXT, added_date TEXT, sort_id INTEGER, ' .
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
            "screenshot2, added_date, sort_id, " .
            "total_content_sources, total_playlists, total_models, total_dvds, total_dvd_groups) VALUES " .
            "(10, 'Action', 'action-scenes', 'High energy scenes', 'stunts', 2, 1, 'action-1.jpg', " .
            "'action-2.jpg', '2026-05-25 10:00:00', 5, 1, 2, 3, 4, 0), " .
            "(20, 'Drama', 'drama', '', '', 0, 0, '', '', '2026-05-26 10:00:00', 6, 0, 0, 0, 0, 0), " .
            "(30, 'Unused Category', 'unused-category', '', '', 0, 1, '', '', '2026-05-26 11:00:00', 7, 0, 0, 0, 0, 0)"
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
