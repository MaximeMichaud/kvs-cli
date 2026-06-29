<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\AlbumCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class AlbumCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private AlbumCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation([
            'project_url' => 'https://example.test',
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

    public function testAlbumListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 2
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Album id', $output);
        $this->assertStringContainsString('Active Album', $output);
        $this->assertStringContainsString('Disabled Album', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAlbumListWithStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 1,
            '--format' => 'json',
            '--fields' => 'album_id,title,status',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['album_id']);
        $this->assertSame('Active Album', $rows[0]['title']);
        $this->assertSame('Active', $rows[0]['status']);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAlbumListFiltersUserByUsernameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--user' => 'bob',
            '--format' => 'json',
            '--fields' => 'album_id,user',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(20, (int) $rows[0]['album_id']);
        $this->assertSame('bob', $rows[0]['user']);
    }

    public function testAlbumListFiltersCategoryByTitleLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--category' => 'Album Category',
            '--format' => 'json',
            '--fields' => 'album_id,categories',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        $this->assertSame('Second Album Category,Album Category', $rows[0]['categories']);
    }

    public function testAlbumListFiltersTagByNameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--tag' => 'zeta-album',
            '--format' => 'json',
            '--fields' => 'album_id,tags',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        $this->assertSame('zeta-album,album-tag', $rows[0]['tags']);
    }

    public function testAlbumListFiltersModelByTitleLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--model' => 'Album Model Two',
            '--format' => 'json',
            '--fields' => 'album_id,models',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        $this->assertSame('Album Model Two,Album Model', $rows[0]['models']);
    }

    public function testAlbumListFiltersContentSourceByTitleLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--content-source' => 'Gallery Studio',
            '--format' => 'json',
            '--fields' => 'album_id,content_source',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        $this->assertSame('Gallery Studio', $rows[0]['content_source']);
    }

    public function testAlbumListFiltersRelationGroupsLikeKvsAdmin(): void
    {
        $cases = [
            ['options' => ['--category-group' => 'Album Category Group'], 'expected' => [20]],
            ['options' => ['--category-group' => 'Missing Group'], 'expected' => []],
            ['options' => ['--model-group' => 'Album Model Group'], 'expected' => [20]],
            ['options' => ['--model-group' => 'Missing Group'], 'expected' => []],
            ['options' => ['--content-source-group' => 'Gallery Group'], 'expected' => [20]],
            ['options' => ['--content-source-group' => 'Missing Group'], 'expected' => []],
        ];

        foreach ($cases as $case) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case['options'],
                '--format' => 'json',
                '--fields' => 'album_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($case['expected'], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        }
    }

    public function testAlbumListFiltersByKvsAdminAdminIpAndStorageGroup(): void
    {
        $cases = [
            ['options' => ['--admin-user' => 'moderator'], 'expected' => [20]],
            ['options' => ['--admin-user' => '9'], 'expected' => [10]],
            ['options' => ['--admin-user' => 'missing-admin'], 'expected' => []],
            ['options' => ['--ip' => '127.0.0.1'], 'expected' => [20]],
            ['options' => ['--ip' => '0'], 'expected' => [10, 5]],
            ['options' => ['--server-group' => 'Album Storage'], 'expected' => [20]],
        ];

        foreach ($cases as $case) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case['options'],
                '--format' => 'json',
                '--fields' => 'album_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($case['expected'], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        }
    }

    public function testAlbumListFiltersByKvsAdminFlagVotes(): void
    {
        $cases = [
            ['options' => ['--flag' => '4'], 'expected' => [20]],
            ['options' => ['--flag' => '6'], 'expected' => [10, 5]],
            ['options' => ['--flag' => '6', '--flag-votes' => '2'], 'expected' => [10]],
            ['options' => ['--flag' => '999'], 'expected' => []],
        ];

        foreach ($cases as $case) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case['options'],
                '--format' => 'json',
                '--fields' => 'album_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($case['expected'], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        }
    }

    public function testAlbumListRejectsFlagVotesWithoutFlag(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--flag-votes' => '2',
            '--format' => 'json',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Option --flag-votes requires --flag', $this->tester->getDisplay());
    }

    public function testAlbumListRejectsInvalidIpFilter(): void
    {
        foreach (['999.999.999.999', '999999999999'] as $value) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--ip' => $value,
                '--format' => 'json',
            ]);

            $this->assertSame(1, $tester->getStatusCode(), "--ip=$value");
            $this->assertStringContainsString('Invalid value for --ip (use: IPv4 address)', $tester->getDisplay());
        }
    }

    public function testAlbumListFiltersByKvsAdminTypeAccessReviewAndLock(): void
    {
        $cases = [
            ['--public' => true, 'expected' => [10]],
            ['--private' => true, 'expected' => [5]],
            ['--premium' => true, 'expected' => [20]],
            ['--access-level' => '0', 'expected' => [10]],
            ['--access-level' => '1', 'expected' => [5]],
            ['--access-level' => '2', 'expected' => [20]],
            ['--review-needed' => true, 'expected' => [20]],
            ['--not-review-needed' => true, 'expected' => [10, 5]],
            ['--locked' => true, 'expected' => [20]],
            ['--unlocked' => true, 'expected' => [10, 5]],
            ['--has-errors' => '1', 'expected' => [20]],
            ['--has-errors' => '10', 'expected' => [5]],
            ['--posted' => 'yes', 'expected' => [10]],
            ['--posted' => 'no', 'expected' => [20, 5]],
            ['--show-id' => '13', 'expected' => [10]],
            ['--show-id' => '14', 'expected' => [20]],
            ['--show-id' => '16', 'expected' => [20]],
            ['--show-id' => '17', 'expected' => [5]],
        ];

        foreach ($cases as $case) {
            $expected = $case['expected'];
            unset($case['expected']);

            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case,
                '--format' => 'json',
                '--fields' => 'album_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        }
    }

    public function testAlbumListFiltersByKvsAdminPostDateRange(): void
    {
        $cases = [
            ['options' => ['--post-date-from' => '2026-05-25'], 'expected' => [20, 10]],
            ['options' => ['--post-date-to' => '2026-05-25'], 'expected' => [10, 5]],
            [
                'options' => [
                    '--post-date-from' => '2026-05-25',
                    '--post-date-to' => '2026-05-25',
                ],
                'expected' => [10],
            ],
        ];

        foreach ($cases as $case) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case['options'],
                '--format' => 'json',
                '--fields' => 'album_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($case['expected'], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        }
    }

    public function testAlbumListRejectsInvalidPostDateFilter(): void
    {
        $cases = [
            'not-a-date',
            '2026-02-30',
            '2026-6-1',
            'June 1 2026',
        ];

        foreach ($cases as $value) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--post-date-from' => $value,
                '--format' => 'json',
            ]);

            $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString(
                'Invalid value for --post-date-from (use: YYYY-MM-DD)',
                $tester->getDisplay()
            );
        }
    }

    public function testAlbumListRejectsExplicitEmptyOptions(): void
    {
        $cases = [
            ['--limit' => '', 'expected' => 'Invalid value for --limit'],
            ['--format' => '', 'expected' => 'Invalid format:'],
            ['--fields' => '', 'expected' => 'The --fields option cannot be empty.'],
            ['--field' => '', 'expected' => 'The --field option cannot be empty.'],
            ['--status' => '', 'expected' => 'Invalid status'],
            ['--user' => '', 'expected' => 'Invalid value for --user'],
            ['--post-date-from' => '', 'expected' => 'Invalid value for --post-date-from'],
        ];

        foreach ($cases as $case) {
            $expected = $case['expected'];
            unset($case['expected']);

            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case,
            ]);

            $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString($expected, $tester->getDisplay());
        }
    }

    public function testAlbumListRejectsInvalidAdminPropertyFilters(): void
    {
        $cases = [
            ['--has-errors' => '100', 'expected' => 'Invalid value for --has-errors'],
            ['--posted' => 'maybe', 'expected' => 'Invalid value for --posted'],
            ['--show-id' => 'unknown', 'expected' => 'Invalid value for --show-id'],
        ];

        foreach ($cases as $case) {
            $expected = $case['expected'];
            unset($case['expected']);

            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case,
                '--format' => 'json',
            ]);

            $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString($expected, $tester->getDisplay());
        }
    }

    public function testAlbumListFiltersByKvsAdminFieldFilter(): void
    {
        $cases = [
            'empty/description' => [5],
            'filled/description' => [20, 10],
            'empty/gallery_url' => [10, 5],
            'filled/gallery_url' => [20],
            'empty/content_source' => [10, 5],
            'filled/content_source' => [20],
            'empty/admin_flag' => [10, 5],
            'filled/admin_flag' => [20],
            'empty/album_viewed' => [5],
            'filled/album_viewed' => [20, 10],
            'empty/comments' => [5],
            'filled/comments' => [20, 10],
            'empty/favourites' => [5],
            'filled/favourites' => [20, 10],
            'empty/purchases' => [10, 5],
            'filled/purchases' => [20],
            'empty/rating' => [5],
            'filled/rating' => [20, 10],
            'empty/tags' => [10, 5],
            'filled/tags' => [20],
            'empty/categories' => [10, 5],
            'filled/categories' => [20],
            'empty/models' => [10, 5],
            'filled/models' => [20],
        ];

        foreach ($cases as $filter => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--field-filter' => $filter,
                '--format' => 'json',
                '--fields' => 'album_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['album_id'], $rows), $filter);
        }
    }

    public function testAlbumListUsesStoredPhotosAmount(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'album_id,images',
            '--format' => 'json',
            '--limit' => 2,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertSame(3, (int) $rows[0]['images']);
        $this->assertSame(7, (int) $rows[1]['images']);
    }

    public function testAlbumListExposesKvsWebsiteLinkField(): void
    {
        file_put_contents(
            $this->kvsPath . '/admin/data/system/website_ui_params.dat',
            serialize([
                'WEBSITE_LINK_PATTERN_ALBUM' => 'album/%ID%/%DIR%/',
                'DISABLED_CONTENT_AVAILABILITY' => '0',
            ])
        );

        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'album_id,website_link',
            '--format' => 'json',
            '--limit' => 2,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'album_id');

        $this->assertSame(
            'https://example.test/album/10/active-album/',
            $rowsById[10]['website_link']
        );
        $this->assertSame(
            'https://example.test/album/20/disabled-album/',
            $rowsById[20]['website_link']
        );
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAlbumListSearchesKvsWebsiteLinkLikeKvsAdmin(): void
    {
        file_put_contents(
            $this->kvsPath . '/admin/data/system/website_ui_params.dat',
            serialize([
                'WEBSITE_LINK_PATTERN_ALBUM' => 'album/%ID%/%DIR%/',
                'DISABLED_CONTENT_AVAILABILITY' => '0',
            ])
        );

        $this->tester->execute([
            'action' => 'list',
            '--search' => 'https://example.test/album/20/disabled-album/',
            '--fields' => 'album_id',
            '--format' => 'json',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
    }

    public function testAlbumListSearchesDescriptionLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Active album description',
            '--format' => 'json',
            '--fields' => 'album_id,description',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([10], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        $this->assertSame('Active album description', $rows[0]['description']);
    }

    public function testAlbumListSearchesIdLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '20',
            '--format' => 'json',
            '--fields' => 'album_id,title',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20], array_map(static fn (array $row): int => (int) $row['album_id'], $rows));
        $this->assertSame('Disabled Album', $rows[0]['title']);
    }

    public function testAlbumDefaultsToListAction(): void
    {
        $this->tester->execute([
            '--format' => 'count',
        ]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('3', trim($this->tester->getDisplay()));
    }

    public function testAlbumListExposesKvsAdminCountFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'album_id,title,photos_amount,comments_count,favourites_count,purchases_count',
            '--format' => 'json',
            '--limit' => 2,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(20, (int) $rows[0]['album_id']);
        $this->assertSame('Disabled Album', $rows[0]['title']);
        $this->assertSame(3, (int) $rows[0]['photos_amount']);
        $this->assertSame(1, (int) $rows[0]['comments_count']);
        $this->assertSame(2, (int) $rows[0]['favourites_count']);
        $this->assertSame(1, (int) $rows[0]['purchases_count']);

        $this->assertSame(10, (int) $rows[1]['album_id']);
        $this->assertSame(7, (int) $rows[1]['photos_amount']);
        $this->assertSame(2, (int) $rows[1]['comments_count']);
        $this->assertSame(5, (int) $rows[1]['favourites_count']);
        $this->assertSame(0, (int) $rows[1]['purchases_count']);
    }

    public function testAlbumListExposesKvsAdminRelationFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'album_id,title,thumb,content_source,admin_flag,server_group,tags,categories,models,ip',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(20, (int) $rows[0]['album_id']);
        $this->assertSame('Disabled Album', $rows[0]['title']);
        $this->assertSame('', $rows[0]['thumb']);
        $this->assertSame('Gallery Studio', $rows[0]['content_source']);
        $this->assertSame('Album Review', $rows[0]['admin_flag']);
        $this->assertSame('Album Storage', $rows[0]['server_group']);
        $this->assertSame('zeta-album,album-tag', $rows[0]['tags']);
        $this->assertSame('Second Album Category,Album Category', $rows[0]['categories']);
        $this->assertSame('Album Model Two,Album Model', $rows[0]['models']);
        $this->assertSame('127.0.0.1', $rows[0]['ip']);
    }

    public function testAlbumListExposesRelationStatusFieldsWhenRequestedDirectly(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'album_id,content_source_status_id,server_group_status_id,admin_user_is_superadmin',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(20, (int) $rows[0]['album_id']);
        $this->assertSame(1, (int) $rows[0]['content_source_status_id']);
        $this->assertSame(1, (int) $rows[0]['server_group_status_id']);
        $this->assertSame(0, (int) $rows[0]['admin_user_is_superadmin']);
    }

    public function testAlbumListExposesKvsAdminErrorHighlightField(): void
    {
        $this->db->exec('UPDATE ' . TestHelper::table('albums') . ' SET status_id = 2 WHERE album_id = 20');

        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Disabled Album',
            '--fields' => 'album_id,status_id,is_error',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(20, (int) $rows[0]['album_id']);
        $this->assertSame(2, (int) $rows[0]['status_id']);
        $this->assertSame(1, (int) $rows[0]['is_error']);
    }

    public function testAlbumListExposesKvsAdminRawScalarAndUserFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'album_id,dir,description,user_status_id,admin_user,admin_user_is_superadmin,access_level_id,tokens_required,added_date',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(20, (int) $rows[0]['album_id']);
        $this->assertSame('disabled-album', $rows[0]['dir']);
        $this->assertSame('Disabled album description', $rows[0]['description']);
        $this->assertSame(0, (int) $rows[0]['user_status_id']);
        $this->assertSame('moderator', $rows[0]['admin_user']);
        $this->assertSame(0, (int) $rows[0]['admin_user_is_superadmin']);
        $this->assertSame(2, (int) $rows[0]['access_level_id']);
        $this->assertSame(15, (int) $rows[0]['tokens_required']);
        $this->assertSame('2026-05-26 09:00:00', $rows[0]['added_date']);
    }

    public function testAlbumListSeparatesKvsAccessTypeAndAccessLevel(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'album_id,is_private,type,access_level_id,access',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(20, (int) $rows[0]['album_id']);
        $this->assertSame('Premium', $rows[0]['is_private']);
        $this->assertSame('Premium', $rows[0]['type']);
        $this->assertSame(2, (int) $rows[0]['access_level_id']);
        $this->assertSame('Only members', $rows[0]['access']);
    }

    public function testAlbumListFormats(): void
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
        $this->assertSame(20, (int) $jsonRows[0]['album_id']);
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

        $this->assertStringContainsString('album_id', $csvOutput);
        $this->assertStringContainsString('Disabled Album', $csvOutput);
        $this->assertEquals(0, $testerCsv->getStatusCode());

        // Test count format
        $testerCount = new CommandTester($this->command);
        $testerCount->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'count'
        ]);

        $output = trim($testerCount->getDisplay());
        $this->assertSame('3', $output);
        $this->assertEquals(0, $testerCount->getStatusCode());
    }

    public function testAlbumListAcceptsKvsLifecycleStatusAliases(): void
    {
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('albums') .
            ' (album_id, user_id, admin_user_id, title, dir, description, status_id, is_private, access_level_id, tokens_required, ' .
            'post_date, album_viewed, rating, rating_amount, photos_amount, favourites_count, purchases_count, ' .
            'content_source_id, admin_flag_id, server_group_id, added_date, ip) VALUES ' .
            "(30, 1, 0, 'Error Album', 'error-album', '', 2, 0, 0, 0, '2026-05-27 10:00:00', 0, 0, 0, 0, 0, 0, 0, 0, 0, '2026-05-27 10:00:00', 0), " .
            "(40, 1, 0, 'Processing Album', 'processing-album', '', 3, 0, 0, 0, " .
            "'2026-05-27 11:00:00', 0, 0, 0, 0, 0, 0, 0, 0, 0, '2026-05-27 11:00:00', 0), " .
            "(50, 1, 0, 'Deleting Album', 'deleting-album', '', 4, 0, 0, 0, " .
            "'2026-05-27 12:00:00', 0, 0, 0, 0, 0, 0, 0, 0, 0, '2026-05-27 12:00:00', 0), " .
            "(60, 1, 0, 'Deleted Album', 'deleted-album', '', 5, 0, 0, 0, '2026-05-27 13:00:00', 0, 0, 0, 0, 0, 0, 0, 0, 0, '2026-05-27 13:00:00', 0)"
        );

        $cases = [
            'error' => [30, 'Error'],
            'in_process' => [40, 'In process'],
            'deleting' => [50, 'Deleting'],
            'deleted' => [60, 'Deleted'],
        ];

        foreach ($cases as $status => [$expectedId, $expectedLabel]) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--status' => $status,
                '--fields' => 'album_id,status',
                '--format' => 'json',
                '--limit' => 1,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($expectedId, (int) $rows[0]['album_id']);
            $this->assertSame($expectedLabel, $rows[0]['status']);
        }
    }

    public function testAlbumShow(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Album #10', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Active Album', $output);
        $this->assertMatchesRegularExpression('/Type\W+Public/', $output);
        $this->assertMatchesRegularExpression('/Access\W+From access type/', $output);
        $this->assertMatchesRegularExpression('/User\W+alice/', $output);
        $this->assertMatchesRegularExpression('/Images\W+7/', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAlbumShowSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('10', $rows[0]['album_id']);
        $this->assertSame('Active Album', $rows[0]['title']);
        $this->assertSame('Public', $rows[0]['type']);
        $this->assertSame('From access type', $rows[0]['access']);
        $this->assertSame('alice', $rows[0]['user']);
        $this->assertSame('7', $rows[0]['images']);
        $this->assertStringNotContainsString('Album #10', $output);
    }

    public function testAlbumShowRejectsCountFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
            '--format' => 'count',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('show action does not support --format=count', $this->tester->getDisplay());
    }

    public function testAlbumShowHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
            '--fields' => 'title',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Active Album', $output);
        $this->assertStringNotContainsString('Album #10', $output);
        $this->assertStringNotContainsString('Property', $output);
    }

    public function testAlbumShowRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Album ID', $this->tester->getDisplay());
    }

    public function testAlbumCommandMetadata(): void
    {
        $this->assertEquals('content:album', $this->command->getName());
        $this->assertStringContainsString('manage', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('album', $aliases);
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('albums') . ' (' .
            'album_id INTEGER, user_id INTEGER, admin_user_id INTEGER, title TEXT, dir TEXT, description TEXT, ' .
            'gallery_url TEXT, delete_reason TEXT, custom1 TEXT, custom2 TEXT, custom3 TEXT, af_custom1 INTEGER, ' .
            'af_custom2 INTEGER, af_custom3 INTEGER, is_review_needed INTEGER, is_locked INTEGER, ' .
            'status_id INTEGER, is_private INTEGER, access_level_id INTEGER, tokens_required INTEGER, ' .
            'post_date TEXT, album_viewed INTEGER, album_viewed_unique INTEGER, rating REAL, rating_amount INTEGER, ' .
            'photos_amount INTEGER, favourites_count INTEGER, purchases_count INTEGER, content_source_id INTEGER, ' .
            'admin_flag_id INTEGER, server_group_id INTEGER, added_date TEXT, ip INTEGER, ' .
            'has_errors INTEGER, relative_post_date INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('albums_images') . ' (album_id INTEGER)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('comments') .
            ' (comment_id INTEGER, object_type_id INTEGER, object_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('users') . ' (user_id INTEGER, username TEXT, status_id INTEGER)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_users') . ' (' .
            'user_id INTEGER, login TEXT, is_superadmin INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('content_sources') . ' (' .
            'content_source_id INTEGER, title TEXT, status_id INTEGER, content_source_group_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('content_sources_groups') . ' (' .
            'content_source_group_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('flags') . ' (' .
            'flag_id INTEGER, title TEXT)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('flags_albums') . ' (album_id INTEGER, flag_id INTEGER, votes INTEGER)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_servers_groups') . ' (' .
            'group_id INTEGER, title TEXT, status_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('categories_groups') . ' (category_group_id INTEGER, title TEXT)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories') .
            ' (category_id INTEGER, title TEXT, category_group_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_albums') .
            ' (id INTEGER, category_id INTEGER, album_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('tags') . ' (tag_id INTEGER, tag TEXT)');
        $db->exec('CREATE TABLE ' . TestHelper::table('tags_albums') . ' (id INTEGER, tag_id INTEGER, album_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('models_groups') . ' (model_group_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ' . TestHelper::table('models') . ' (model_id INTEGER, title TEXT, model_group_id INTEGER)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('models_albums') .
            ' (id INTEGER, model_id INTEGER, album_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_audit_log') .
            ' (record_id INTEGER, object_id INTEGER, object_type_id INTEGER, action_id INTEGER)'
        );

        $db->exec("INSERT INTO " . TestHelper::table('users') . " VALUES (1, 'alice', 1), (2, 'bob', 0)");
        $db->exec("INSERT INTO " . TestHelper::table('admin_users') . " VALUES (8, 'moderator', 0), (9, 'admin', 1)");
        $db->exec(
            "INSERT INTO " . TestHelper::table('albums') .
            ' (album_id, user_id, admin_user_id, title, dir, description, gallery_url, delete_reason, custom1, custom2, custom3, ' .
            'af_custom1, af_custom2, af_custom3, is_review_needed, is_locked, status_id, is_private, access_level_id, ' .
            'tokens_required, post_date, album_viewed, album_viewed_unique, rating, rating_amount, photos_amount, ' .
            'favourites_count, purchases_count, content_source_id, admin_flag_id, server_group_id, added_date, ip, ' .
            'has_errors, relative_post_date) VALUES ' .
            "(10, 1, 9, 'Active Album', 'active-album', 'Active album description', '', '', '', '', '', " .
            "0, 0, 0, 0, 0, 1, 0, 0, 0, '2026-05-25 10:00:00', 12, 12, 40, 10, 7, 5, 0, 0, 0, 0, " .
            "'2026-05-25 09:00:00', 0, 0, 0), " .
            "(20, 2, 8, 'Disabled Album', 'disabled-album', 'Disabled album description', " .
            "'https://gallery.example/20', '', 'featured', '', '', 1, 0, 0, 1, 1, 0, 2, 2, 15, " .
            "'2026-05-26 10:00:00', 5, 5, 10, 5, 3, 2, 1, 3, 4, 5, '2026-05-26 09:00:00', " .
            "2130706433, 1, 0), " .
            "(5, 1, 0, 'Private Empty Album', 'private-empty-album', '', '', '', '', '', '', " .
            "0, 0, 0, 0, 0, 0, 1, 1, 0, '2026-05-24 10:00:00', 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, " .
            "'2026-05-24 09:00:00', 0, 2, 0)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('content_sources') .
            " VALUES (3, 'Gallery Studio', 1, 11)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('content_sources_groups') .
            " VALUES (11, 'Gallery Group')"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('flags') .
            " VALUES (4, 'Album Review')"
        );
        $db->exec("INSERT INTO " . TestHelper::table('flags_albums') . ' VALUES (10, 6, 3), (5, 6, 1)');
        $db->exec(
            "INSERT INTO " . TestHelper::table('admin_servers_groups') .
            " VALUES (5, 'Album Storage', 1)"
        );
        $db->exec("INSERT INTO " . TestHelper::table('albums_images') . " VALUES (10), (10), (20)");
        $db->exec("INSERT INTO " . TestHelper::table('categories_groups') . " VALUES (12, 'Album Category Group')");
        $db->exec(
            "INSERT INTO " . TestHelper::table('categories') .
            " VALUES (1, 'Album Category', 0), (2, 'Second Album Category', 12)"
        );
        $db->exec("INSERT INTO " . TestHelper::table('categories_albums') . " VALUES (1, 2, 20), (2, 1, 20)");
        $db->exec("INSERT INTO " . TestHelper::table('tags') . " VALUES (1, 'album-tag'), (2, 'zeta-album')");
        $db->exec("INSERT INTO " . TestHelper::table('tags_albums') . " VALUES (1, 2, 20), (2, 1, 20)");
        $db->exec("INSERT INTO " . TestHelper::table('models_groups') . " VALUES (13, 'Album Model Group')");
        $db->exec(
            "INSERT INTO " . TestHelper::table('models') .
            " VALUES (1, 'Album Model', 0), (2, 'Album Model Two', 13)"
        );
        $db->exec("INSERT INTO " . TestHelper::table('models_albums') . " VALUES (1, 2, 20), (2, 1, 20)");
        $db->exec(
            'INSERT INTO ' . TestHelper::table('comments') .
            ' (comment_id, object_type_id, object_id) VALUES ' .
            '(1, 2, 10), (2, 2, 10), (3, 2, 20), (4, 1, 20)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('admin_audit_log') .
            ' VALUES (1, 10, 2, 100), (2, 20, 2, 140), (3, 5, 2, 110)'
        );

        return $db;
    }

    private function createCommand(PDO $db): AlbumCommand
    {
        return new class ($this->config, $db) extends AlbumCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:album');
                $this->setDescription('Manage KVS photo albums');
                $this->setAliases(['album', 'albums', 'gallery']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
