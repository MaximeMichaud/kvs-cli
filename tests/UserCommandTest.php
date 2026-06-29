<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class UserCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private UserCommand $command;
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

    public function testUserListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 2
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('User id', $output);
        $this->assertStringContainsString('Username', $output);
        $this->assertStringContainsString('premium', $output);
        $this->assertStringContainsString('remove_me', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testUserListWithRemovalRequested(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--removal-requested' => true,
            '--format' => 'json',
            '--fields' => 'user_id,username,email,removal_reason',
            '--limit' => 10
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['user_id']);
        $this->assertSame('remove_me', $rows[0]['username']);
        $this->assertSame('Delete my account', $rows[0]['removal_reason']);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testUserListFormats(): void
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
        $this->assertSame(3, (int) $jsonRows[0]['user_id']);
        $this->assertSame('premium', $jsonRows[0]['username']);
        $this->assertEquals(0, $testerJson->getStatusCode());

        // Test CSV format - CSV writes to php://output so we capture it with ob_start
        $testerCsv = new CommandTester($this->command);
        ob_start();
        $testerCsv->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'csv'
        ]);
        $csvOutput = ob_get_clean();

        $this->assertStringContainsString('user_id', $csvOutput);
        $this->assertStringContainsString('premium', $csvOutput);
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

    public function testUserShow(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '1'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('User: alice', $output);
        $this->assertStringContainsString('User ID', $output);
        $this->assertStringContainsString('Username', $output);
        $this->assertStringContainsString('alice@example.com', $output);
        $this->assertStringContainsString('Content Statistics', $output);
        $this->assertMatchesRegularExpression('/Videos Uploaded\W+2/', $output);
        $this->assertMatchesRegularExpression('/Albums Created\W+1/', $output);
        $this->assertMatchesRegularExpression('/Comments Posted\W+3/', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testUserShowSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '1',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('1', $rows[0]['user_id']);
        $this->assertSame('alice', $rows[0]['username']);
        $this->assertSame(2, $rows[0]['videos_uploaded']);
        $this->assertSame(1, $rows[0]['albums_created']);
        $this->assertSame(3, $rows[0]['comments_posted']);
        $this->assertStringNotContainsString('User: alice', $output);
    }

    public function testUserShowByUsernameStillWorks(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => 'alice',
            '--format' => 'json',
            '--fields' => 'user_id,username',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('1', $rows[0]['user_id']);
        $this->assertSame('alice', $rows[0]['username']);
    }

    public function testUserShowDoesNotCoerceNumericPrefixUsernameToId(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '1abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('User not found: 1abc', $this->tester->getDisplay());
    }

    public function testUserDeleteDoesNotCoerceNumericPrefixUsernameToId(): void
    {
        $this->tester->execute([
            'action' => 'delete',
            'id' => '1abc',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('User not found: 1abc', $this->tester->getDisplay());
        $this->assertStringNotContainsString('This will delete user', $this->tester->getDisplay());
    }

    public function testTrustedFilterReturnsOnlyTrustedUsers(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--trusted' => true,
            '--format' => 'json',
            '--fields' => 'user_id,username,is_trusted',
            '--limit' => 10
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertSame('alice', $rows[0]['username']);
        $this->assertSame(1, (int) $rows[0]['is_trusted']);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testUserListExposesKvsAdminCountFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'alice',
            '--fields' => 'user_id,username,videos_count,albums_count,posts_count,dvds_count,' .
                'playlists_count,public_playlists_count,comments_count,favourite_category',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertSame('alice', $rows[0]['username']);
        $this->assertSame(2, (int) $rows[0]['videos_count']);
        $this->assertSame(1, (int) $rows[0]['albums_count']);
        $this->assertSame(2, (int) $rows[0]['posts_count']);
        $this->assertSame(1, (int) $rows[0]['dvds_count']);
        $this->assertSame(2, (int) $rows[0]['playlists_count']);
        $this->assertSame(1, (int) $rows[0]['public_playlists_count']);
        $this->assertSame(3, (int) $rows[0]['comments_count']);
        $this->assertSame('Featured', $rows[0]['favourite_category']);
    }

    public function testUserListExposesKvsAdminThumbAndFormattedIp(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'alice',
            '--fields' => 'user_id,thumb,ip',
            '--format' => 'json',
            '--limit' => 1,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertSame('1/1.jpg', $rows[0]['thumb']);
        $this->assertSame('127.0.0.1', $rows[0]['ip']);
    }

    public function testUserStatsSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'stats',
            '--format' => 'json',
            '--fields' => 'section,metric,value,label',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsByMetric = array_column($rows, null, 'metric');

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('overall', $rowsByMetric['Total Users']['section'] ?? null);
        $this->assertSame(3, (int) ($rowsByMetric['Total Users']['value'] ?? 0));
        $this->assertStringNotContainsString('Most Recent Users', $this->tester->getDisplay());
    }

    public function testCommandMetadata(): void
    {
        $this->assertEquals('content:user', $this->command->getName());
        $this->assertStringContainsString('user', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('user', $aliases);
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('users') . ' (' .
            'user_id INTEGER, username TEXT, display_name TEXT, email TEXT, status_id INTEGER, ' .
            'gender_id INTEGER, country_id TEXT, birth_date TEXT, ip TEXT, added_date TEXT, last_login_date TEXT, ' .
            'profile_viewed INTEGER, logins_count INTEGER, activity INTEGER, tokens_available INTEGER, ' .
            'tokens_required INTEGER, total_videos_count INTEGER, total_albums_count INTEGER, is_trusted INTEGER, ' .
            'is_removal_requested INTEGER, removal_reason TEXT, favourite_category_id INTEGER, avatar TEXT)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('videos') . ' (user_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('albums') . ' (user_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('posts') . ' (user_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('dvds') . ' (user_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('playlists') . ' (user_id INTEGER, is_private INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('comments') . ' (user_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('categories') . ' (category_id INTEGER, title TEXT)');

        $db->exec(
            'INSERT INTO ' . TestHelper::table('users') .
            " (user_id, username, display_name, email, status_id, gender_id, country_id, birth_date, ip, " .
            "added_date, last_login_date, profile_viewed, logins_count, activity, tokens_available, tokens_required, " .
            "total_videos_count, total_albums_count, is_trusted, is_removal_requested, removal_reason, " .
            "favourite_category_id, avatar) VALUES " .
            "(1, 'alice', 'Alice Example', 'alice@example.com', 2, 2, 'CA', '1990-01-02', 2130706433, " .
            "'2026-05-26 10:00:00', '2026-05-26 11:00:00', 1000, 4, 42, 50, 10, 2, 1, 1, 0, '', 10, '1/1.jpg'), " .
            "(2, 'remove_me', 'Remove Me', 'remove@example.com', 0, 0, '', '0000-00-00', '', " .
            "'2026-05-25 10:00:00', '0000-00-00 00:00:00', 0, 0, 0, 0, 0, 0, 0, 0, 1, " .
            "'Delete my account', 0, ''), " .
            "(3, 'premium', 'Premium User', 'premium@example.com', 3, 1, 'US', '1985-03-04', '127.0.0.2', " .
            "'2026-05-24 10:00:00', '2026-05-24 12:00:00', 50, 2, 10, 100, 0, 0, 0, 0, 0, '', 0, '')"
        );
        $db->exec('INSERT INTO ' . TestHelper::table('videos') . ' VALUES (1), (1), (2)');
        $db->exec('INSERT INTO ' . TestHelper::table('albums') . ' VALUES (1)');
        $db->exec('INSERT INTO ' . TestHelper::table('posts') . ' VALUES (1), (1), (2)');
        $db->exec('INSERT INTO ' . TestHelper::table('dvds') . ' VALUES (1)');
        $db->exec('INSERT INTO ' . TestHelper::table('playlists') . ' VALUES (1, 0), (1, 1), (2, 0)');
        $db->exec('INSERT INTO ' . TestHelper::table('comments') . ' VALUES (1), (1), (1), (2)');
        $db->exec("INSERT INTO " . TestHelper::table('categories') . " VALUES (10, 'Featured')");

        return $db;
    }

    private function createCommand(PDO $db): UserCommand
    {
        return new class ($this->config, $db) extends UserCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:user');
                $this->setDescription('Manage KVS users');
                $this->setAliases(['user', 'users', 'member', 'members']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
