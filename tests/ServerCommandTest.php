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

    public function testServerListUsesKvsAdminStreamingLabels(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,streaming_type_id,streaming',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'server_id');

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(1, (int) $rowsById[2]['streaming_type_id']);
        $this->assertSame('Direct URL (no protection)', $rowsById[2]['streaming']);
        $this->assertStringNotContainsString('Apache', $this->tester->getDisplay());
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

    public function testServerListExposesKvsAdminStorageServerFields(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => implode(',', [
                'server_id',
                'total_content',
                'control_script_url',
                'control_script_url_version',
                'control_script_url_lock_ip',
                'path',
                'ftp_host',
                'ftp_port',
                'ftp_user',
                'ftp_folder',
                'ftp_timeout',
                'ftp_force_ssl',
                's3_region',
                's3_endpoint',
                's3_bucket',
                's3_prefix',
                'time_offset',
                'lb_weight',
                'lb_countries',
                'is_debug_enabled',
                'added_date',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'server_id');

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('3 Videos', $rowsById[1]['total_content']);
        $this->assertSame('2 Albums', $rowsById[3]['total_content']);
        $this->assertSame('', $rowsById[1]['control_script_url']);
        $this->assertSame('N/A', $rowsById[1]['control_script_url_version']);
        $this->assertSame(0, (int) $rowsById[1]['control_script_url_lock_ip']);
        $this->assertSame('/data/videos', $rowsById[1]['path']);
        $this->assertSame('0.25', (string) $rowsById[1]['time_offset']);
        $this->assertSame('1.5', (string) $rowsById[1]['lb_weight']);
        $this->assertSame('CA,US', $rowsById[1]['lb_countries']);
        $this->assertSame('2026-05-20 10:00:00', $rowsById[1]['added_date']);

        $this->assertSame('ftp.example.test', $rowsById[3]['ftp_host']);
        $this->assertSame('21', $rowsById[3]['ftp_port']);
        $this->assertSame('ftp-user', $rowsById[3]['ftp_user']);
        $this->assertSame('/albums', $rowsById[3]['ftp_folder']);
        $this->assertSame('30', $rowsById[3]['ftp_timeout']);
        $this->assertSame(1, (int) $rowsById[3]['ftp_force_ssl']);
        $this->assertSame(1, (int) $rowsById[3]['is_debug_enabled']);
    }

    public function testServerListRejectsRawCredentialFields(): void
    {
        $this->insertS3StorageServer();

        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,ftp_pass,s3_api_secret',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown field(s): ftp_pass, s3_api_secret', $this->tester->getDisplay());
    }

    public function testServerListExposesKvsAdminComputedWarningFields(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => implode(',', [
                'server_id',
                'free_space_percent',
                'error_text',
                'is_error',
                'is_warning',
                'is_free_space_warning',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'server_id');

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('(60%)', $rowsById[1]['free_space_percent']);
        $this->assertSame('', $rowsById[1]['error_text']);
        $this->assertSame(0, (int) $rowsById[1]['is_error']);
        $this->assertSame(0, (int) $rowsById[1]['is_warning']);
        $this->assertSame(0, (int) $rowsById[1]['is_free_space_warning']);

        $this->assertSame('(25%)', $rowsById[3]['free_space_percent']);
        $this->assertSame(
            ' (This server has debug log enabled) (Content path is not writable)',
            $rowsById[3]['error_text']
        );
        $this->assertSame(1, (int) $rowsById[3]['is_error']);
        $this->assertSame(1, (int) $rowsById[3]['is_warning']);
        $this->assertSame(0, (int) $rowsById[3]['is_free_space_warning']);
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

    public function testServerListCountIgnoresDisplayLimit(): void
    {
        $testerCount = new CommandTester($this->command);
        $testerCount->execute([
            '--force' => true,
            'action' => 'list',
            '--format' => 'count',
            '--limit' => 1,
        ]);

        $this->assertSame('3', trim($testerCount->getDisplay()));
        $this->assertSame(0, $testerCount->getStatusCode());
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

    public function testServerShowSupportsJsonFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '1',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('1', $rows[0]['server_id']);
        $this->assertSame('Video Local', $rows[0]['title']);
        $this->assertSame('Video Group', $rows[0]['group']);
        $this->assertSame('/data/videos', $rows[0]['path']);
        $this->assertStringNotContainsString('Server #1', $output);
    }

    public function testServerShowHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '1',
            '--fields' => 'server_id',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Server id', $output);
        $this->assertStringContainsString('1', $output);
        $this->assertStringNotContainsString('Server #1', $output);
        $this->assertStringNotContainsString('Property', $output);
    }

    public function testServerShowUsesKvsAdminErrorLabels(): void
    {
        $this->db->exec(
            'UPDATE ' . TestHelper::table('admin_servers') .
            ' SET error_streaming_id = 5, error_streaming_iteration = 2 WHERE server_id = 1'
        );

        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '1',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Content check found errors', $output);
        $this->assertStringNotContainsString('Content availability error', $output);
    }

    public function testServerShowRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '1abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Server ID', $this->tester->getDisplay());
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
        $this->assertStringContainsString('Content path is not writable', $output);
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
        $this->assertMatchesRegularExpression('/Inactive\W+1/', $output);
        $this->assertMatchesRegularExpression('/With Errors\W+1/', $output);
        $this->assertMatchesRegularExpression('/Videos\W+2/', $output);
        $this->assertMatchesRegularExpression('/Albums\W+1/', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerStatsSupportsJsonFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'stats',
            '--format' => 'json',
            '--fields' => 'section,metric,value,label',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsByMetric = array_column($rows, null, 'metric');

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('overall', $rowsByMetric['Total Servers']['section'] ?? null);
        $this->assertSame(3, (int) ($rowsByMetric['Total Servers']['value'] ?? 0));
        $this->assertSame(1, (int) ($rowsByMetric['Inactive']['value'] ?? 0));
        $this->assertArrayNotHasKey('Disabled', $rowsByMetric);
        $this->assertStringNotContainsString('Storage Statistics', $this->tester->getDisplay());
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

    public function testServerGroupListHonorsLimit(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            '--limit' => 1,
            '--format' => 'json',
            '--fields' => 'group_id,title',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['group_id']);
        $this->assertSame('Video Group', $rows[0]['title']);
    }

    public function testServerGroupCountIgnoresDisplayLimit(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            '--limit' => 1,
            '--format' => 'count',
        ]);

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertSame('2', trim($this->tester->getDisplay()));
    }

    public function testServerGroupShowSupportsJsonFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            'id' => '10',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame(10, $rows[0]['group_id']);
        $this->assertSame('Video Group', $rows[0]['title']);
        $this->assertCount(2, $rows[0]['servers']);
        $this->assertSame(1, $rows[0]['servers'][0]['server_id']);
        $this->assertStringNotContainsString('Server Group #10', $output);
    }

    public function testServerGroupShowHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            'id' => '10',
            '--fields' => 'title',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Video Group', $output);
        $this->assertStringNotContainsString('Server Group #10', $output);
        $this->assertStringNotContainsString('Servers in Group', $output);
    }

    public function testServerGroupShowRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            'id' => '10abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Server group ID', $this->tester->getDisplay());
    }

    public function testServerGroupListRejectsInvalidLimitBeforeSql(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            '--limit' => -1,
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --limit', $this->tester->getDisplay());
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

    public function testServerGroupListExposesKvsAdminGroupFields(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            '--format' => 'json',
            '--fields' => implode(',', [
                'group_id',
                'content_type_id',
                'servers_count',
                'servers_amount',
                'total_servers_amount',
                'active_servers_amount',
                'total_content',
                'free_space',
                'load',
                'added_date',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'group_id');

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame(1, (int) $rowsById[10]['content_type_id']);
        $this->assertSame(2, (int) $rowsById[10]['servers_count']);
        $this->assertSame(2, (int) $rowsById[10]['servers_amount']);
        $this->assertSame(2, (int) $rowsById[10]['total_servers_amount']);
        $this->assertSame(1, (int) $rowsById[10]['active_servers_amount']);
        $this->assertSame('3 Videos', $rowsById[10]['total_content']);
        $this->assertSame('2 GB', $rowsById[10]['free_space']);
        $this->assertSame('0.75', $rowsById[10]['load']);
        $this->assertSame('2026-05-20 10:00:00', $rowsById[10]['added_date']);
    }

    public function testServerGroupListExposesKvsAdminComputedWarningFields(): void
    {
        $this->db->exec('CREATE TABLE ' . TestHelper::table('options') . ' (variable TEXT, value TEXT)');
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('options') .
            " VALUES ('SERVER_GROUP_MIN_FREE_SPACE_MB', '3072')"
        );

        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            '--format' => 'json',
            '--fields' => implode(',', [
                'group_id',
                'free_space_percent',
                'error_text',
                'is_warning',
                'is_free_space_warning',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'group_id');

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('(40%)', $rowsById[10]['free_space_percent']);
        $this->assertSame(' (No free space is available)', $rowsById[10]['error_text']);
        $this->assertSame(1, (int) $rowsById[10]['is_warning']);
        $this->assertSame(1, (int) $rowsById[10]['is_free_space_warning']);
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

    public function testServerShowRejectsListOnlyOptions(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '1',
            '--status' => 'disabled',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('The show action does not support --status', $this->tester->getDisplay());
    }

    public function testServerStatsRejectsListOnlyOptions(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'stats',
            '--limit' => 1,
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('The stats action does not support --limit', $this->tester->getDisplay());
    }

    public function testServerGroupListRejectsStorageFilters(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            '--status' => 'disabled',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('The group action does not support --status', $this->tester->getDisplay());
    }

    public function testServerGroupShowRejectsListOnlyOptions(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            'id' => '10',
            '--limit' => 1,
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('The group action does not support --limit', $this->tester->getDisplay());
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
            'connection_type_id INTEGER, streaming_type_id INTEGER, is_remote INTEGER, total_space INTEGER, free_space INTEGER, ' .
            '`load` REAL, error_iteration INTEGER, error_streaming_iteration INTEGER, error_id INTEGER, ' .
            'error_streaming_id INTEGER, is_debug_enabled INTEGER, urls TEXT, path TEXT, ftp_host TEXT, ' .
            'ftp_port TEXT, ftp_user TEXT, ftp_folder TEXT, ftp_timeout TEXT, ftp_force_ssl INTEGER, ' .
            's3_region TEXT, s3_bucket TEXT, s3_endpoint TEXT, s3_prefix TEXT, s3_api_key TEXT, ' .
            's3_api_secret TEXT, control_script_url TEXT, control_script_url_version TEXT, ' .
            'control_script_url_lock_ip INTEGER, time_offset REAL, lb_weight REAL, lb_countries TEXT, added_date TEXT)'
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
            'is_remote, ' .
            'total_space, free_space, `load`, error_iteration, error_streaming_iteration, error_id, ' .
            'error_streaming_id, is_debug_enabled, urls, path, ftp_host, ftp_port, ftp_user, ftp_folder, ' .
            'ftp_timeout, ftp_force_ssl, s3_region, s3_bucket, s3_endpoint, s3_prefix, control_script_url, ' .
            'control_script_url_version, control_script_url_lock_ip, time_offset, lb_weight, lb_countries, ' .
            'added_date) VALUES ' .
            "(1, 10, 1, 'Video Local', 1, 0, 0, 0, 10737418240, 6442450944, 0.50, 0, 0, 0, 0, 0, " .
            "'https://cdn1.example.test', '/data/videos', '', '', '', '', '', 0, '', '', '', '', " .
            "'https://control.example.test', '1.0', 1, 0.25, 1.5, 'CA,US', " .
            "'2026-05-20 10:00:00'), " .
            "(2, 10, 1, 'Video Disabled', 0, 1, 1, 1, 5368709120, 2147483648, 1.00, 0, 0, 0, 0, 0, " .
            "'https://cdn2.example.test', '/mnt/videos', '', '', '', '', '', 0, '', '', '', '', " .
            "'', '', 0, 0, 1.0, 'CA', '2026-05-21 10:00:00'), " .
            "(3, 20, 2, 'Album Error', 1, 2, 4, 0, 2147483648, 536870912, 2.00, 3, 0, 1, 0, 1, " .
            "'https://albums.example.test', '', 'ftp.example.test', '21', 'ftp-user', '/albums', " .
            "'30', 1, '', '', '', '', '', '', 0, 0, 2.5, 'US', '2026-05-22 10:00:00')"
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
            'is_remote, ' .
            'total_space, free_space, `load`, error_iteration, error_streaming_iteration, error_id, ' .
            'error_streaming_id, is_debug_enabled, urls, path, ftp_host, ftp_port, ftp_user, ftp_folder, ' .
            'ftp_timeout, ftp_force_ssl, s3_region, s3_bucket, s3_endpoint, s3_prefix, s3_api_key, ' .
            's3_api_secret, control_script_url, control_script_url_version, control_script_url_lock_ip, ' .
            'time_offset, lb_weight, lb_countries, added_date) VALUES ' .
            "(4, 20, 2, 'Album S3', 1, 3, 4, 0, 3221225472, 1073741824, 0.25, 0, 0, 0, 0, 0, " .
            "'https://s3-cdn.example.test', '', '', '', '', '', '', 0, 'ca-central-1', 'album-bucket', " .
            "'https://s3.example.test', 'albums', 'hidden-fixture-value-a', 'hidden-fixture-value-b', '', '', " .
            "0, 0, 1.0, 'CA', " .
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
