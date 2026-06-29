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
        $this->assertSame('2', $output);
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
            'status_id INTEGER, is_private INTEGER, access_level_id INTEGER, tokens_required INTEGER, ' .
            'post_date TEXT, album_viewed INTEGER, rating REAL, rating_amount INTEGER, photos_amount INTEGER, ' .
            'favourites_count INTEGER, purchases_count INTEGER, content_source_id INTEGER, admin_flag_id INTEGER, ' .
            'server_group_id INTEGER, added_date TEXT, ip INTEGER)'
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
            'content_source_id INTEGER, title TEXT, status_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('flags') . ' (' .
            'flag_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_servers_groups') . ' (' .
            'group_id INTEGER, title TEXT, status_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('categories') . ' (category_id INTEGER, title TEXT)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_albums') .
            ' (id INTEGER, category_id INTEGER, album_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('tags') . ' (tag_id INTEGER, tag TEXT)');
        $db->exec('CREATE TABLE ' . TestHelper::table('tags_albums') . ' (id INTEGER, tag_id INTEGER, album_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('models') . ' (model_id INTEGER, title TEXT)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('models_albums') .
            ' (id INTEGER, model_id INTEGER, album_id INTEGER)'
        );

        $db->exec("INSERT INTO " . TestHelper::table('users') . " VALUES (1, 'alice', 1), (2, 'bob', 0)");
        $db->exec("INSERT INTO " . TestHelper::table('admin_users') . " VALUES (8, 'moderator', 0), (9, 'admin', 1)");
        $db->exec(
            "INSERT INTO " . TestHelper::table('albums') .
            ' (album_id, user_id, admin_user_id, title, dir, description, status_id, is_private, access_level_id, tokens_required, ' .
            'post_date, album_viewed, rating, ' .
            'rating_amount, photos_amount, favourites_count, purchases_count, content_source_id, admin_flag_id, ' .
            'server_group_id, added_date, ip) VALUES ' .
            "(10, 1, 9, 'Active Album', 'active-album', 'Active album description', 1, 0, 0, 0, " .
            "'2026-05-25 10:00:00', 12, 40, 10, 7, 5, 0, 0, 0, 0, '2026-05-25 09:00:00', 0), " .
            "(20, 2, 8, 'Disabled Album', 'disabled-album', 'Disabled album description', 0, 2, 2, 15, " .
            "'2026-05-26 10:00:00', 5, 10, 5, 3, 2, 1, 3, 4, 5, '2026-05-26 09:00:00', 2130706433)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('content_sources') .
            " VALUES (3, 'Gallery Studio', 1)"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('flags') .
            " VALUES (4, 'Album Review')"
        );
        $db->exec(
            "INSERT INTO " . TestHelper::table('admin_servers_groups') .
            " VALUES (5, 'Album Storage', 1)"
        );
        $db->exec("INSERT INTO " . TestHelper::table('albums_images') . " VALUES (10), (10), (20)");
        $db->exec(
            "INSERT INTO " . TestHelper::table('categories') .
            " VALUES (1, 'Album Category'), (2, 'Second Album Category')"
        );
        $db->exec("INSERT INTO " . TestHelper::table('categories_albums') . " VALUES (1, 2, 20), (2, 1, 20)");
        $db->exec("INSERT INTO " . TestHelper::table('tags') . " VALUES (1, 'album-tag'), (2, 'zeta-album')");
        $db->exec("INSERT INTO " . TestHelper::table('tags_albums') . " VALUES (1, 2, 20), (2, 1, 20)");
        $db->exec("INSERT INTO " . TestHelper::table('models') . " VALUES (1, 'Album Model'), (2, 'Album Model Two')");
        $db->exec("INSERT INTO " . TestHelper::table('models_albums') . " VALUES (1, 2, 20), (2, 1, 20)");
        $db->exec(
            'INSERT INTO ' . TestHelper::table('comments') .
            ' (comment_id, object_type_id, object_id) VALUES ' .
            '(1, 2, 10), (2, 2, 10), (3, 2, 20), (4, 1, 20)'
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
