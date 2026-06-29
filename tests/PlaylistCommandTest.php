<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\PlaylistCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PlaylistCommand::class)]
class PlaylistCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private PlaylistCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->db = $this->createDatabase();

        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->command = $this->createCommand($this->db);
        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testPlaylistListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 5,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Playlist', $output);
        $this->assertStringContainsString('Private Playlist', $output);
        $this->assertStringContainsString('Disabled Playlist', $output);
    }

    public function testPlaylistListWithStatusFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--format' => 'json',
            '--fields' => 'playlist_id,title,status',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(2, $rows);
        $this->assertSame([30, 20], array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows));
        $this->assertSame(['Active', 'Active'], array_column($rows, 'status'));
    }

    public function testPlaylistListWithUserFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--user' => 1,
            '--format' => 'json',
            '--fields' => 'playlist_id,title,user',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame([30, 20], array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows));
        $this->assertSame(['alice', 'alice'], array_column($rows, 'user'));
    }

    public function testPlaylistListFiltersUserByUsernameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--user' => 'alice',
            '--format' => 'json',
            '--fields' => 'playlist_id,title,user',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([30, 20], array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows));
        $this->assertSame(['alice', 'alice'], array_column($rows, 'user'));
    }

    public function testPlaylistListFiltersTagByNameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--tag' => 'practice',
            '--format' => 'json',
            '--fields' => 'playlist_id,tags',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([30], array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows));
        $this->assertSame('practice,training', $rows[0]['tags']);
    }

    public function testPlaylistListFiltersCategoryByTitleLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--category' => 'Archive',
            '--format' => 'json',
            '--fields' => 'playlist_id,categories',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([30], array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows));
        $this->assertSame('Archive,Featured', $rows[0]['categories']);
    }

    public function testPlaylistListFiltersByKvsAdminFieldFilter(): void
    {
        $cases = [
            'empty/description' => [10],
            'filled/description' => [30, 20],
            'empty/rating' => [10],
            'filled/rating' => [30, 20],
            'empty/tags' => [20, 10],
            'filled/tags' => [30],
            'empty/categories' => [20, 10],
            'filled/categories' => [30],
            'empty/videos' => [10],
            'filled/videos' => [30, 20],
        ];

        foreach ($cases as $filter => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--field-filter' => $filter,
                '--format' => 'json',
                '--fields' => 'playlist_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows), $filter);
        }
    }

    public function testPlaylistListFiltersByKvsAdminReviewAndLockedFlags(): void
    {
        $cases = [
            '--review-needed' => [20],
            '--not-review-needed' => [30, 10],
            '--locked' => [20],
            '--unlocked' => [30, 10],
        ];

        foreach ($cases as $option => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                $option => true,
                '--format' => 'json',
                '--fields' => 'playlist_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows), $option);
        }
    }

    public function testPlaylistListFiltersByKvsAdminFlagVotes(): void
    {
        $cases = [
            ['options' => ['--flag' => '7'], 'expected' => [30, 20]],
            ['options' => ['--flag' => '7', '--flag-votes' => '2'], 'expected' => [30]],
            ['options' => ['--flag' => '8'], 'expected' => [10]],
            ['options' => ['--flag' => '999'], 'expected' => []],
        ];

        foreach ($cases as $case) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case['options'],
                '--format' => 'json',
                '--fields' => 'playlist_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($case['expected'], array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows));
        }
    }

    public function testPlaylistListRejectsFlagVotesWithoutFlag(): void
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

    public function testPlaylistListPublicFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--public' => true,
            '--limit' => 5,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Playlist', $output);
        $this->assertStringContainsString('Disabled Playlist', $output);
        $this->assertStringNotContainsString('Private Playlist', $output);
    }

    public function testPlaylistListPrivateFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--private' => true,
            '--format' => 'json',
            '--fields' => 'playlist_id,title,type',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(20, (int) $rows[0]['playlist_id']);
        $this->assertSame('Private Playlist', $rows[0]['title']);
        $this->assertSame('Private', $rows[0]['type']);
    }

    public function testPlaylistListSearchFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'test',
            '--limit' => 5,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Playlist', $output);
        $this->assertStringNotContainsString('Private Playlist', $output);
        $this->assertStringNotContainsString('Disabled Playlist', $output);
    }

    public function testPlaylistListSearchesDirectoryLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'private-playlist',
            '--format' => 'json',
            '--fields' => 'playlist_id,dir',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([20], array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows));
        $this->assertSame('private-playlist', $rows[0]['dir']);
    }

    public function testPlaylistListSearchesIdLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '30',
            '--format' => 'json',
            '--fields' => 'playlist_id,title',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([30], array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows));
        $this->assertSame('Test Playlist', $rows[0]['title']);
    }

    public function testPlaylistDefaultsToListAction(): void
    {
        $this->tester->execute([
            '--format' => 'count',
        ]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('3', trim($this->tester->getDisplay()));
    }

    public function testPlaylistListJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json',
            '--fields' => 'playlist_id,title,videos',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(30, (int) $rows[0]['playlist_id']);
        $this->assertSame('Test Playlist', $rows[0]['title']);
        $this->assertSame(2, (int) $rows[0]['videos']);
    }

    public function testPlaylistListExposesKvsAdminCountFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json',
            '--fields' => 'playlist_id,title,videos_amount,comments_amount',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['playlist_id']);
        $this->assertSame('Test Playlist', $rows[0]['title']);
        $this->assertSame(2, (int) $rows[0]['videos_amount']);
        $this->assertSame(2, (int) $rows[0]['comments_amount']);
    }

    public function testPlaylistListExposesKvsAdminRawScalarAndUserStatusFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json',
            '--fields' => 'playlist_id,dir,description,user,user_status_id,status_id,is_private,last_content_date',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['playlist_id']);
        $this->assertSame('test-playlist', $rows[0]['dir']);
        $this->assertSame('A test playlist', $rows[0]['description']);
        $this->assertSame('alice', $rows[0]['user']);
        $this->assertSame(1, (int) $rows[0]['user_status_id']);
        $this->assertSame(1, (int) $rows[0]['status_id']);
        $this->assertSame(0, (int) $rows[0]['is_private']);
        $this->assertSame('2026-05-26 11:00:00', $rows[0]['last_content_date']);
    }

    public function testPlaylistListExposesKvsAdminRelationFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json',
            '--fields' => 'playlist_id,tags,categories',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['playlist_id']);
        $this->assertSame('practice,training', $rows[0]['tags']);
        $this->assertSame('Archive,Featured', $rows[0]['categories']);
    }

    public function testPlaylistListCsvFormat(): void
    {
        ob_start();
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'csv',
            '--fields' => 'playlist_id,title,status',
        ]);
        $csvOutput = ob_get_clean();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('playlist_id,title,status', $csvOutput);
        $this->assertStringContainsString('30,"Test Playlist",Active', $csvOutput);
    }

    public function testPlaylistListCountFormat(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('3', trim($this->tester->getDisplay()));

        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('2', trim($this->tester->getDisplay()));
    }

    public function testPlaylistListRejectsConflictingPrivacyFilters(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--public' => true,
            '--private' => true,
            '--format' => 'count',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('cannot be used together', $this->tester->getDisplay());
    }

    public function testPlaylistShow(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Playlist #30', $output);
        $this->assertStringContainsString('Test Playlist', $output);
        $this->assertStringContainsString('alice', $output);
        $this->assertMatchesRegularExpression('/Videos\W+2/', $output);
        $this->assertMatchesRegularExpression('/Views\W+100/', $output);
        $this->assertStringContainsString('4.0/5 (10 votes)', $output);
        $this->assertStringContainsString('A test playlist', $output);
        $this->assertStringContainsString('#100: Intro Video', $output);
        $this->assertStringContainsString('#101: Second Video', $output);
        $this->assertStringContainsString('Archive, Featured', $output);
        $this->assertStringContainsString('practice, training', $output);
    }

    public function testPlaylistShowSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('30', $rows[0]['playlist_id']);
        $this->assertSame('Test Playlist', $rows[0]['title']);
        $this->assertSame('A test playlist', $rows[0]['description']);
        $this->assertSame(['Archive', 'Featured'], $rows[0]['categories']);
        $this->assertSame(['practice', 'training'], $rows[0]['tags']);
        $this->assertSame('100', $rows[0]['videos_top'][0]['video_id']);
        $this->assertStringNotContainsString('Playlist #30', $output);
    }

    public function testPlaylistShowHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
            '--fields' => 'title',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Test Playlist', $output);
        $this->assertStringNotContainsString('Playlist #30', $output);
        $this->assertStringNotContainsString('Videos (Top 10)', $output);
    }

    public function testPlaylistShowRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Playlist ID', $this->tester->getDisplay());
    }

    public function testPlaylistShowNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => 999999,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Playlist not found: 999999', $output);
    }

    public function testPlaylistShowMissingId(): void
    {
        $this->tester->execute([
            'action' => 'show',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Playlist ID is required', $output);
    }

    public function testPlaylistDeleteMissingId(): void
    {
        $this->tester->execute([
            'action' => 'delete',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Playlist ID is required', $output);
    }

    public function testPlaylistDeleteNotFound(): void
    {
        $this->tester->execute([
            'action' => 'delete',
            'id' => 999999,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Playlist not found: 999999', $output);
    }

    public function testPlaylistAddMissingId(): void
    {
        $this->tester->execute([
            'action' => 'add',
            '--video' => 1,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Playlist ID is required', $output);
    }

    public function testPlaylistAddMissingVideoOption(): void
    {
        $this->tester->execute([
            'action' => 'add',
            'id' => 1,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required (use --video=<id>)', $output);
    }

    public function testPlaylistAddRejectsScientificNotationPlaylistId(): void
    {
        $this->tester->execute([
            'action' => 'add',
            'id' => '30e0',
            '--video' => 999999,
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Playlist ID', $this->tester->getDisplay());
    }

    public function testPlaylistAddRejectsDecimalVideoId(): void
    {
        $this->tester->execute([
            'action' => 'add',
            'id' => 30,
            '--video' => '999999.5',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Video ID', $this->tester->getDisplay());
    }

    public function testPlaylistAddPlaylistNotFound(): void
    {
        $this->tester->execute([
            'action' => 'add',
            'id' => 999999,
            '--video' => 1,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Playlist not found: 999999', $output);
    }

    public function testPlaylistAddVideoNotFound(): void
    {
        $this->tester->execute([
            'action' => 'add',
            'id' => 30,
            '--video' => 999999,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video not found: 999999', $output);
    }

    public function testPlaylistAddRecountsAllOwnerPlaylists(): void
    {
        $this->db->exec('UPDATE ' . TestHelper::table('playlists') . ' SET total_videos = 99 WHERE playlist_id = 20');

        $this->tester->execute([
            'action' => 'add',
            'id' => 30,
            '--video' => 102,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame(
            3,
            (int) $this->db->query(
                'SELECT total_videos FROM ' . TestHelper::table('playlists') . ' WHERE playlist_id = 30'
            )->fetchColumn()
        );
        $this->assertSame(
            1,
            (int) $this->db->query(
                'SELECT total_videos FROM ' . TestHelper::table('playlists') . ' WHERE playlist_id = 20'
            )->fetchColumn()
        );
        $this->assertSame(
            2,
            (int) $this->db->query(
                'SELECT favourites_count FROM ' . TestHelper::table('videos') . ' WHERE video_id = 102'
            )->fetchColumn()
        );
        $this->assertSame(
            4,
            (int) $this->db->query(
                'SELECT favourite_videos_count FROM ' . TestHelper::table('users') . ' WHERE user_id = 1'
            )->fetchColumn()
        );
    }

    public function testPlaylistAddExistingVideoRecountsLikeKvsAdmin(): void
    {
        $this->db->exec('UPDATE ' . TestHelper::table('playlists') . ' SET total_videos = 99 WHERE playlist_id = 30');

        $this->tester->execute([
            'action' => 'add',
            'id' => 30,
            '--video' => 100,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('already in playlist', $this->tester->getDisplay());
        $this->assertSame(
            2,
            (int) $this->db->query(
                'SELECT total_videos FROM ' . TestHelper::table('playlists') . ' WHERE playlist_id = 30'
            )->fetchColumn()
        );
        $this->assertSame(
            1,
            (int) $this->db->query(
                'SELECT COUNT(*) FROM ' . TestHelper::table('fav_videos') .
                ' WHERE playlist_id = 30 AND video_id = 100'
            )->fetchColumn()
        );
    }

    public function testPlaylistRemoveMissingId(): void
    {
        $this->tester->execute([
            'action' => 'remove',
            '--video' => 1,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Playlist ID is required', $output);
    }

    public function testPlaylistRemoveMissingVideoOption(): void
    {
        $this->tester->execute([
            'action' => 'remove',
            'id' => 1,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required (use --video=<id>)', $output);
    }

    public function testPlaylistRemoveRejectsScientificNotationPlaylistId(): void
    {
        $this->tester->execute([
            'action' => 'remove',
            'id' => '30e0',
            '--video' => 999999,
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Playlist ID', $this->tester->getDisplay());
    }

    public function testPlaylistRemoveRejectsScientificNotationVideoId(): void
    {
        $this->tester->execute([
            'action' => 'remove',
            'id' => 30,
            '--video' => '999999e0',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Video ID', $this->tester->getDisplay());
    }

    public function testPlaylistRemovePlaylistNotFound(): void
    {
        $this->tester->execute([
            'action' => 'remove',
            'id' => 999999,
            '--video' => 1,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Playlist not found: 999999', $output);
    }

    public function testPlaylistRemoveVideoNotFound(): void
    {
        $this->tester->execute([
            'action' => 'remove',
            'id' => 30,
            '--video' => 999999,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video not found: 999999', $output);
    }

    public function testPlaylistRemoveMissingRelationRecountsLikeKvsAdmin(): void
    {
        $this->db->exec('UPDATE ' . TestHelper::table('playlists') . ' SET total_videos = 99 WHERE playlist_id = 30');

        $this->tester->execute([
            'action' => 'remove',
            'id' => 30,
            '--video' => 102,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('not in playlist', $this->tester->getDisplay());
        $this->assertSame(
            2,
            (int) $this->db->query(
                'SELECT total_videos FROM ' . TestHelper::table('playlists') . ' WHERE playlist_id = 30'
            )->fetchColumn()
        );
        $this->assertSame(
            0,
            (int) $this->db->query(
                'SELECT COUNT(*) FROM ' . TestHelper::table('fav_videos') .
                ' WHERE playlist_id = 30 AND video_id = 102'
            )->fetchColumn()
        );
    }

    public function testPlaylistRemoveDeletesStaleVideoRelationLikeKvsAdmin(): void
    {
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('fav_videos') .
            ' (user_id, video_id, fav_type, playlist_id, playlist_sort_id, added_date) VALUES ' .
            "(1, 999, 10, 30, 3, '2026-05-26 10:30:00')"
        );

        $this->tester->execute([
            'action' => 'remove',
            'id' => 30,
            '--video' => 999,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->db->query(
                'SELECT COUNT(*) FROM ' . TestHelper::table('fav_videos') . ' WHERE playlist_id = 30 AND video_id = 999'
            )->fetchColumn()
        );
    }

    public function testPlaylistCommandMetadata(): void
    {
        $this->assertEquals('content:playlist', $this->command->getName());
        $this->assertStringContainsString('playlist', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('playlist', $aliases);
        $this->assertContains('playlists', $aliases);
    }

    public function testPlaylistDefaultAction(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Playlist', $output);
        $this->assertStringContainsString('Total: 3 results', $output);
        $this->assertStringNotContainsString('Available actions:', $output);
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('users') . ' (' .
            'user_id INTEGER, username TEXT, status_id INTEGER, favourite_videos_count INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('playlists') . ' (' .
            'playlist_id INTEGER, user_id INTEGER, title TEXT, description TEXT, dir TEXT, ' .
            'status_id INTEGER, is_private INTEGER, is_locked INTEGER, is_review_needed INTEGER, ' .
            'rating INTEGER, rating_amount INTEGER, playlist_viewed INTEGER, comments_count INTEGER, ' .
            'subscribers_count INTEGER, added_date TEXT, last_content_date TEXT, total_videos INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('fav_videos') . ' (' .
            'user_id INTEGER, video_id INTEGER, fav_type INTEGER, playlist_id INTEGER, ' .
            'playlist_sort_id INTEGER, added_date TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('comments') . ' (' .
            'comment_id INTEGER, object_type_id INTEGER, object_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('videos') . ' (' .
            'video_id INTEGER, title TEXT, favourites_count INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories') . ' (' .
            'category_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_playlists') . ' (' .
            'id INTEGER, category_id INTEGER, playlist_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('tags') . ' (' .
            'tag_id INTEGER, tag TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('tags_playlists') . ' (' .
            'id INTEGER, tag_id INTEGER, playlist_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('flags_playlists') . ' (' .
            'playlist_id INTEGER, flag_id INTEGER, votes INTEGER)'
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('users') .
            " (user_id, username, status_id, favourite_videos_count) VALUES (1, 'alice', 1, 2), (2, 'bob', 0, 0)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('playlists') .
            ' (playlist_id, user_id, title, description, dir, status_id, is_private, is_locked, ' .
            'is_review_needed, rating, rating_amount, playlist_viewed, comments_count, subscribers_count, ' .
            'added_date, last_content_date, total_videos) VALUES ' .
            "(30, 1, 'Test Playlist', 'A test playlist', 'test-playlist', 1, 0, 0, 0, " .
            "40, 10, 100, 2, 3, '2026-05-26 10:00:00', '2026-05-26 11:00:00', 2), " .
            "(20, 1, 'Private Playlist', 'Private collection', 'private-playlist', 1, 1, 1, 1, " .
            "10, 5, 20, 0, 1, '2026-05-25 10:00:00', '2026-05-25 11:00:00', 1), " .
            "(10, 2, 'Disabled Playlist', '', 'disabled-playlist', 0, 0, 0, 0, " .
            "0, 1, 5, 0, 0, '2026-05-24 10:00:00', '2026-05-24 11:00:00', 0)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('videos') .
            " (video_id, title, favourites_count) VALUES " .
            "(100, 'Intro Video', 1), (101, 'Second Video', 1), (102, 'Private Video', 1)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('fav_videos') .
            ' (user_id, video_id, fav_type, playlist_id, playlist_sort_id, added_date) VALUES ' .
            "(1, 100, 10, 30, 1, '2026-05-26 10:10:00'), " .
            "(1, 101, 10, 30, 2, '2026-05-26 10:20:00'), " .
            "(1, 102, 10, 20, 1, '2026-05-25 10:10:00')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('comments') .
            ' (comment_id, object_type_id, object_id) VALUES ' .
            '(1, 13, 30), (2, 13, 30), (3, 1, 30), (4, 13, 20)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories') .
            " (category_id, title) VALUES (1, 'Featured'), (2, 'Archive')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories_playlists') .
            ' (id, category_id, playlist_id) VALUES (1, 2, 30), (2, 1, 30)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('tags') .
            " (tag_id, tag) VALUES (1, 'training'), (2, 'practice')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('tags_playlists') .
            ' (id, tag_id, playlist_id) VALUES (1, 2, 30), (2, 1, 30)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('flags_playlists') .
            ' (playlist_id, flag_id, votes) VALUES (30, 7, 3), (20, 7, 1), (10, 8, 5)'
        );

        return $db;
    }

    private function createCommand(PDO $db): PlaylistCommand
    {
        return new class ($this->config, $db) extends PlaylistCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:playlist');
                $this->setDescription('Manage KVS playlists');
                $this->setAliases(['playlist', 'playlists']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
