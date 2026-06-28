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
            '--fields' => 'album_id,title,content_source,admin_flag,server_group',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(20, (int) $rows[0]['album_id']);
        $this->assertSame('Disabled Album', $rows[0]['title']);
        $this->assertSame('Gallery Studio', $rows[0]['content_source']);
        $this->assertSame('Album Review', $rows[0]['admin_flag']);
        $this->assertSame('Album Storage', $rows[0]['server_group']);
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
        $this->assertMatchesRegularExpression('/Access\W+Public/', $output);
        $this->assertMatchesRegularExpression('/User\W+alice/', $output);
        $this->assertMatchesRegularExpression('/Images\W+7/', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
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
            'album_id INTEGER, user_id INTEGER, title TEXT, status_id INTEGER, is_private INTEGER, ' .
            'post_date TEXT, album_viewed INTEGER, rating REAL, rating_amount INTEGER, photos_amount INTEGER, ' .
            'favourites_count INTEGER, purchases_count INTEGER, content_source_id INTEGER, admin_flag_id INTEGER, ' .
            'server_group_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('albums_images') . ' (album_id INTEGER)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('comments') .
            ' (comment_id INTEGER, object_type_id INTEGER, object_id INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('users') . ' (user_id INTEGER, username TEXT)');
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

        $db->exec("INSERT INTO " . TestHelper::table('users') . " VALUES (1, 'alice'), (2, 'bob')");
        $db->exec(
            "INSERT INTO " . TestHelper::table('albums') .
            ' (album_id, user_id, title, status_id, is_private, post_date, album_viewed, rating, ' .
            'rating_amount, photos_amount, favourites_count, purchases_count, content_source_id, admin_flag_id, ' .
            'server_group_id) VALUES ' .
            "(10, 1, 'Active Album', 1, 0, '2026-05-25 10:00:00', 12, 40, 10, 7, 5, 0, 0, 0, 0), " .
            "(20, 2, 'Disabled Album', 0, 2, '2026-05-26 10:00:00', 5, 10, 5, 3, 2, 1, 3, 4, 5)"
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
