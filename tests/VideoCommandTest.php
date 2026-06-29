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

    public function testVideoListFiltersUserByUsernameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--user' => 'alice',
            '--fields' => 'video_id,user',
            '--format' => 'json',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([30, 10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        $this->assertSame(['alice', 'alice'], array_column($rows, 'user'));
    }

    public function testVideoListFiltersCategoryByTitleLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--category' => 'Drama',
            '--fields' => 'video_id,categories',
            '--format' => 'json',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20, 10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        $this->assertSame('Drama', $rows[0]['categories']);
        $this->assertSame('Drama,Action', $rows[1]['categories']);
    }

    public function testVideoListFiltersTagByNameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--tag' => 'tag-two',
            '--fields' => 'video_id,tags',
            '--format' => 'json',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        $this->assertSame('tag-two,tag-one', $rows[0]['tags']);
    }

    public function testVideoListFiltersModelByTitleLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--model' => 'Model Two',
            '--fields' => 'video_id,models',
            '--format' => 'json',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        $this->assertSame('Model Two,Model One', $rows[0]['models']);
    }

    public function testVideoListFiltersContentSourceByTitleLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--content-source' => 'Studio One',
            '--fields' => 'video_id,content_source',
            '--format' => 'json',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        $this->assertSame('Studio One', $rows[0]['content_source']);
    }

    public function testVideoListFiltersDvdByTitleLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--dvd' => 'Series One',
            '--fields' => 'video_id,dvd',
            '--format' => 'json',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        $this->assertSame('Series One', $rows[0]['dvd']);
    }

    public function testVideoListFiltersCategoryGroupByTitleLikeKvsAdmin(): void
    {
        $cases = [
            ['--category-group' => 'Genre Group', 'expected' => [20, 10]],
            ['--category-group' => 'Missing Group', 'expected' => []],
            ['--content-source-group' => 'Studio Group', 'expected' => [10]],
            ['--content-source-group' => 'Missing Group', 'expected' => []],
            ['--dvd-group' => 'Series Group', 'expected' => [10]],
            ['--dvd-group' => 'Missing Group', 'expected' => []],
            ['--model-group' => 'Model Group', 'expected' => [10]],
            ['--model-group' => 'Missing Group', 'expected' => []],
        ];

        foreach ($cases as $case) {
            $expected = $case['expected'];
            unset($case['expected']);

            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case,
                '--fields' => 'video_id',
                '--format' => 'json',
                '--limit' => 5,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($expected, array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        }
    }

    public function testVideoListFiltersByKvsAdminAdminIpAndStorageGroup(): void
    {
        $cases = [
            ['options' => ['--admin-user' => 'moderator'], 'expected' => [10]],
            ['options' => ['--admin-user' => '9'], 'expected' => [20]],
            ['options' => ['--admin-user' => 'missing-admin'], 'expected' => []],
            ['options' => ['--ip' => '127.0.0.1'], 'expected' => [10]],
            ['options' => ['--ip' => '0'], 'expected' => [30, 20]],
            ['options' => ['--server-group' => 'Primary Storage'], 'expected' => [10]],
        ];

        foreach ($cases as $case) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case['options'],
                '--fields' => 'video_id',
                '--format' => 'json',
                '--limit' => 5,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($case['expected'], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        }
    }

    public function testVideoListRejectsInvalidIpFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--ip' => '999.999.999.999',
            '--format' => 'json',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --ip (use: IPv4 address)', $this->tester->getDisplay());
    }

    public function testVideoListFiltersByKvsAdminVideoProperties(): void
    {
        $cases = [
            ['options' => ['--resolution' => '2'], 'expected' => [10]],
            ['options' => ['--resolution' => '101'], 'expected' => [30, 10]],
            ['options' => ['--load-type' => '1'], 'expected' => [10]],
            ['options' => ['--load-type' => '0'], 'expected' => [30]],
            ['options' => ['--format-video-group' => 'HD Formats'], 'expected' => [10]],
            ['options' => ['--format-video-group' => 'Missing Group'], 'expected' => []],
            ['options' => ['--feed' => '100'], 'expected' => [10]],
            ['options' => ['--feed' => '999'], 'expected' => []],
            ['options' => ['--has-errors' => '1'], 'expected' => [20]],
            ['options' => ['--has-errors' => '10'], 'expected' => [30]],
            ['options' => ['--posted' => 'yes'], 'expected' => [30, 10]],
            ['options' => ['--posted' => 'no'], 'expected' => [20]],
            ['options' => ['--neuroscore' => 'score_missing'], 'expected' => [30]],
            ['options' => ['--neuroscore' => 'score_finished'], 'expected' => [10]],
            ['options' => ['--digiregs-copyright' => 'copyright_applied'], 'expected' => [30, 10]],
            ['options' => ['--digiregs-copyright' => 'copyright_not_applied'], 'expected' => [20]],
            ['options' => ['--digiregs-copyright' => 'copyright_studio'], 'expected' => [10]],
            ['options' => ['--show-id' => '15'], 'expected' => [10]],
            ['options' => ['--show-id' => '18'], 'expected' => [10]],
            ['options' => ['--show-id' => '19'], 'expected' => [10]],
            ['options' => ['--show-id' => '21'], 'expected' => [30]],
            ['options' => ['--show-id' => '22'], 'expected' => [20, 10]],
            ['options' => ['--show-id' => '23'], 'expected' => [10]],
            ['options' => ['--show-id' => '24'], 'expected' => [30, 20]],
            ['options' => ['--show-id' => 'is_vertical'], 'expected' => [20]],
            ['options' => ['--show-id' => 'is_horizontal'], 'expected' => [30, 10]],
            ['options' => ['--show-id' => 'wf/mp4'], 'expected' => [10]],
            ['options' => ['--show-id' => 'wq/720'], 'expected' => [30, 10]],
            ['options' => ['--show-id' => 'woq/720'], 'expected' => [20]],
        ];

        foreach ($cases as $case) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case['options'],
                '--fields' => 'video_id',
                '--format' => 'json',
                '--limit' => 5,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($case['expected'], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        }
    }

    public function testVideoListRejectsInvalidAdminPropertyFilters(): void
    {
        $cases = [
            ['--load-type' => '4', 'expected' => 'Invalid value for --load-type'],
            ['--feed' => '0', 'expected' => 'Invalid value for --feed'],
            ['--server-group' => '0', 'expected' => 'Invalid value for --server-group'],
            ['--format-video-group' => '0', 'expected' => 'Invalid value for --format-video-group'],
            ['--has-errors' => '2', 'expected' => 'Invalid value for --has-errors'],
            ['--posted' => 'maybe', 'expected' => 'Invalid value for --posted'],
            ['--neuroscore' => 'unknown', 'expected' => 'Invalid value for --neuroscore'],
            ['--digiregs-copyright' => 'unknown', 'expected' => 'Invalid value for --digiregs-copyright'],
            ['--show-id' => 'unknown', 'expected' => 'Invalid value for --show-id'],
            ['--show-id' => 'wq/not-a-number', 'expected' => 'Invalid value for --show-id quality filter'],
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

    public function testVideoListFiltersPlaylistByTitleLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--playlist' => 'Editorial Picks',
            '--fields' => 'video_id,title',
            '--format' => 'json',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([30, 10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
    }

    public function testVideoListMissingPlaylistTitleMatchesKvsAdminEmptyResult(): void
    {
        $this->db->exec('INSERT INTO ' . TestHelper::table('fav_videos') . ' VALUES (20, 0)');

        $this->tester->execute([
            'action' => 'list',
            '--playlist' => '__missing_playlist__',
            '--fields' => 'video_id',
            '--format' => 'json',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([], $rows);
    }

    public function testVideoListFiltersByKvsAdminPrivacyAccessReviewLockedAndRanges(): void
    {
        $cases = [
            ['options' => ['--public' => true], 'expected' => [10]],
            ['options' => ['--private' => true], 'expected' => [30]],
            ['options' => ['--premium' => true], 'expected' => [20]],
            ['options' => ['--access-level' => '0'], 'expected' => [30, 10]],
            ['options' => ['--access-level' => '2'], 'expected' => [20]],
            ['options' => ['--review-needed' => true], 'expected' => [20]],
            ['options' => ['--not-review-needed' => true], 'expected' => [30, 10]],
            ['options' => ['--locked' => true], 'expected' => [20]],
            ['options' => ['--unlocked' => true], 'expected' => [30, 10]],
            ['options' => ['--post-date-from' => '2026-05-25'], 'expected' => [20, 10]],
            ['options' => ['--post-date-to' => '2026-05-25'], 'expected' => [30, 20]],
            ['options' => ['--duration-from' => '100'], 'expected' => [30, 10]],
            ['options' => ['--duration-to' => '100'], 'expected' => [20]],
        ];

        foreach ($cases as $case) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case['options'],
                '--fields' => 'video_id',
                '--format' => 'json',
                '--limit' => 5,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($case['expected'], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        }
    }

    public function testVideoListRejectsConflictingAdminFilters(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--public' => true,
            '--private' => true,
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('cannot be used together', $this->tester->getDisplay());
    }

    public function testVideoListFiltersByKvsAdminFieldFilter(): void
    {
        $cases = [
            'filled/title' => [30, 20, 10],
            'empty/title' => [],
            'filled/description' => [10],
            'empty/description' => [30, 20],
            'filled/gallery_url' => [10],
            'empty/gallery_url' => [30, 20],
            'filled/custom1' => [10],
            'empty/custom1' => [30, 20],
            'filled/af_custom1' => [10],
            'empty/af_custom1' => [30, 20],
            'filled/content_source' => [10],
            'empty/content_source' => [30, 20],
            'filled/dvd' => [10],
            'empty/dvd' => [30, 20],
            'filled/admin' => [20, 10],
            'empty/admin' => [30],
            'filled/admin_flag' => [10],
            'empty/admin_flag' => [30, 20],
            'filled/tokens_required' => [10],
            'empty/tokens_required' => [30, 20],
            'filled/video_viewed' => [30, 20, 10],
            'empty/video_viewed' => [],
            'filled/video_viewed_unique' => [10],
            'empty/video_viewed_unique' => [30, 20],
            'filled/comments' => [20, 10],
            'empty/comments' => [30],
            'filled/favourites' => [30, 10],
            'empty/favourites' => [20],
            'filled/purchases' => [10],
            'empty/purchases' => [30, 20],
            'filled/rating' => [30, 10],
            'empty/rating' => [20],
            'filled/tags' => [10],
            'empty/tags' => [30, 20],
            'filled/categories' => [20, 10],
            'empty/categories' => [30],
            'filled/models' => [10],
            'empty/models' => [30, 20],
        ];

        foreach ($cases as $filter => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--field-filter' => $filter,
                '--fields' => 'video_id',
                '--format' => 'json',
                '--limit' => 5,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['video_id'], $rows), $filter);
        }
    }

    public function testVideoListFiltersByKvsAdminFlagVotes(): void
    {
        $cases = [
            ['options' => ['--flag' => '5'], 'expected' => [10]],
            ['options' => ['--flag' => '6'], 'expected' => [30, 20]],
            ['options' => ['--flag' => '6', '--flag-votes' => '2'], 'expected' => [30]],
            ['options' => ['--flag' => '999'], 'expected' => []],
        ];

        foreach ($cases as $case) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case['options'],
                '--fields' => 'video_id',
                '--format' => 'json',
                '--limit' => 5,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($case['expected'], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        }
    }

    public function testVideoListRejectsFlagVotesWithoutFlag(): void
    {
        foreach (['1', '2'] as $flagVotes) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--flag-votes' => $flagVotes,
                '--format' => 'count',
            ]);

            $this->assertSame(1, $tester->getStatusCode(), $flagVotes);
            $this->assertStringContainsString('Option --flag-votes requires --flag', $tester->getDisplay());
        }
    }

    public function testVideoThumbMatchesKvsAdminWhenScreenMainIsZero(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'video_id,thumb',
            '--format' => 'json',
            '--limit' => 2,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'video_id');

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(
            'https://admin-cdn.example.test/videos_screenshots/0/20/320x180/0.jpg',
            $rowsById[20]['thumb']
        );
    }

    public function testVideoListExposesKvsWebsiteLinkField(): void
    {
        file_put_contents(
            $this->kvsPath . '/admin/data/system/website_ui_params.dat',
            serialize([
                'WEBSITE_LINK_PATTERN' => 'video/%ID%/%DIR%/',
                'DISABLED_CONTENT_AVAILABILITY' => '0',
            ])
        );

        $this->tester->execute([
            'action' => 'list',
            '--fields' => 'video_id,website_link',
            '--format' => 'json',
            '--limit' => 3,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'video_id');

        $this->assertSame(
            'https://example.test/video/10/featured-clip/',
            $rowsById[10]['website_link']
        );
        $this->assertSame(
            'https://example.test/video/20/disabled-clip/',
            $rowsById[20]['website_link']
        );
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testVideoListSearchesKvsWebsiteLinkLikeKvsAdmin(): void
    {
        file_put_contents(
            $this->kvsPath . '/admin/data/system/website_ui_params.dat',
            serialize([
                'WEBSITE_LINK_PATTERN' => 'video/%ID%/%DIR%/',
                'DISABLED_CONTENT_AVAILABILITY' => '0',
            ])
        );

        $this->tester->execute([
            'action' => 'list',
            '--search' => 'https://example.test/video/10/featured-clip/',
            '--fields' => 'video_id',
            '--format' => 'json',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
    }

    public function testVideoListRejectsInvalidStatusWithCliError(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'bogus',
            '--format' => 'count',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid status "bogus"', $output);
        $this->assertStringNotContainsString('In BaseCommand.php line', $output);
    }

    public function testVideoListEscapesLikeWildcardsInSearch(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '%',
            '--format' => 'count',
        ]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('0', trim($this->tester->getDisplay()));
    }

    public function testVideoListSearchesDescriptionLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Featured description',
            '--format' => 'json',
            '--fields' => 'video_id,description',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        $this->assertSame('Featured description', $rows[0]['description']);
    }

    public function testVideoListSearchesIdAndAdminFieldsLikeKvsAdmin(): void
    {
        foreach (['10', 'custom one', '<iframe src="featured"></iframe>'] as $search) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--search' => $search,
                '--format' => 'json',
                '--fields' => 'video_id',
                '--limit' => 5,
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame([10], array_map(static fn (array $row): int => (int) $row['video_id'], $rows));
        }
    }

    public function testVideoDefaultsToListAction(): void
    {
        $this->tester->execute([
            '--format' => 'count',
        ]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('3', trim($this->tester->getDisplay()));
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

    public function testVideoListExposesRelationStatusFieldsWhenRequestedDirectly(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Featured Clip',
            '--fields' => 'video_id,content_source_status_id,dvd_status_id,server_group_status_id,admin_user_is_superadmin',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(10, (int) $rows[0]['video_id']);
        $this->assertSame(1, (int) $rows[0]['content_source_status_id']);
        $this->assertSame(1, (int) $rows[0]['dvd_status_id']);
        $this->assertSame(1, (int) $rows[0]['server_group_status_id']);
        $this->assertSame(0, (int) $rows[0]['admin_user_is_superadmin']);
    }

    public function testVideoListExposesKvsAdminErrorHighlightField(): void
    {
        $this->db->exec('UPDATE ' . TestHelper::table('videos') . ' SET status_id = 2 WHERE video_id = 20');

        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Disabled Clip',
            '--fields' => 'video_id,status_id,is_error',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(20, (int) $rows[0]['video_id']);
        $this->assertSame(2, (int) $rows[0]['status_id']);
        $this->assertSame(1, (int) $rows[0]['is_error']);
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
            'dir TEXT, is_private INTEGER, is_review_needed INTEGER, is_locked INTEGER, access_level_id INTEGER, duration INTEGER, ' .
            'file_size INTEGER, file_dimensions TEXT, post_date TEXT, rating INTEGER, rating_amount INTEGER, video_viewed INTEGER, ' .
            'video_viewed_unique INTEGER, comments_count INTEGER, favourites_count INTEGER, purchases_count INTEGER, r_ctr REAL, ' .
            'description TEXT, gallery_url TEXT, website_link TEXT, file_url TEXT, embed TEXT, pseudo_url TEXT, ' .
            'delete_reason TEXT, custom1 TEXT, custom2 TEXT, custom3 TEXT, af_custom1 INTEGER, af_custom2 INTEGER, ' .
            'af_custom3 INTEGER, tokens_required INTEGER, content_source_id INTEGER, dvd_id INTEGER, admin_flag_id INTEGER, ' .
            'server_group_id INTEGER, format_video_group_id INTEGER, screen_main INTEGER, ip INTEGER, ' .
            'load_type_id INTEGER, feed_id INTEGER, has_errors INTEGER, relative_post_date INTEGER, ' .
            'is_vertical INTEGER, rs_completed INTEGER, file_formats TEXT)'
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
            'CREATE TABLE ' . TestHelper::table('dvds') . ' (' .
            'dvd_id INTEGER, title TEXT, status_id INTEGER, dvd_group_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('dvds_groups') . ' (' .
            'dvd_group_id INTEGER, title TEXT)'
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
            ' (comment_id INTEGER, object_type_id INTEGER, object_id INTEGER, is_approved INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('formats_screenshots') .
            ' (format_screenshot_id INTEGER, size TEXT, status_id INTEGER, group_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_groups') .
            ' (category_group_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories') .
            ' (category_id INTEGER, title TEXT, category_group_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_videos') .
            ' (id INTEGER, category_id INTEGER, video_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('tags') . ' (tag_id INTEGER, tag TEXT)');
        $db->exec('CREATE TABLE ' . TestHelper::table('tags_videos') . ' (id INTEGER, tag_id INTEGER, video_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('models') . ' (model_id INTEGER, title TEXT, model_group_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('models_groups') . ' (model_group_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ' . TestHelper::table('models_videos') . ' (id INTEGER, model_id INTEGER, video_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('playlists') . ' (playlist_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ' . TestHelper::table('fav_videos') . ' (video_id INTEGER, playlist_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('flags_videos') . ' (video_id INTEGER, flag_id INTEGER, votes INTEGER)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('videos_advanced_operations') .
            ' (video_id INTEGER, operation_type_id INTEGER, operation_status_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_audit_log') .
            ' (record_id INTEGER, object_id INTEGER, object_type_id INTEGER, action_id INTEGER)'
        );

        $db->exec("INSERT INTO " . TestHelper::table('users') . " VALUES (1, 'alice', 1), (2, 'bob', 0)");
        $db->exec("INSERT INTO " . TestHelper::table('admin_users') . " VALUES (8, 'moderator', 0), (9, 'admin', 1)");
        $db->exec(
            "INSERT INTO " . TestHelper::table('videos') .
            " (video_id, user_id, admin_user_id, title, status_id, resolution_type, dir, is_private, access_level_id, duration, file_size, " .
            "is_review_needed, is_locked, file_dimensions, post_date, rating, rating_amount, video_viewed, video_viewed_unique, " .
            "comments_count, favourites_count, purchases_count, r_ctr, description, gallery_url, custom1, custom2, custom3, " .
            "website_link, file_url, embed, pseudo_url, delete_reason, af_custom1, af_custom2, af_custom3, " .
            "tokens_required, content_source_id, dvd_id, admin_flag_id, server_group_id, " .
            "format_video_group_id, screen_main, ip, load_type_id, feed_id, has_errors, relative_post_date) VALUES " .
            "(10, 1, 8, 'Featured Clip', 1, 2, 'featured-clip', 0, 0, 125, 1048576, 0, 0, '1920x1080', " .
            "'2026-05-26 10:00:00', 40, 10, 123, 12, 2, 7, 1, 0.125, 'Featured description', " .
            "'https://example.test/gallery', 'custom one', '', '', '', '', '<iframe src=\"featured\"></iframe>', '', '', " .
            "1, 0, 0, 5, 3, 4, 5, 6, 7, 3, 2130706433, 1, 100, 0, 0), " .
            "(20, 2, 9, 'Disabled Clip', 0, 0, 'disabled-clip', 2, 2, 61, 524288, 1, 1, '640x360', " .
            "'2026-05-25 10:00:00', 0, 1, 5, 0, 1, 0, 0, 0.050, '', '', '', '', '', '', '', '', '', '', " .
            "0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 0, 1, 0), " .
            "(30, 1, 0, 'Older Active Clip', 1, 1, 'older-active-clip', 1, 0, 3600, 2097152, 0, 0, '1280x720', " .
            "'2026-05-24 10:00:00', 15, 5, 20, 0, 0, 1, 0, 0, '', '', '', '', '', '', '', '', '', '', " .
            "0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 0)"
        );
        $db->exec(
            "UPDATE " . TestHelper::table('videos') .
            " SET is_vertical = 0, rs_completed = 1, file_formats = '||mp4|' WHERE video_id = 10"
        );
        $db->exec(
            "UPDATE " . TestHelper::table('videos') .
            " SET is_vertical = 1, rs_completed = 0, file_formats = '' WHERE video_id = 20"
        );
        $db->exec(
            "UPDATE " . TestHelper::table('videos') .
            " SET screen_main = 1, is_vertical = 0, rs_completed = 0, file_formats = '||webm|' WHERE video_id = 30"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('content_sources') .
            " VALUES (3, 'Studio One', 1, 12)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('content_sources_groups') .
            " VALUES (12, 'Studio Group')"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('dvds') .
            " VALUES (4, 'Series One', 1, 14)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('dvds_groups') .
            " VALUES (14, 'Series Group')"
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
            ' (comment_id, object_type_id, object_id, is_approved) VALUES ' .
            '(1, 1, 10, 1), (2, 1, 10, 1), (3, 1, 10, 0), (4, 2, 10, 1), (5, 1, 20, 1)'
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('formats_screenshots') .
            " VALUES (1, 'source', 1, 1), (2, '640x360', 1, 1), (3, '320x180', 1, 1)"
        );
        $db->exec("INSERT INTO " . TestHelper::table('categories_groups') . " VALUES (10, 'Genre Group')");
        $db->exec("INSERT INTO " . TestHelper::table('categories') . " VALUES (1, 'Action', 0), (2, 'Drama', 10)");
        $db->exec("INSERT INTO " . TestHelper::table('categories_videos') . " VALUES (1, 2, 10), (2, 1, 10), (3, 2, 20)");
        $db->exec("INSERT INTO " . TestHelper::table('tags') . " VALUES (1, 'tag-one'), (2, 'tag-two')");
        $db->exec("INSERT INTO " . TestHelper::table('tags_videos') . " VALUES (1, 2, 10), (2, 1, 10)");
        $db->exec("INSERT INTO " . TestHelper::table('models_groups') . " VALUES (16, 'Model Group')");
        $db->exec("INSERT INTO " . TestHelper::table('models') . " VALUES (1, 'Model One', 0), (2, 'Model Two', 16)");
        $db->exec("INSERT INTO " . TestHelper::table('models_videos') . " VALUES (1, 2, 10), (2, 1, 10)");
        $db->exec("INSERT INTO " . TestHelper::table('playlists') . " VALUES (100, 'Editorial Picks')");
        $db->exec("INSERT INTO " . TestHelper::table('fav_videos') . " VALUES (10, 100), (30, 100)");
        $db->exec("INSERT INTO " . TestHelper::table('flags_videos') . " VALUES (30, 6, 3), (20, 6, 1)");
        $db->exec(
            "INSERT INTO " . TestHelper::table('videos_advanced_operations') .
            " VALUES (10, 1, 2), (20, 1, 0), (10, 2, 1), (30, 2, 2)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('admin_audit_log') .
            " VALUES (1, 10, 1, 100), (2, 20, 1, 140), (3, 30, 1, 110)"
        );

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
