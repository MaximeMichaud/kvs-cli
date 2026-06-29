<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testUserListExposesKvsPremiumDaysLeftMessage(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'premium',
            '--format' => 'json',
            '--fields' => 'user_id,status_id,days_left_message',
            '--limit' => 10,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['user_id']);
        $this->assertSame(3, (int) $rows[0]['status_id']);
        $this->assertSame('30 days, trial', $rows[0]['days_left_message']);
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
        $this->assertSame('127.0.0.1', $rows[0]['ip']);
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

    public function testUntrustedFilterReturnsOnlyUntrustedUsersLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--untrusted' => true,
            '--format' => 'json',
            '--fields' => 'user_id,username,is_trusted',
            '--limit' => 10,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([3, 2], array_map(static fn (array $row): int => (int) $row['user_id'], $rows));
        $this->assertSame([0, 0], array_map(static fn (array $row): int => (int) $row['is_trusted'], $rows));
    }

    public function testUserListSearchesDisplayNameLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Alice Example',
            '--format' => 'json',
            '--fields' => 'user_id,display_name',
            '--limit' => 10,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertSame('Alice Example', $rows[0]['display_name']);
    }

    public function testUserListSearchesIdLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '2',
            '--format' => 'json',
            '--fields' => 'user_id,username',
            '--limit' => 10,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([2], array_map(static fn (array $row): int => (int) $row['user_id'], $rows));
        $this->assertSame('remove_me', $rows[0]['username']);
    }

    public function testUserListSearchesAdditionalFieldsLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'synthetic account',
            '--format' => 'json',
            '--fields' => 'user_id,username,about_me',
            '--limit' => 10,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['user_id']);
        $this->assertSame('remove_me', $rows[0]['username']);
        $this->assertSame('Synthetic account used for admin search.', $rows[0]['about_me']);
    }

    public function testUserListRejectsRawPasswordHashField(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'user_id,username,pass,temp_pass,last_session_id_hash',
            '--limit' => 1,
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown field(s): pass, temp_pass', $this->tester->getDisplay());
        $this->assertStringContainsString('last_session_id_hash', $this->tester->getDisplay());
    }

    public function testUserListFiltersByIpLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--ip' => '127.0.0.1',
            '--format' => 'json',
            '--fields' => 'user_id,username,ip',
            '--limit' => 10,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertSame('alice', $rows[0]['username']);
        $this->assertSame('127.0.0.1', $rows[0]['ip']);
    }

    public function testUserListFiltersByActivityLikeKvsAdmin(): void
    {
        $loginTester = new CommandTester($this->command);
        $loginTester->execute([
            'action' => 'list',
            '--activity' => 'have/logins',
            '--format' => 'ids',
            '--limit' => 10,
        ]);

        $videoTester = new CommandTester($this->command);
        $videoTester->execute([
            'action' => 'list',
            '--activity' => 'have/videos',
            '--format' => 'ids',
            '--limit' => 10,
        ]);

        $friendsTester = new CommandTester($this->command);
        $friendsTester->execute([
            'action' => 'list',
            '--activity' => 'no/friends',
            '--format' => 'ids',
            '--limit' => 10,
        ]);

        $this->assertEquals(0, $loginTester->getStatusCode(), $loginTester->getDisplay());
        $this->assertSame('3 1', trim($loginTester->getDisplay()));
        $this->assertEquals(0, $videoTester->getStatusCode(), $videoTester->getDisplay());
        $this->assertSame('2 1', trim($videoTester->getDisplay()));
        $this->assertEquals(0, $friendsTester->getStatusCode(), $friendsTester->getDisplay());
        $this->assertSame('3 2', trim($friendsTester->getDisplay()));
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int> $expectedIds
     */
    #[DataProvider('provideKvsAdminUserScalarFilters')]
    public function testUserListFiltersByKvsAdminScalarFilters(array $options, array $expectedIds): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'ids',
            '--limit' => 10,
            ...$options,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(implode(' ', $expectedIds), trim($this->tester->getDisplay()));
    }

    /**
     * @return iterable<string, array{options: array<string, mixed>, expectedIds: list<int>}>
     */
    public static function provideKvsAdminUserScalarFilters(): iterable
    {
        yield 'country title' => ['options' => ['--country' => 'Canada'], 'expectedIds' => [1]];
        yield 'country id' => ['options' => ['--country' => '2'], 'expectedIds' => [3]];
        yield 'missing country title' => ['options' => ['--country' => 'Missing Country'], 'expectedIds' => []];
        yield 'gender male' => ['options' => ['--gender' => 'male'], 'expectedIds' => [3]];
        yield 'gender female' => ['options' => ['--gender' => 'female'], 'expectedIds' => [1]];
        yield 'gender id' => ['options' => ['--gender' => '2'], 'expectedIds' => [1]];
        yield 'temporary ban' => ['options' => ['--banned-status' => 'temporary'], 'expectedIds' => [2]];
        yield 'permanent ban' => ['options' => ['--banned-status' => 'permanent'], 'expectedIds' => [3]];
        yield 'temporary ban id' => ['options' => ['--banned-status' => '1'], 'expectedIds' => [2]];
        yield 'permanent ban id' => ['options' => ['--banned-status' => '2'], 'expectedIds' => [3]];
    }

    /**
     * @param list<int> $expectedIds
     */
    #[DataProvider('provideKvsAdminUserFieldFilters')]
    public function testUserListFiltersByKvsAdminFieldFilter(string $fieldFilter, array $expectedIds): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--field-filter' => $fieldFilter,
            '--format' => 'ids',
            '--limit' => 10,
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(implode(' ', $expectedIds), trim($this->tester->getDisplay()));
    }

    /**
     * @return iterable<string, array{fieldFilter: string, expectedIds: list<int>}>
     */
    public static function provideKvsAdminUserFieldFilters(): iterable
    {
        yield 'filled description' => ['fieldFilter' => 'filled/description', 'expectedIds' => [1]];
        yield 'empty description' => ['fieldFilter' => 'empty/description', 'expectedIds' => [3, 2]];
        yield 'filled avatar' => ['fieldFilter' => 'filled/avatar', 'expectedIds' => [1]];
        yield 'empty avatar' => ['fieldFilter' => 'empty/avatar', 'expectedIds' => [3, 2]];
        yield 'filled about me' => ['fieldFilter' => 'filled/about_me', 'expectedIds' => [2]];
        yield 'empty about me' => ['fieldFilter' => 'empty/about_me', 'expectedIds' => [3, 1]];
        yield 'filled custom1' => ['fieldFilter' => 'filled/custom1', 'expectedIds' => [1]];
        yield 'empty custom1' => ['fieldFilter' => 'empty/custom1', 'expectedIds' => [3, 2]];
        yield 'filled country' => ['fieldFilter' => 'filled/country_id', 'expectedIds' => [3, 1]];
        yield 'empty country' => ['fieldFilter' => 'empty/country_id', 'expectedIds' => [2]];
        yield 'filled gender' => ['fieldFilter' => 'filled/gender_id', 'expectedIds' => [3, 1]];
        yield 'empty gender' => ['fieldFilter' => 'empty/gender_id', 'expectedIds' => [2]];
        yield 'filled relationship status' => ['fieldFilter' => 'filled/relationship_status_id', 'expectedIds' => [1]];
        yield 'empty relationship status' => ['fieldFilter' => 'empty/relationship_status_id', 'expectedIds' => [3, 2]];
        yield 'filled orientation' => ['fieldFilter' => 'filled/orientation_id', 'expectedIds' => [1]];
        yield 'empty orientation' => ['fieldFilter' => 'empty/orientation_id', 'expectedIds' => [3, 2]];
        yield 'filled profile viewed' => ['fieldFilter' => 'filled/profile_viewed', 'expectedIds' => [3, 1]];
        yield 'empty profile viewed' => ['fieldFilter' => 'empty/profile_viewed', 'expectedIds' => [2]];
        yield 'filled tokens required' => ['fieldFilter' => 'filled/tokens_required', 'expectedIds' => [1]];
        yield 'empty tokens required' => ['fieldFilter' => 'empty/tokens_required', 'expectedIds' => [3, 2]];
        yield 'filled birth date' => ['fieldFilter' => 'filled/birth_date', 'expectedIds' => [3, 1]];
        yield 'empty birth date' => ['fieldFilter' => 'empty/birth_date', 'expectedIds' => [2]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('provideInvalidKvsAdminUserFilters')]
    public function testUserListRejectsInvalidKvsAdminFilters(array $options, string $message): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'count',
            ...$options,
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString($message, $this->tester->getDisplay());
    }

    /**
     * @return iterable<string, array{options: array<string, mixed>, message: string}>
     */
    public static function provideInvalidKvsAdminUserFilters(): iterable
    {
        yield 'invalid country' => [
            'options' => ['--country' => '-1'],
            'message' => 'Invalid value for --country',
        ];
        yield 'invalid gender' => [
            'options' => ['--gender' => 'unknown'],
            'message' => 'Invalid value for --gender',
        ];
        yield 'invalid banned status' => [
            'options' => ['--banned-status' => 'unknown'],
            'message' => 'Invalid value for --banned-status',
        ];
        yield 'invalid field filter' => [
            'options' => ['--field-filter' => 'filled/unknown'],
            'message' => 'Invalid user field filter',
        ];
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
        $this->assertSame(1, (int) ($rowsByMetric['Inactive Users']['value'] ?? 0));
        $this->assertArrayNotHasKey('Disabled Users', $rowsByMetric);
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
            'pass TEXT, temp_pass TEXT, last_session_id_hash TEXT, description TEXT, gender_id INTEGER, ' .
            'country_id INTEGER, city TEXT, birth_date TEXT, ip TEXT, ' .
            'added_date TEXT, ' .
            'last_login_date TEXT, profile_viewed INTEGER, logins_count INTEGER, activity INTEGER, ' .
            'tokens_available INTEGER, ' .
            'tokens_required INTEGER, total_videos_count INTEGER, total_albums_count INTEGER, is_trusted INTEGER, ' .
            'is_removal_requested INTEGER, removal_reason TEXT, favourite_category_id INTEGER, avatar TEXT, ' .
            'cover TEXT, ' .
            'is_trial INTEGER, friends_count INTEGER, website TEXT, education TEXT, occupation TEXT, ' .
            'relationship_status_id INTEGER, orientation_id INTEGER, about_me TEXT, interests TEXT, ' .
            'favourite_movies TEXT, favourite_music TEXT, favourite_books TEXT, custom1 TEXT, custom2 TEXT, ' .
            'custom3 TEXT, custom4 TEXT, custom5 TEXT, custom6 TEXT, custom7 TEXT, custom8 TEXT, custom9 TEXT, ' .
            'custom10 TEXT, login_protection_is_banned INTEGER, login_protection_restore_code INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('bill_transactions') . ' (' .
            'transaction_id INTEGER, user_id INTEGER, status_id INTEGER, access_end_date TEXT, ' .
            'duration_rebill INTEGER, is_unlimited_access INTEGER)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('videos') . ' (user_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('albums') . ' (user_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('posts') . ' (user_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('dvds') . ' (user_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('playlists') . ' (user_id INTEGER, is_private INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('comments') . ' (user_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('categories') . ' (category_id INTEGER, title TEXT)');
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('list_countries') .
            ' (country_id INTEGER, country_code TEXT, language_code TEXT, title TEXT)'
        );

        $userColumns = [
            'user_id',
            'username',
            'display_name',
            'email',
            'status_id',
            'pass',
            'temp_pass',
            'last_session_id_hash',
            'description',
            'gender_id',
            'country_id',
            'city',
            'birth_date',
            'ip',
            'added_date',
            'last_login_date',
            'profile_viewed',
            'logins_count',
            'activity',
            'tokens_available',
            'tokens_required',
            'total_videos_count',
            'total_albums_count',
            'is_trusted',
            'is_removal_requested',
            'removal_reason',
            'favourite_category_id',
            'avatar',
            'cover',
            'is_trial',
            'friends_count',
            'website',
            'education',
            'occupation',
            'relationship_status_id',
            'orientation_id',
            'about_me',
            'interests',
            'favourite_movies',
            'favourite_music',
            'favourite_books',
            'custom1',
            'custom2',
            'custom3',
            'custom4',
            'custom5',
            'custom6',
            'custom7',
            'custom8',
            'custom9',
            'custom10',
            'login_protection_is_banned',
            'login_protection_restore_code',
        ];
        $userDefaults = array_fill_keys($userColumns, '');
        foreach (
            [
            'user_id',
            'status_id',
            'gender_id',
            'country_id',
            'profile_viewed',
            'logins_count',
            'activity',
            'tokens_available',
            'tokens_required',
            'total_videos_count',
            'total_albums_count',
            'is_trusted',
            'is_removal_requested',
            'favourite_category_id',
            'is_trial',
            'friends_count',
            'relationship_status_id',
            'orientation_id',
            'login_protection_is_banned',
            'login_protection_restore_code',
            ] as $column
        ) {
            $userDefaults[$column] = 0;
        }
        $userDefaults['birth_date'] = '0000-00-00';
        $userDefaults['last_login_date'] = '0000-00-00 00:00:00';

        $insertUser = $db->prepare(
            'INSERT INTO ' . TestHelper::table('users') .
            ' (' . implode(', ', $userColumns) . ') VALUES (:' . implode(', :', $userColumns) . ')'
        );
        foreach (
            [
            [
                'user_id' => 1,
                'username' => 'alice',
                'display_name' => 'Alice Example',
                'email' => 'alice@example.com',
                'status_id' => 2,
                'pass' => '$2a$07$hiddenfixturehashhiddenfixturehashhiddenfixturehash12',
                'temp_pass' => 'temporary-password-token',
                'last_session_id_hash' => 'hidden-session-hash',
                'description' => 'Alice profile',
                'gender_id' => 2,
                'country_id' => 1,
                'city' => 'Montreal',
                'birth_date' => '1990-01-02',
                'ip' => 2130706433,
                'added_date' => '2026-05-26 10:00:00',
                'last_login_date' => '2026-05-26 11:00:00',
                'profile_viewed' => 1000,
                'logins_count' => 4,
                'activity' => 42,
                'tokens_available' => 50,
                'tokens_required' => 10,
                'total_videos_count' => 2,
                'total_albums_count' => 1,
                'is_trusted' => 1,
                'favourite_category_id' => 10,
                'avatar' => '1/1.jpg',
                'friends_count' => 2,
                'relationship_status_id' => 1,
                'orientation_id' => 2,
                'custom1' => 'vip',
            ],
            [
                'user_id' => 2,
                'username' => 'remove_me',
                'display_name' => 'Remove Me',
                'email' => 'remove@example.com',
                'status_id' => 0,
                'added_date' => '2026-05-25 10:00:00',
                'is_removal_requested' => 1,
                'removal_reason' => 'Delete my account',
                'about_me' => 'Synthetic account used for admin search.',
                'login_protection_is_banned' => 1,
                'login_protection_restore_code' => 12345,
            ],
            [
                'user_id' => 3,
                'username' => 'premium',
                'display_name' => 'Premium User',
                'email' => 'premium@example.com',
                'status_id' => 3,
                'gender_id' => 1,
                'country_id' => 2,
                'birth_date' => '1985-03-04',
                'ip' => '127.0.0.2',
                'added_date' => '2026-05-24 10:00:00',
                'last_login_date' => '2026-05-24 12:00:00',
                'profile_viewed' => 50,
                'logins_count' => 2,
                'activity' => 10,
                'tokens_available' => 100,
                'is_trial' => 1,
                'login_protection_is_banned' => 1,
            ],
            ] as $user
        ) {
            $insertUser->execute(array_replace($userDefaults, $user));
        }
        $db->exec(
            'INSERT INTO ' . TestHelper::table('bill_transactions') .
            " VALUES (1, 3, 4, '2026-06-24 12:00:00', 30, 0)"
        );
        $db->exec('INSERT INTO ' . TestHelper::table('videos') . ' VALUES (1), (1), (2)');
        $db->exec('INSERT INTO ' . TestHelper::table('albums') . ' VALUES (1)');
        $db->exec('INSERT INTO ' . TestHelper::table('posts') . ' VALUES (1), (1), (2)');
        $db->exec('INSERT INTO ' . TestHelper::table('dvds') . ' VALUES (1)');
        $db->exec('INSERT INTO ' . TestHelper::table('playlists') . ' VALUES (1, 0), (1, 1), (2, 0)');
        $db->exec('INSERT INTO ' . TestHelper::table('comments') . ' VALUES (1), (1), (1), (2)');
        $db->exec("INSERT INTO " . TestHelper::table('categories') . " VALUES (10, 'Featured')");
        $db->exec(
            "INSERT INTO " . TestHelper::table('list_countries') .
            " VALUES (1, 'CA', 'en', 'Canada'), (2, 'US', 'en', 'United States')"
        );

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
