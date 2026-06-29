<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\DvdCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(DvdCommand::class)]
class DvdCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private DvdCommand $command;
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

    public function testListDvdsDefault(): void
    {
        $this->tester->execute(['action' => 'list']);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Series', $this->tester->getDisplay());
        $this->assertStringContainsString('Disabled Series', $this->tester->getDisplay());
    }

    public function testListDvdsWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 5
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Other Channel', $this->tester->getDisplay());
    }

    public function testListDvdsActiveStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,status'
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(2, $rows);
        $this->assertSame([30, 10], array_map(static fn (array $row): int => (int) $row['dvd_id'], $rows));
        $this->assertSame('Active', $rows[0]['status']);
    }

    public function testListDvdsCountFormatIgnoresPaginationButAppliesFilters(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'count',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('3', trim($this->tester->getDisplay()));

        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--format' => 'count',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('2', trim($this->tester->getDisplay()));
    }

    public function testListDvdsAggregatesRelationCountsWithoutNativeTotals(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,videos,total_videos_duration,duration',
            '--limit' => '1',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('Test Series', $rows[0]['title']);
        $this->assertSame(2, (int) $rows[0]['videos']);
        $this->assertSame(3690, (int) $rows[0]['total_videos_duration']);
        $this->assertSame('1:01:30', $rows[0]['duration']);
    }

    public function testListDvdsExposesKvsAdminVideoAmountAndDurationFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,videos_amount,total_duration',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('Test Series', $rows[0]['title']);
        $this->assertSame(2, (int) $rows[0]['videos_amount']);
        $this->assertSame('1:01:30', $rows[0]['total_duration']);
    }

    public function testListDvdsExposesKvsAdminSubscribersAmountField(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,subscribers_amount',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('Test Series', $rows[0]['title']);
        $this->assertSame(5, (int) $rows[0]['subscribers_amount']);
    }

    public function testListDvdsExposesKvsAdminCommentsAmountField(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,comments_amount',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('Test Series', $rows[0]['title']);
        $this->assertSame(2, (int) $rows[0]['comments_amount']);
    }

    public function testListDvdsExposesKvsAdminGroupFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,dvd_group,dvd_group_status_id',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('Test Series', $rows[0]['title']);
        $this->assertSame('Featured Series', $rows[0]['dvd_group']);
        $this->assertSame(1, (int) $rows[0]['dvd_group_status_id']);
    }

    public function testListDvdsExposesKvsAdminMediaUserAndRelationFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,thumb,cover1_front,cover1_back,cover2_front,cover2_back,user,' .
                'is_video_upload_allowed,tags,categories,models,avg_videos_rating,avg_videos_popularity',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('front-1.jpg', $rows[0]['thumb']);
        $this->assertSame('front-1.jpg', $rows[0]['cover1_front']);
        $this->assertSame('back-1.jpg', $rows[0]['cover1_back']);
        $this->assertSame('front-2.jpg', $rows[0]['cover2_front']);
        $this->assertSame('back-2.jpg', $rows[0]['cover2_back']);
        $this->assertSame('channel-owner', $rows[0]['user']);
        $this->assertSame(2, (int) $rows[0]['is_video_upload_allowed']);
        $this->assertSame('featured,series', $rows[0]['tags']);
        $this->assertSame('Channels,Featured', $rows[0]['categories']);
        $this->assertSame('Model One,Model Two', $rows[0]['models']);
        $this->assertSame(4.5, (float) $rows[0]['avg_videos_rating']);
        $this->assertSame(1200, (int) $rows[0]['avg_videos_popularity']);
    }

    public function testListDvdsExposesKvsAdminRawScalarFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,dir,description,synonyms,tokens_required,added_date,sort_id',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('test-series', $rows[0]['dir']);
        $this->assertSame('Long running series', $rows[0]['description']);
        $this->assertSame('series, channel', $rows[0]['synonyms']);
        $this->assertSame(25, (int) $rows[0]['tokens_required']);
        $this->assertSame('2026-05-25 10:00:00', $rows[0]['added_date']);
        $this->assertSame(9, (int) $rows[0]['sort_id']);
    }

    public function testListDvdsFormatsZeroReleaseYearLikeKvsAdmin(): void
    {
        $this->db->exec('UPDATE ' . TestHelper::table('dvds') . ' SET release_year = 0 WHERE dvd_id = 10');

        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,release_year',
            '--limit' => '3',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'dvd_id');

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('-', $rowsById[10]['release_year']);
    }

    public function testListDvdsDisabledStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'disabled'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Disabled Series', $output);
        $this->assertStringNotContainsString('Test Series', $output);
    }

    public function testListDvdsSearch(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'test'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Series', $output);
        $this->assertStringNotContainsString('Disabled Series', $output);
    }

    #[DataProvider('provideOutputFormats')]
    public function testListDvdsFormats(string $format): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => $format,
            '--limit' => 3
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        if ($format === 'json') {
            $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(3, $rows);
            $this->assertSame(30, (int) $rows[0]['dvd_id']);
            $this->assertSame('Test Series', $rows[0]['title']);
            return;
        }

        $this->assertStringContainsString('Test Series', $this->tester->getDisplay());
    }

    public static function provideOutputFormats(): array
    {
        return [
            'table format' => ['table'],
            'json format' => ['json'],
        ];
    }

    public function testShowDvd(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD: Test Series', $output);
        $this->assertStringContainsString('DVD ID', $output);
        $this->assertMatchesRegularExpression('/Videos\W+2/', $output);
        $this->assertMatchesRegularExpression('/Total Duration\W+1:01:30/', $output);
        $this->assertStringContainsString('4.0/5 (10 votes)', $output);
        $this->assertStringContainsString('Long running series', $output);
    }

    public function testShowDvdOmitsZeroReleaseYearLikeKvsAdmin(): void
    {
        $this->db->exec('UPDATE ' . TestHelper::table('dvds') . ' SET release_year = 0 WHERE dvd_id = 10');

        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD: Other Channel', $output);
        $this->assertStringNotContainsString('Release Year', $output);
    }

    public function testShowDvdNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD not found: 999999999', $output);
    }

    public function testShowDvdMissingId(): void
    {
        $this->tester->execute([
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD ID is required', $output);
    }

    public function testStats(): void
    {
        $this->tester->execute(['action' => 'stats']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD Statistics', $output);
        $this->assertMatchesRegularExpression('/Total DVDs\W+3/', $output);
        $this->assertMatchesRegularExpression('/Active\W+2/', $output);
        $this->assertMatchesRegularExpression('/Disabled\W+1/', $output);
    }

    public function testDefaultActionIsList(): void
    {
        $this->tester->execute([]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Series', $this->tester->getDisplay());
    }

    public function testInvalidActionReturnsFailure(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown DVD action "invalid"', $this->tester->getDisplay());
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('dvds') . ' (' .
            'dvd_id INTEGER, title TEXT, dir TEXT, status_id INTEGER, release_year INTEGER, dvd_viewed INTEGER, ' .
            'cover1_front TEXT, cover1_back TEXT, cover2_front TEXT, cover2_back TEXT, user_id INTEGER, ' .
            'is_video_upload_allowed INTEGER, ' .
            'subscribers_count INTEGER, rating INTEGER, rating_amount INTEGER, description TEXT, ' .
            'synonyms TEXT, tokens_required INTEGER, added_date TEXT, sort_id INTEGER, ' .
            'total_videos INTEGER, total_videos_duration INTEGER, avg_videos_rating REAL, ' .
            'avg_videos_popularity INTEGER, dvd_group_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('dvds_groups') . ' (' .
            'dvd_group_id INTEGER, title TEXT, status_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('users') . ' (' .
            'user_id INTEGER, username TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('tags') . ' (' .
            'tag_id INTEGER, tag TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('tags_dvds') . ' (' .
            'tag_id INTEGER, dvd_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories') . ' (' .
            'category_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_dvds') . ' (' .
            'category_id INTEGER, dvd_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('models') . ' (' .
            'model_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('models_dvds') . ' (' .
            'model_id INTEGER, dvd_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('videos') . ' (' .
            'dvd_id INTEGER, duration INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('comments') . ' (' .
            'object_type_id INTEGER, object_id INTEGER)'
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('dvds') .
            ' (dvd_id, title, dir, status_id, release_year, dvd_viewed, cover1_front, cover1_back, ' .
            'cover2_front, cover2_back, user_id, is_video_upload_allowed, subscribers_count, rating, rating_amount, ' .
            'description, synonyms, tokens_required, added_date, sort_id, total_videos, total_videos_duration, ' .
            'avg_videos_rating, avg_videos_popularity, dvd_group_id) VALUES ' .
            "(30, 'Test Series', 'test-series', 1, 2026, 100, 'front-1.jpg', 'back-1.jpg', " .
            "'front-2.jpg', 'back-2.jpg', 1, 2, 5, 40, 10, 'Long running series', " .
            "'series, channel', 25, '2026-05-25 10:00:00', 9, 99, 99, 4.5, 1200, 7), " .
            "(20, 'Disabled Series', 'disabled-series', 0, 2025, 10, '', '', '', '', 2, 0, 0, 0, 0, '', '', 0, " .
            "'2026-05-26 10:00:00', 10, 99, 99, 0, 0, 8), " .
            "(10, 'Other Channel', 'other-channel', 1, 2024, 50, '', '', '', '', 0, 0, 2, 12, 3, '', '', 0, " .
            "'2026-05-27 10:00:00', 11, 99, 99, 0, 0, 0)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('dvds_groups') .
            " VALUES (7, 'Featured Series', 1), (8, 'Archived Series', 0)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('users') .
            " VALUES (1, 'channel-owner'), (2, 'disabled-owner')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('tags') .
            " VALUES (1, 'featured'), (2, 'series')"
        );
        $db->exec('INSERT INTO ' . TestHelper::table('tags_dvds') . ' VALUES (1, 30), (2, 30)');
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories') .
            " VALUES (1, 'Channels'), (2, 'Featured')"
        );
        $db->exec('INSERT INTO ' . TestHelper::table('categories_dvds') . ' VALUES (1, 30), (2, 30)');
        $db->exec(
            'INSERT INTO ' . TestHelper::table('models') .
            " VALUES (1, 'Model One'), (2, 'Model Two')"
        );
        $db->exec('INSERT INTO ' . TestHelper::table('models_dvds') . ' VALUES (1, 30), (2, 30)');
        $db->exec('INSERT INTO ' . TestHelper::table('videos') . ' VALUES (30, 3600), (30, 90), (20, 120), (10, 300)');
        $db->exec(
            'INSERT INTO ' . TestHelper::table('comments') .
            ' VALUES (5, 30), (5, 30), (5, 20), (1, 30)'
        );

        return $db;
    }

    private function createCommand(PDO $db): DvdCommand
    {
        return new class ($this->config, $db) extends DvdCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:dvd');
                $this->setDescription('Manage KVS DVDs (channels/series)');
                $this->setAliases(['dvd', 'dvds', 'channel', 'channels']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
