<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\AlbumCommand;
use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Command\Content\CommentCommand;
use KVS\CLI\Command\Content\DvdCommand;
use KVS\CLI\Command\Content\ModelCommand;
use KVS\CLI\Command\Content\PlaylistCommand;
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
        $this->tempDir = TestHelper::createTempDir('kvs-content-output-regression-');
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
            'duration INTEGER, file_size INTEGER, favourites_count INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'author')");
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(20, 1, 1, 'Daily news', '2024-01-02 00:00:00', 15, 4, 16, 2, 90, 5050, 7)"
        );

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,title,filesize,duration,rating,resolution,favourites',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(20, (int) $rows[0]['id']);
        $this->assertSame('4.93 KB', $rows[0]['filesize']);
        $this->assertSame('1:30', $rows[0]['duration']);
        $this->assertSame('4.0/5 (4 votes)', $rows[0]['rating']);
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

    public function testVideoListDefaultFieldsExposeFormattedStatus(): void
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
            "(20, 1, 1, 'Active news', '2024-01-02 00:00:00', 15, 4, 16, 2, 0)"
        );

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('Active', $rows[0]['status']);
        $this->assertArrayNotHasKey('status_id', $rows[0]);
    }

    public function testVideoListSupportsSingleFieldAndIdsFormat(): void
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
            "(20, 1, 1, 'First video', '2024-01-01 00:00:00', 15, 4, 16, 2, 0), " .
            "(21, 1, 1, 'Second video', '2024-01-02 00:00:00', 20, 5, 20, 2, 0)"
        );

        $fieldTester = new CommandTester($this->createVideoCommand($db));
        $fieldTester->execute([
            'action' => 'list',
            '--field' => 'title',
            '--limit' => '2',
        ]);

        $idsTester = new CommandTester($this->createVideoCommand($db));
        $idsTester->execute([
            'action' => 'list',
            '--format' => 'ids',
            '--limit' => '2',
        ]);

        $countTester = new CommandTester($this->createVideoCommand($db));
        $countTester->execute([
            'action' => 'list',
            '--format' => 'count',
            '--limit' => '1',
        ]);

        $this->assertSame(0, $fieldTester->getStatusCode());
        $this->assertSame("Second video\nFirst video", trim($fieldTester->getDisplay()));
        $this->assertSame(0, $idsTester->getStatusCode());
        $this->assertSame('21 20', trim($idsTester->getDisplay()));
        $this->assertSame(0, $countTester->getStatusCode());
        $this->assertSame('2', trim($countTester->getDisplay()));
    }

    public function testVideoListRejectsInvalidLimit(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, user_id INTEGER, status_id INTEGER, title TEXT, post_date TEXT, video_viewed INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute([
            'action' => 'list',
            '--limit' => 'abc',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --limit', $tester->getDisplay());
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

    public function testVideoListAndStatsClampRatingToScale(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, user_id INTEGER, status_id INTEGER, title TEXT, post_date TEXT, ' .
            'video_viewed INTEGER, rating_amount INTEGER, rating REAL, resolution_type INTEGER, ' .
            'is_private INTEGER, duration INTEGER, file_size INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'author')");
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(20, 1, 1, 'Imported rating', '2024-01-02 00:00:00', 15, 5, 70, 2, 0, 120, 5050)"
        );

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,rating',
            '--format' => 'json',
            '--limit' => '1',
        ]);
        $rows = $this->decodeJsonRows($tester->getDisplay());

        $statsTester = new CommandTester($this->createVideoCommand($db));
        $statsTester->execute(['action' => 'stats']);
        $statsOutput = $statsTester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('5.0/5 (5 votes)', $rows[0]['rating']);
        $this->assertSame(0, $statsTester->getStatusCode());
        $this->assertStringContainsString('5.0/5', $statsOutput);
        $this->assertStringNotContainsString('14.0/5', $statsOutput);
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
        $db->exec('CREATE TABLE ktvs_categories_videos (id INTEGER, category_id INTEGER, video_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags (tag_id INTEGER, tag TEXT)');
        $db->exec('CREATE TABLE ktvs_tags_videos (id INTEGER, tag_id INTEGER, video_id INTEGER)');
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

    public function testVideoShowDisplaysPremiumPrivacyAsTypeLabel(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, title TEXT, status_id INTEGER, resolution_type INTEGER, is_private INTEGER, ' .
            'duration INTEGER, file_size INTEGER, file_dimensions TEXT, post_date TEXT, rating REAL, ' .
            'rating_amount INTEGER, video_viewed INTEGER, favourites_count INTEGER, description TEXT)'
        );
        $db->exec('CREATE TABLE ktvs_categories (category_id INTEGER, title TEXT)');
        $db->exec('CREATE TABLE ktvs_categories_videos (id INTEGER, category_id INTEGER, video_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags (tag_id INTEGER, tag TEXT)');
        $db->exec('CREATE TABLE ktvs_tags_videos (id INTEGER, tag_id INTEGER, video_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(20, 'Premium news', 1, 2, 2, 120, 5050, '1280x720', '2024-01-02 00:00:00', 20, 5, 15, 7, '')"
        );

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute(['action' => 'show', 'id' => '20']);
        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/Type\W+Premium/', $output);
        $this->assertMatchesRegularExpression('/Access\W+From access type/', $output);
        $this->assertStringNotContainsString('Private    │ Yes', $output);
    }

    public function testAlbumListExposesFormattedPrivacyField(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_albums (' .
            'album_id INTEGER, user_id INTEGER, title TEXT, status_id INTEGER, is_private INTEGER, ' .
            'post_date TEXT, album_viewed INTEGER, rating REAL, rating_amount INTEGER, photos_amount INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_albums_images (album_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (object_type_id INTEGER, object_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'author')");
        $db->exec(
            "INSERT INTO ktvs_albums VALUES " .
            "(4, 1, 'Weekend Outdoor Set', 1, 2, '2024-01-02 00:00:00', 15, 20, 5, 0)"
        );

        $tester = new CommandTester($this->createAlbumCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,title,is_private',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(4, (int) $rows[0]['id']);
        $this->assertSame('Premium', $rows[0]['is_private']);

        $defaultTester = new CommandTester($this->createAlbumCommand($db));
        $defaultTester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--limit' => '1',
        ]);
        $defaultRows = $this->decodeJsonRows($defaultTester->getDisplay());

        $this->assertSame(0, $defaultTester->getStatusCode());
        $this->assertSame('Active', $defaultRows[0]['status']);
        $this->assertArrayNotHasKey('status_id', $defaultRows[0]);
        $this->assertSame('Premium', $defaultRows[0]['is_private']);
    }

    public function testAlbumListRejectsUnknownRequestedField(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_albums (' .
            'album_id INTEGER, user_id INTEGER, title TEXT, status_id INTEGER, is_private INTEGER, ' .
            'post_date TEXT, album_viewed INTEGER, rating REAL, rating_amount INTEGER, photos_amount INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_albums_images (album_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (object_type_id INTEGER, object_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'author')");
        $db->exec(
            "INSERT INTO ktvs_albums VALUES " .
            "(4, 1, 'Weekend Outdoor Set', 1, 0, '2024-01-02 00:00:00', 15, 20, 5, 0)"
        );

        $tester = new CommandTester($this->createAlbumCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'nonexistent_field',
            '--limit' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown field(s): nonexistent_field', $tester->getDisplay());
    }

    public function testAlbumListSearchSupportsSingleFieldAndIdsFormat(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_albums (' .
            'album_id INTEGER, user_id INTEGER, title TEXT, dir TEXT, description TEXT, status_id INTEGER, ' .
            'is_private INTEGER, post_date TEXT, album_viewed INTEGER, rating REAL, rating_amount INTEGER, ' .
            'photos_amount INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_albums_images (album_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (object_type_id INTEGER, object_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'author')");
        $db->exec(
            "INSERT INTO ktvs_albums VALUES " .
            "(4, 1, 'Outdoor Set', 'outdoor-set', 'Outdoor album description', 1, 0, " .
            "'2024-01-02 00:00:00', 15, 20, 5, 0), " .
            "(5, 1, 'Indoor Set', 'indoor-set', 'Indoor album description', 1, 0, " .
            "'2024-01-03 00:00:00', 20, 20, 5, 0)"
        );

        $searchTester = new CommandTester($this->createAlbumCommand($db));
        $searchTester->execute([
            'action' => 'list',
            '--search' => 'Outdoor',
            '--fields' => 'id,title',
            '--format' => 'json',
            '--limit' => '10',
        ]);
        $rows = $this->decodeJsonRows($searchTester->getDisplay());

        $fieldTester = new CommandTester($this->createAlbumCommand($db));
        $fieldTester->execute([
            'action' => 'list',
            '--field' => 'title',
            '--limit' => '2',
        ]);

        $idsTester = new CommandTester($this->createAlbumCommand($db));
        $idsTester->execute([
            'action' => 'list',
            '--format' => 'ids',
            '--limit' => '2',
        ]);

        $this->assertSame(0, $searchTester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(4, (int) $rows[0]['id']);
        $this->assertSame('Outdoor Set', $rows[0]['title']);
        $this->assertSame(0, $fieldTester->getStatusCode());
        $this->assertSame("Indoor Set\nOutdoor Set", trim($fieldTester->getDisplay()));
        $this->assertSame(0, $idsTester->getStatusCode());
        $this->assertSame('5 4', trim($idsTester->getDisplay()));
    }

    public function testAlbumListAndShowClampRatingToScale(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_albums (' .
            'album_id INTEGER, user_id INTEGER, title TEXT, status_id INTEGER, is_private INTEGER, ' .
            'post_date TEXT, album_viewed INTEGER, rating REAL, rating_amount INTEGER, photos_amount INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_albums_images (album_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (object_type_id INTEGER, object_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'author')");
        $db->exec(
            "INSERT INTO ktvs_albums VALUES " .
            "(4, 1, 'Imported rating', 1, 0, '2024-01-02 00:00:00', 15, 70, 4, 0)"
        );

        $tester = new CommandTester($this->createAlbumCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,rating',
            '--format' => 'json',
            '--limit' => '1',
        ]);
        $rows = $this->decodeJsonRows($tester->getDisplay());

        $showTester = new CommandTester($this->createAlbumCommand($db));
        $showTester->execute(['action' => 'show', 'id' => '4']);
        $showOutput = $showTester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(5.0, (float) $rows[0]['rating']);
        $this->assertSame(0, $showTester->getStatusCode());
        $this->assertStringContainsString('5.0/5 (4 votes)', $showOutput);
        $this->assertStringNotContainsString('17.5/5', $showOutput);
    }

    public function testModelListDefaultFieldsExposeFormattedStatus(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_models (' .
            'model_id INTEGER, title TEXT, status_id INTEGER, rating REAL, rating_amount INTEGER, ' .
            'model_viewed INTEGER, country TEXT, birth_date TEXT, measurements TEXT, ' .
            'height TEXT, weight TEXT, rank INTEGER, total_videos INTEGER, total_albums INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_models_videos (model_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_models_albums (model_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_list_countries (country_code TEXT, language_code TEXT, title TEXT)');
        $db->exec("INSERT INTO ktvs_models VALUES (7, 'Lina Moreau', 1, 70, 5, 100, '', '', '', '', '', 0, 0, 0)");

        $tester = new CommandTester($this->createModelCommand($db));
        $tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('Active', $rows[0]['status']);
        $this->assertArrayNotHasKey('status_id', $rows[0]);

        $ratingTester = new CommandTester($this->createModelCommand($db));
        $ratingTester->execute([
            'action' => 'list',
            '--fields' => 'id,rating',
            '--format' => 'json',
            '--limit' => '1',
        ]);
        $ratingRows = $this->decodeJsonRows($ratingTester->getDisplay());

        $this->assertSame(0, $ratingTester->getStatusCode());
        $this->assertSame('5.0/5 (5 votes)', $ratingRows[0]['rating']);
    }

    public function testDvdListDefaultFieldsExposeFormattedStatus(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_dvds (' .
            'dvd_id INTEGER, title TEXT, status_id INTEGER, rating REAL, rating_amount INTEGER, ' .
            'dvd_viewed INTEGER, release_year INTEGER, subscribers_count INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_videos (dvd_id INTEGER, duration INTEGER)');
        $db->exec("INSERT INTO ktvs_dvds VALUES (4, 'Fitness Basics', 1, 70, 5, 100, 2026, 0)");
        $db->exec('INSERT INTO ktvs_videos VALUES (4, 4260)');

        $tester = new CommandTester($this->createDvdCommand($db));
        $tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('Active', $rows[0]['status']);
        $this->assertArrayNotHasKey('status_id', $rows[0]);

        $durationTester = new CommandTester($this->createDvdCommand($db));
        $durationTester->execute([
            'action' => 'list',
            '--fields' => 'id,duration',
            '--format' => 'json',
            '--limit' => '1',
        ]);
        $durationRows = $this->decodeJsonRows($durationTester->getDisplay());

        $this->assertSame(0, $durationTester->getStatusCode());
        $this->assertSame('1:11:00', $durationRows[0]['duration']);

        $ratingTester = new CommandTester($this->createDvdCommand($db));
        $ratingTester->execute([
            'action' => 'list',
            '--fields' => 'id,rating',
            '--format' => 'json',
            '--limit' => '1',
        ]);
        $ratingRows = $this->decodeJsonRows($ratingTester->getDisplay());

        $this->assertSame(0, $ratingTester->getStatusCode());
        $this->assertSame('5.0/5 (5 votes)', $ratingRows[0]['rating']);
    }

    public function testDvdRejectsUnknownAction(): void
    {
        $tester = new CommandTester($this->createDvdCommand($this->createSqliteConnection()));
        $tester->execute([
            'action' => 'invalid-action',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown DVD action "invalid-action"', $tester->getDisplay());
        $this->assertStringContainsString('Available actions: list, show', $tester->getDisplay());
    }

    public function testContentCommandsRejectUnknownActions(): void
    {
        $db = $this->createSqliteConnection();
        $commands = [
            'video' => ['video', $this->createVideoCommand($db)],
            'album' => ['album', $this->createAlbumCommand($db)],
            'playlist' => ['playlist', $this->createPlaylistCommand($db)],
            'user' => ['user', $this->createUserCommand($db)],
            'comment' => ['comment', $this->createCommentCommand($db)],
            'model' => ['model', $this->createModelCommand($db)],
            'tag' => ['tag', $this->createTagCommand($db)],
            'category' => ['category', $this->createCategoryCommand($db)],
            'dvd' => ['DVD', $this->createDvdCommand($db)],
        ];

        foreach ($commands as $commandName => [$messageLabel, $command]) {
            $tester = new CommandTester($command);
            $tester->execute(['action' => 'bogus']);

            $this->assertSame(1, $tester->getStatusCode(), "$commandName should fail on unknown action");
            $this->assertStringContainsString(
                sprintf('Unknown %s action "bogus"', $messageLabel),
                $tester->getDisplay()
            );
        }
    }

    public function testPlaylistListDefaultFieldsExposeFormattedStatusAndType(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_playlists (' .
            'playlist_id INTEGER, user_id INTEGER, title TEXT, status_id INTEGER, is_private INTEGER, ' .
            'rating REAL, rating_amount INTEGER, playlist_viewed INTEGER, added_date TEXT, description TEXT)'
        );
        $db->exec('CREATE TABLE ktvs_fav_videos (playlist_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (object_type_id INTEGER, object_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'author')");
        $db->exec(
            "INSERT INTO ktvs_playlists VALUES " .
            "(3, 1, 'Best Tutorials', 1, 0, 70, 5, 100, '2024-01-02 00:00:00', '')"
        );

        $tester = new CommandTester($this->createPlaylistCommand($db));
        $tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('Active', $rows[0]['status']);
        $this->assertSame('Public', $rows[0]['type']);
        $this->assertArrayNotHasKey('status_id', $rows[0]);
        $this->assertArrayNotHasKey('is_private', $rows[0]);

        $ratingTester = new CommandTester($this->createPlaylistCommand($db));
        $ratingTester->execute([
            'action' => 'list',
            '--fields' => 'id,rating',
            '--format' => 'json',
            '--limit' => '1',
        ]);
        $ratingRows = $this->decodeJsonRows($ratingTester->getDisplay());

        $this->assertSame(0, $ratingTester->getStatusCode());
        $this->assertSame(5.0, (float) $ratingRows[0]['rating']);
    }

    public function testPlaylistListSupportsSingleFieldAndIdsFormat(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_playlists (' .
            'playlist_id INTEGER, user_id INTEGER, title TEXT, status_id INTEGER, is_private INTEGER, ' .
            'rating REAL, rating_amount INTEGER, playlist_viewed INTEGER, added_date TEXT, description TEXT)'
        );
        $db->exec('CREATE TABLE ktvs_fav_videos (playlist_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (object_type_id INTEGER, object_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users VALUES (1, 'author')");
        $db->exec(
            "INSERT INTO ktvs_playlists VALUES " .
            "(3, 1, 'Tutorial Picks', 1, 0, 70, 5, 100, '2024-01-02 00:00:00', ''), " .
            "(4, 1, 'Lecture Picks', 1, 0, 70, 5, 120, '2024-01-03 00:00:00', '')"
        );

        $fieldTester = new CommandTester($this->createPlaylistCommand($db));
        $fieldTester->execute([
            'action' => 'list',
            '--field' => 'title',
            '--limit' => '2',
        ]);

        $idsTester = new CommandTester($this->createPlaylistCommand($db));
        $idsTester->execute([
            'action' => 'list',
            '--format' => 'ids',
            '--limit' => '2',
        ]);

        $this->assertSame(0, $fieldTester->getStatusCode());
        $this->assertSame("Lecture Picks\nTutorial Picks", trim($fieldTester->getDisplay()));
        $this->assertSame(0, $idsTester->getStatusCode());
        $this->assertSame('4 3', trim($idsTester->getDisplay()));
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
            'user_id INTEGER, username TEXT, display_name TEXT, email TEXT, status_id INTEGER, added_date TEXT, ' .
            'total_videos_count INTEGER, total_albums_count INTEGER)'
        );
        $db->exec(
            "INSERT INTO ktvs_users VALUES " .
            "(1, 'member', 'Member', 'member@example.com', 2, '2024-01-01 00:00:00', 0, 0), " .
            "(2, 'anonymous', 'Anonymous', 'anonymous@example.com', 4, '2024-01-02 00:00:00', 0, 0)"
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

    public function testUserListUsesKvsAdminContentCounts(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_users (' .
            'user_id INTEGER, username TEXT, display_name TEXT, email TEXT, status_id INTEGER, added_date TEXT, ' .
            'total_videos_count INTEGER, total_albums_count INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_videos (user_id INTEGER, status_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums (user_id INTEGER, status_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_users VALUES " .
            "(1, 'admin', 'Admin', 'admin@example.com', 2, '2024-01-01 00:00:00', 0, 0)"
        );
        $db->exec('INSERT INTO ktvs_videos VALUES (1, 0), (1, 0), (1, 0)');
        $db->exec('INSERT INTO ktvs_albums VALUES (1, 0), (1, 0)');

        $tester = new CommandTester($this->createUserCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,username,videos,albums',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(1, (int) $rows[0]['id']);
        $this->assertSame(3, (int) $rows[0]['videos']);
        $this->assertSame(2, (int) $rows[0]['albums']);
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
        $this->createCategoryRelationTables($db);
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
        $db->exec(
            "INSERT INTO ktvs_tags (tag_id, tag, tag_dir, status_id, added_date, " .
            "total_content_sources, total_playlists, total_models, total_dvds, total_dvd_groups) " .
            "VALUES (34, 'review', 'review', 1, '2024-01-01 00:00:00', 0, 0, 0, 0, 0)"
        );
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

    public function testTagListExposesFormattedStatus(): void
    {
        $db = $this->createSqliteConnection();
        $this->createTagTables($db);
        $db->exec(
            "INSERT INTO ktvs_tags (tag_id, tag, tag_dir, status_id, added_date, " .
            "total_content_sources, total_playlists, total_models, total_dvds, total_dvd_groups) " .
            "VALUES (39, 'advanced', 'advanced', 1, '2024-01-01 00:00:00', 0, 0, 0, 0, 0)"
        );

        $tester = new CommandTester($this->createTagCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'id,tag,status',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(39, (int) $rows[0]['id']);
        $this->assertSame('Active', $rows[0]['status']);
    }

    public function testTagListUsesKvsAdminDefaultOrdering(): void
    {
        $db = $this->createSqliteConnection();
        $this->createTagTables($db);
        $db->exec(
            "INSERT INTO ktvs_tags (tag_id, tag, tag_dir, status_id, added_date, " .
            "total_content_sources, total_playlists, total_models, total_dvds, total_dvd_groups) VALUES " .
            "(39, 'advanced', 'advanced', 1, '2024-01-01 00:00:00', 0, 0, 0, 0, 0), " .
            "(62, 'audio', 'audio', 1, '2024-01-01 00:00:00', 0, 0, 0, 0, 0)"
        );

        $tester = new CommandTester($this->createTagCommand($db));
        $tester->execute([
            'action' => 'list',
            '--fields' => 'tag_id,tag',
            '--format' => 'json',
            '--limit' => '2',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([62, 39], array_map(static fn (array $row): int => (int) $row['tag_id'], $rows));
    }

    public function testTagStatsCountsTagsUsedOutsideVideosAndAlbums(): void
    {
        $db = $this->createSqliteConnection();
        $this->createTagTables($db);
        $db->exec(
            "INSERT INTO ktvs_tags VALUES " .
            "(34, 'review', 'review', 1, '2024-01-01 00:00:00', 0, 0, 0, 0, 0), " .
            "(35, 'post only', 'post-only', 1, '2024-01-01 00:00:00', 0, 0, 0, 0, 0)"
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
            '(tag_id INTEGER, tag TEXT, tag_dir TEXT, status_id INTEGER, added_date TEXT, ' .
            'total_content_sources INTEGER, total_playlists INTEGER, total_models INTEGER, ' .
            'total_dvds INTEGER, total_dvd_groups INTEGER)'
        );
        $tagRelations = ['videos', 'albums', 'posts', 'playlists', 'content_sources', 'models', 'dvds', 'dvds_groups'];
        foreach ($tagRelations as $suffix) {
            $db->exec("CREATE TABLE ktvs_tags_{$suffix} (tag_id INTEGER)");
        }
    }

    private function createCategoryRelationTables(\PDO $db): void
    {
        foreach (['videos', 'albums', 'posts', 'playlists', 'content_sources', 'models', 'dvds', 'dvds_groups'] as $suffix) {
            $db->exec("CREATE TABLE ktvs_categories_{$suffix} (category_id INTEGER)");
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

    private function createAlbumCommand(\PDO $db): AlbumCommand
    {
        return new class ($this->createConfig(), $db) extends AlbumCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:album');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createModelCommand(\PDO $db): ModelCommand
    {
        return new class ($this->createConfig(), $db) extends ModelCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:model');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createDvdCommand(\PDO $db): DvdCommand
    {
        return new class ($this->createConfig(), $db) extends DvdCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:dvd');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createPlaylistCommand(\PDO $db): PlaylistCommand
    {
        return new class ($this->createConfig(), $db) extends PlaylistCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:playlist');
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
