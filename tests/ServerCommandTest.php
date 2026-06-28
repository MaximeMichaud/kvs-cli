<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\ServerCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;

class ServerCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private ServerCommand $command;
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

    public function testServerListBasic(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video Local', $output);
        $this->assertStringContainsString('Video Disabled', $output);
        $this->assertStringContainsString('Album Error', $output);
    }

    public function testServerListWithStatusFilter(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--status' => 'active',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,title,status'
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(2, $rows);
        $this->assertSame([1, 3], array_map(static fn (array $row): int => (int) $row['server_id'], $rows));
        $this->assertSame(['Active', 'Active'], array_column($rows, 'status'));
    }

    public function testServerListWithTypeFilter(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--type' => 'video',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,title'
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], array_map(static fn (array $row): int => (int) $row['server_id'], $rows));
    }

    public function testServerListWithConnectionFilter(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--connection' => 'local',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,title,connection'
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['server_id']);
        $this->assertSame('Local', $rows[0]['connection']);
    }

    public function testServerListWithConnectionFiltersForRemoteTypes(): void
    {
        $this->insertS3StorageServer();

        $cases = [
            ['mount', 2, 'Mount'],
            ['ftp', 3, 'FTP'],
            ['s3', 4, 'S3'],
        ];

        foreach ($cases as [$connection, $serverId, $label]) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => 'list',
                '--connection' => $connection,
                '--limit' => 10,
                '--format' => 'json',
                '--fields' => 'server_id,title,connection',
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertEquals(0, $tester->getStatusCode());
            $this->assertCount(1, $rows);
            $this->assertSame($serverId, (int) $rows[0]['server_id']);
            $this->assertSame($label, $rows[0]['connection']);
        }
    }

    public function testServerListWithGroupFilterIsolatesGroups(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--group' => '20',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,title,group_title',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['server_id']);
        $this->assertSame('Album Error', $rows[0]['title']);
        $this->assertSame('Album Group', $rows[0]['group_title']);
    }

    public function testServerListWithErrorsFilter(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--errors' => true,
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,title,has_error'
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['server_id']);
        $this->assertSame('Album Error', $rows[0]['title']);
        $this->assertSame('Yes', $rows[0]['has_error']);
    }

    public function testServerListJsonFormat(): void
    {
        $testerJson = new CommandTester($this->command);
        $testerJson->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json'
        ]);

        $output = $testerJson->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertJson($output);
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['server_id']);
        $this->assertSame('Video Local', $rows[0]['title']);
        $this->assertSame('Video Group', $rows[0]['group_title']);
        $this->assertEquals(0, $testerJson->getStatusCode());
    }

    public function testServerListCountFormat(): void
    {
        $testerCount = new CommandTester($this->command);
        $testerCount->execute([
            '--force' => true,
            'action' => 'list',
            '--format' => 'count'
        ]);

        $output = trim($testerCount->getDisplay());
        $this->assertSame('3', $output);
        $this->assertEquals(0, $testerCount->getStatusCode());
    }

    public function testServerShow(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '1'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server #1', $output);
        $this->assertStringContainsString('Video Local', $output);
        $this->assertStringContainsString('Video Group', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('10 GB', $output);
        $this->assertStringContainsString('6 GB', $output);
        $this->assertStringContainsString('/data/videos', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerShowDisplaysMountConnectionInfo(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '2',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Server #2', $output);
        $this->assertStringContainsString('Video Disabled', $output);
        $this->assertStringContainsString('Mount', $output);
        $this->assertStringContainsString('/mnt/videos', $output);
        $this->assertStringNotContainsString('FTP Host', $output);
        $this->assertStringNotContainsString('S3 Bucket', $output);
    }

    public function testServerShowDisplaysFtpConnectionInfo(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '3',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Server #3', $output);
        $this->assertStringContainsString('Album Error', $output);
        $this->assertStringContainsString('FTP', $output);
        $this->assertStringContainsString('FTP Host', $output);
        $this->assertStringContainsString('ftp.example.test:21', $output);
        $this->assertStringContainsString('FTP User', $output);
        $this->assertStringContainsString('ftp-user', $output);
        $this->assertStringContainsString('FTP Folder', $output);
        $this->assertStringContainsString('/albums', $output);
    }

    public function testServerShowDisplaysS3ConnectionInfoWithoutCredentials(): void
    {
        $this->insertS3StorageServer();

        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '4',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Server #4', $output);
        $this->assertStringContainsString('Album S3', $output);
        $this->assertStringContainsString('S3', $output);
        $this->assertStringContainsString('S3 Region', $output);
        $this->assertStringContainsString('ca-central-1', $output);
        $this->assertStringContainsString('S3 Bucket', $output);
        $this->assertStringContainsString('album-bucket', $output);
        $this->assertStringContainsString('S3 Endpoint', $output);
        $this->assertStringContainsString('https://s3.example.test', $output);
        $this->assertStringNotContainsString('hidden-fixture-value-a', $output);
        $this->assertStringNotContainsString('hidden-fixture-value-b', $output);
    }

    public function testServerShowNotFound(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server not found: 999999', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerShowMissingId(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server ID is required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerStats(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'stats'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Storage Statistics', $output);
        $this->assertMatchesRegularExpression('/Total Servers\W+3/', $output);
        $this->assertMatchesRegularExpression('/Active\W+2/', $output);
        $this->assertMatchesRegularExpression('/Disabled\W+1/', $output);
        $this->assertMatchesRegularExpression('/With Errors\W+1/', $output);
        $this->assertMatchesRegularExpression('/Videos\W+2/', $output);
        $this->assertMatchesRegularExpression('/Albums\W+1/', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerGroupList(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video Group', $output);
        $this->assertStringContainsString('Album Group', $output);
        $this->assertStringContainsString('1/2', $output);
    }

    public function testServerGroupListUsesKvsAdminMinimumCapacity(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            '--format' => 'json',
            '--fields' => 'group_id,total_space,min_free',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'group_id');

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('5 GB', $rowsById[10]['total_space'] ?? null);
        $this->assertSame('2 GB', $rowsById[10]['min_free'] ?? null);
    }

    public function testServerGroupShow(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            'id' => '10'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server Group #10: Video Group', $output);
        $this->assertStringContainsString('3 Videos', $output);
        $this->assertStringContainsString('Servers in Group', $output);
        $this->assertStringContainsString('Video Local', $output);
        $this->assertStringContainsString('Video Disabled', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerGroupShowNotFound(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server group not found: 999999', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerCommandMetadata(): void
    {
        $this->assertEquals('system:server', $this->command->getName());
        $this->assertStringContainsString('server', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('server', $aliases);
        $this->assertContains('servers', $aliases);
    }

    public function testServerDefaultAction(): void
    {
        $this->tester->execute(['--force' => true]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video Local', $this->tester->getDisplay());
    }

    public function testServerRejectsUnknownAction(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'unknown_action',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown server action "unknown_action"', $this->tester->getDisplay());
    }

    public function testServerEnableMissingId(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'enable'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server ID is required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerDisableMissingId(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'disable'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server ID is required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerEnableNotFound(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'enable',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server not found: 999999', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerDisableNotFound(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'disable',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server not found: 999999', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_servers_groups') . ' (' .
            'group_id INTEGER, title TEXT, status_id INTEGER, content_type_id INTEGER, added_date TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_servers') . ' (' .
            'server_id INTEGER, group_id INTEGER, content_type_id INTEGER, title TEXT, status_id INTEGER, ' .
            'connection_type_id INTEGER, streaming_type_id INTEGER, total_space INTEGER, free_space INTEGER, ' .
            '`load` REAL, error_iteration INTEGER, error_streaming_iteration INTEGER, error_id INTEGER, ' .
            'error_streaming_id INTEGER, is_debug_enabled INTEGER, urls TEXT, path TEXT, ftp_host TEXT, ' .
            'ftp_port TEXT, ftp_user TEXT, ftp_folder TEXT, s3_region TEXT, s3_bucket TEXT, s3_endpoint TEXT, ' .
            's3_api_key TEXT, s3_api_secret TEXT, control_script_url TEXT, control_script_url_version TEXT, ' .
            'added_date TEXT)'
        );
        $db->exec('CREATE TABLE ' . TestHelper::table('videos') . ' (server_group_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('albums') . ' (server_group_id INTEGER)');

        $db->exec(
            'INSERT INTO ' . TestHelper::table('admin_servers_groups') .
            " VALUES (10, 'Video Group', 1, 1, '2026-05-20 10:00:00'), " .
            "(20, 'Album Group', 1, 2, '2026-05-21 10:00:00')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('admin_servers') .
            ' (server_id, group_id, content_type_id, title, status_id, connection_type_id, streaming_type_id, ' .
            'total_space, free_space, `load`, error_iteration, error_streaming_iteration, error_id, ' .
            'error_streaming_id, is_debug_enabled, urls, path, ftp_host, ftp_port, ftp_user, ftp_folder, ' .
            's3_region, s3_bucket, s3_endpoint, control_script_url, control_script_url_version, added_date) VALUES ' .
            "(1, 10, 1, 'Video Local', 1, 0, 0, 10737418240, 6442450944, 0.50, 0, 0, 0, 0, 0, " .
            "'https://cdn1.example.test', '/data/videos', '', '', '', '', '', '', '', " .
            "'https://control.example.test', '1.0', '2026-05-20 10:00:00'), " .
            "(2, 10, 1, 'Video Disabled', 0, 1, 1, 5368709120, 2147483648, 1.00, 0, 0, 0, 0, 0, " .
            "'https://cdn2.example.test', '/mnt/videos', '', '', '', '', '', '', '', '', '', '2026-05-21 10:00:00'), " .
            "(3, 20, 2, 'Album Error', 1, 2, 4, 2147483648, 536870912, 2.00, 3, 0, 1, 0, 1, " .
            "'https://albums.example.test', '', 'ftp.example.test', '21', 'ftp-user', '/albums', " .
            "'', '', '', '', '', '2026-05-22 10:00:00')"
        );
        $db->exec('INSERT INTO ' . TestHelper::table('videos') . ' VALUES (10), (10), (10)');
        $db->exec('INSERT INTO ' . TestHelper::table('albums') . ' VALUES (20), (20)');

        return $db;
    }

    private function insertS3StorageServer(): void
    {
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('admin_servers') .
            ' (server_id, group_id, content_type_id, title, status_id, connection_type_id, streaming_type_id, ' .
            'total_space, free_space, `load`, error_iteration, error_streaming_iteration, error_id, ' .
            'error_streaming_id, is_debug_enabled, urls, path, ftp_host, ftp_port, ftp_user, ftp_folder, ' .
            's3_region, s3_bucket, s3_endpoint, s3_api_key, s3_api_secret, control_script_url, ' .
            'control_script_url_version, added_date) VALUES ' .
            "(4, 20, 2, 'Album S3', 1, 3, 4, 3221225472, 1073741824, 0.25, 0, 0, 0, 0, 0, " .
            "'https://s3-cdn.example.test', '', '', '', '', '', 'ca-central-1', 'album-bucket', " .
            "'https://s3.example.test', 'hidden-fixture-value-a', 'hidden-fixture-value-b', '', '', " .
            "'2026-05-23 10:00:00')"
        );
    }

    private function createCommand(PDO $db): ServerCommand
    {
        return new class ($this->config, $db) extends ServerCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:server');
                $this->setDescription('[EXPERIMENTAL] Manage KVS storage servers');
                $this->setAliases(['server', 'servers']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
