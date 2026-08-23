<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\ServerCommand;
use KVS\CLI\Config\Configuration;
use KVS\CLI\Service\StorageClusterDataPublisher;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;

class ServerCommandTest extends TestCase
{
    private const INITIAL_CLUSTER_DATA = 'initial cluster data';

    private string $kvsPath;
    private Configuration $config;
    private ServerCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        file_put_contents($this->kvsPath . '/admin/data/system/cluster.dat', self::INITIAL_CLUSTER_DATA);
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

    public function testMutationsRejectListAndOutputOptionsBeforeLookup(): void
    {
        $cases = [
            ['enable', '--format', 'json', 'format'],
            ['disable', '--fields', 'title', 'fields'],
            ['enable', '--type', 'video', 'type'],
            ['disable', '--no-truncate', true, 'no-truncate'],
            ['enable', '--connection', 'local', 'connection'],
        ];

        foreach ($cases as [$action, $option, $value, $optionName]) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => $action,
                'id' => '999999',
                $option => $value,
            ]);

            $output = $tester->getDisplay();

            $this->assertSame(1, $tester->getStatusCode(), $optionName . ': ' . $output);
            $this->assertStringContainsString("The $action action does not support --$optionName", $output);
            $this->assertStringNotContainsString('Server not found', $output);
        }
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

    public function testServerListCountRejectsFieldSelection(): void
    {
        $testerCount = new CommandTester($this->command);
        $testerCount->execute([
            '--force' => true,
            'action' => 'list',
            '--format' => 'count',
            '--fields' => 'server_id',
        ]);

        $this->assertSame(1, $testerCount->getStatusCode(), $testerCount->getDisplay());
        $this->assertStringContainsString('The count format does not support --fields.', $testerCount->getDisplay());
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

    public function testServerShowSupportsRequestedAdminListFields(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '1',
            '--format' => 'json',
            '--fields' => implode(',', [
                'server_id',
                'status_id',
                'content_type_id',
                'total_content',
                'content_count',
                'streaming_type_id',
                'control_script_url',
                'control_script_url_version',
                'control_script_url_lock_ip',
                'connection_type_id',
                'added_date',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['server_id']);
        $this->assertSame(1, (int) $rows[0]['status_id']);
        $this->assertSame(1, (int) $rows[0]['content_type_id']);
        $this->assertSame('3 Videos', $rows[0]['total_content']);
        $this->assertSame(3, (int) $rows[0]['content_count']);
        $this->assertSame(0, (int) $rows[0]['streaming_type_id']);
        $this->assertSame('', $rows[0]['control_script_url']);
        $this->assertSame('N/A', $rows[0]['control_script_url_version']);
        $this->assertSame(0, (int) $rows[0]['control_script_url_lock_ip']);
        $this->assertSame(0, (int) $rows[0]['connection_type_id']);
        $this->assertSame('2026-05-20 10:00:00', $rows[0]['added_date']);
    }

    public function testServerShowRejectsCountFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '1',
            '--format' => 'count',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('show action does not support --format=count', $this->tester->getDisplay());
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

    public function testServerShowExposesKvsAdminComputedWarningFields(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '3',
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

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['server_id']);
        $this->assertSame('(25%)', $rows[0]['free_space_percent']);
        $this->assertSame(
            ' (This server has debug log enabled) (Content path is not writable)',
            $rows[0]['error_text']
        );
        $this->assertSame(1, (int) $rows[0]['is_error']);
        $this->assertSame(1, (int) $rows[0]['is_warning']);
        $this->assertSame(0, (int) $rows[0]['is_free_space_warning']);
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

    public function testServerGroupShowRejectsCountFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            'id' => '10',
            '--format' => 'count',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('group action does not support --format=count', $this->tester->getDisplay());
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

    public function testServerGroupShowSupportsRequestedAdminFields(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            'id' => '10',
            '--format' => 'json',
            '--fields' => implode(',', [
                'group_id',
                'status_id',
                'content_type_id',
                'servers_count',
                'active_servers_amount',
                'total_content',
                'total_space',
                'free_space',
                'load',
                'added_date',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['group_id']);
        $this->assertSame(1, (int) $rows[0]['status_id']);
        $this->assertSame(1, (int) $rows[0]['content_type_id']);
        $this->assertSame(2, (int) $rows[0]['servers_count']);
        $this->assertSame(1, (int) $rows[0]['active_servers_amount']);
        $this->assertSame('3 Videos', $rows[0]['total_content']);
        $this->assertSame('5 GB', $rows[0]['total_space']);
        $this->assertSame('2 GB', $rows[0]['free_space']);
        $this->assertSame('0.75', $rows[0]['load']);
        $this->assertSame('2026-05-20 10:00:00', $rows[0]['added_date']);
    }

    public function testServerGroupShowExposesKvsAdminComputedWarningFields(): void
    {
        $this->db->exec('CREATE TABLE ' . TestHelper::table('options') . ' (variable TEXT, value TEXT)');
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('options') .
            " VALUES ('SERVER_GROUP_MIN_FREE_SPACE_MB', '3072')"
        );

        $this->tester->execute([
            '--force' => true,
            'action' => 'group',
            'id' => '10',
            '--format' => 'json',
            '--fields' => implode(',', [
                'group_id',
                'free_space_percent',
                'error_text',
                'is_warning',
                'is_free_space_warning',
            ]),
        ]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['group_id']);
        $this->assertSame('(40%)', $rows[0]['free_space_percent']);
        $this->assertSame(' (No free space is available)', $rows[0]['error_text']);
        $this->assertSame(1, (int) $rows[0]['is_warning']);
        $this->assertSame(1, (int) $rows[0]['is_free_space_warning']);
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

    public function testServerWeightActionsAreDocumentedInHelp(): void
    {
        $help = $this->command->getHelp();
        $definition = $this->command->getDefinition();

        $this->assertStringContainsString('weights <group-id>', $help);
        $this->assertStringContainsString('set-weights <group-id>', $help);
        $this->assertTrue($definition->hasOption('weight'));
        $this->assertTrue($definition->getOption('weight')->isArray());
        $this->assertTrue($definition->hasOption('if-revision'));
        $this->assertTrue($definition->hasOption('ignore-revision'));
        $this->assertTrue($definition->hasOption('dry-run'));
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

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown server action "unknown_action"', $output);
        $this->assertStringContainsString('activate', $output);
        $this->assertStringContainsString('deactivate', $output);
        $this->assertStringContainsString('weights', $output);
        $this->assertStringContainsString('set-weights', $output);
    }

    public function testServerWeightsReturnsCompleteCanonicalVectorWithoutSecrets(): void
    {
        $this->prepareWeightFixture();
        $this->db->exec(
            'UPDATE ' . TestHelper::table('admin_servers') . " SET streaming_key = 'hidden-value' WHERE server_id = 1"
        );

        [$status, $result, $display] = $this->executeWeightJson([
            'action' => 'weights',
            'id' => '10',
        ]);

        $canonical = [
            [
                'server_id' => 1,
                'group_id' => 10,
                'status_id' => 1,
                'streaming_type_id' => 0,
                'lb_weight' => '1.5',
                'lb_countries' => [],
            ],
            [
                'server_id' => 2,
                'group_id' => 10,
                'status_id' => 0,
                'streaming_type_id' => 1,
                'lb_weight' => '1',
                'lb_countries' => ['CA'],
            ],
            [
                'server_id' => 4,
                'group_id' => 10,
                'status_id' => 1,
                'streaming_type_id' => 4,
                'lb_weight' => '2',
                'lb_countries' => ['CA'],
            ],
            [
                'server_id' => 5,
                'group_id' => 10,
                'status_id' => 1,
                'streaming_type_id' => 4,
                'lb_weight' => '3',
                'lb_countries' => ['US'],
            ],
            [
                'server_id' => 6,
                'group_id' => 10,
                'status_id' => 1,
                'streaming_type_id' => 5,
                'lb_weight' => '1',
                'lb_countries' => [],
            ],
        ];
        $expectedRevision = hash(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );

        $this->assertSame(0, $status, $display);
        $this->assertTrue($result['ok']);
        $this->assertSame('weights', $result['action']);
        $this->assertSame(10, $result['group_id']);
        $this->assertSame($expectedRevision, $result['revision']);
        $this->assertCount(5, $result['weights']);
        $this->assertSame([1, 2, 4, 5, 6], array_column($result['weights'], 'server_id'));
        $this->assertSame(1.5, $result['weights'][0]['weight']);
        $this->assertSame([true, false, true, true, false], array_column($result['weights'], 'eligible'));
        $this->assertStringNotContainsString('streaming_key', $display);
        $this->assertStringNotContainsString('hidden-value', $display);
        $this->assertSame($result, json_decode(trim($display), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testServerSetWeightsRejectsMalformedValues(): void
    {
        $invalidValues = ['1', '1:', ':1', '0:1', '1:0', '1:-1', '1:+1', '1:1.5', '1:100000', ' 1:2'];

        foreach ($invalidValues as $invalidValue) {
            [$status, $result, $display] = $this->executeWeightJson([
                'action' => 'set-weights',
                'id' => '10',
                '--weight' => [$invalidValue],
                '--dry-run' => true,
            ]);

            $this->assertSame(1, $status, $invalidValue . ': ' . $display);
            $this->assertFalse($result['ok']);
            $this->assertContains($result['error']['code'], ['invalid_weight_entry', 'invalid_weight']);
        }
    }

    public function testServerSetWeightsRejectsDuplicateIdsAndExcessiveSum(): void
    {
        [$duplicateStatus, $duplicate] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => ['1:4', '1:5'],
            '--dry-run' => true,
        ]);
        [$sumStatus, $sum] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => ['1:60000', '2:40000'],
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $duplicateStatus);
        $this->assertSame('duplicate_server_id', $duplicate['error']['code']);
        $this->assertSame(1, $sumStatus);
        $this->assertSame('excessive_weight_sum', $sum['error']['code']);
    }

    public function testServerSetWeightsValidatesGroupAndCompleteMembership(): void
    {
        $weights = $this->prepareWeightFixture();

        [$missingGroupStatus, $missingGroup] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '999',
            '--weight' => ['1:1'],
            '--dry-run' => true,
        ]);
        [$missingServerStatus, $missingServer] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => [...$this->weightOptions($weights), '999:1'],
            '--dry-run' => true,
        ]);
        [$outOfGroupStatus, $outOfGroup] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => [...$this->weightOptions($weights), '3:1'],
            '--dry-run' => true,
        ]);
        unset($weights[6]);
        [$incompleteStatus, $incomplete] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $missingGroupStatus);
        $this->assertSame('group_not_found', $missingGroup['error']['code']);
        $this->assertSame(1, $missingServerStatus);
        $this->assertSame('server_not_found', $missingServer['error']['code']);
        $this->assertSame(1, $outOfGroupStatus);
        $this->assertSame('server_out_of_group', $outOfGroup['error']['code']);
        $this->assertSame(1, $incompleteStatus);
        $this->assertSame('incomplete_weight_vector', $incomplete['error']['code']);
    }

    public function testServerSetWeightsRequiresAndChecksRevision(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();

        [$requiredStatus, $required] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
        ]);
        [$conflictStatus, $conflict] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => str_repeat('0', 64),
        ]);
        $revision = $this->readWeightRevision();
        [$validStatus, $valid] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $revision,
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $requiredStatus);
        $this->assertSame('revision_required', $required['error']['code']);
        $this->assertSame(1, $conflictStatus);
        $this->assertSame('revision_conflict', $conflict['error']['code']);
        $this->assertSame(0, $validStatus);
        $this->assertTrue($valid['dry_run']);
    }

    public function testServerSetWeightsRechecksRevisionImmediatelyBeforeWriting(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();
        $revision = $this->readWeightRevision();
        $databaseBefore = $this->fetchWeightRows();
        $clusterBefore = file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat');
        $serverTable = TestHelper::table('admin_servers');
        $publisher = new class (
            $this->kvsPath . '/admin/data/system/cluster.dat',
            $this->db,
            $serverTable
        ) extends StorageClusterDataPublisher {
            private bool $changed = false;

            public function __construct(
                string $clusterFile,
                private PDO $db,
                private string $serverTable
            ) {
                parent::__construct($clusterFile);
            }

            public function fieldsFromSerializedRows(string $bytes): array
            {
                $fields = parent::fieldsFromSerializedRows($bytes);
                if (!$this->changed) {
                    $this->changed = true;
                    $this->db->exec("UPDATE {$this->serverTable} SET status_id = 1 WHERE server_id = 2");
                }

                return $fields;
            }
        };
        $this->useClusterPublisher($publisher);

        [$status, $result, $display] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $revision,
        ]);

        $this->assertSame(1, $status, $display);
        $this->assertSame('revision_conflict', $result['error']['code']);
        $this->assertSame($databaseBefore, $this->fetchWeightRows());
        $this->assertSame($clusterBefore, file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat'));
        $this->assertSame(1, (int) $this->fetchServerRow(2)['status_id']);
    }

    public function testServerSetWeightsDryRunLeavesDatabaseAndClusterDataUnchanged(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();
        $databaseBefore = $this->fetchWeightRows();
        $clusterBefore = file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat');

        [$status, $result, $display] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $this->readWeightRevision(),
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $status, $display);
        $this->assertTrue($result['dry_run']);
        $this->assertTrue($result['changed']);
        $this->assertFalse($result['cluster_data_updated']);
        $this->assertSame($databaseBefore, $this->fetchWeightRows());
        $this->assertSame($clusterBefore, file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat'));
    }

    public function testServerSetWeightsUpdatesVectorCoherentlyAndPreservesOtherFields(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();
        $before = $this->fetchServerRowsWithoutWeights(10);

        [$status, $result, $display] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $this->readWeightRevision(),
        ]);

        $this->assertSame(0, $status, $display);
        $this->assertTrue($result['changed']);
        $this->assertTrue($result['cluster_data_updated']);
        $this->assertSame($weights, $this->fetchWeightMap(10));
        $this->assertSame($before, $this->fetchServerRowsWithoutWeights(10));
        $this->assertSame(
            [1, 2, 3, 4, 5, 6],
            array_map('intval', array_column($this->readClusterRows(), 'server_id'))
        );
        foreach ($this->readClusterRows() as $clusterRow) {
            $this->assertSame(StorageClusterDataPublisher::FIELDS, array_keys($clusterRow));
        }
    }

    public function testServerSetWeightsPreservesInstalledClusterFieldSet(): void
    {
        $weights = $this->prepareWeightFixture();
        $serverTable = TestHelper::table('admin_servers');
        $this->db->exec("ALTER TABLE {$serverTable} ADD COLUMN control_script_url_ttl INTEGER DEFAULT 0");
        $this->db->exec("ALTER TABLE {$serverTable} ADD COLUMN control_script_url_pattern TEXT DEFAULT ''");
        $this->db->exec(
            "UPDATE {$serverTable} SET control_script_url_ttl = server_id * 10, "
            . "control_script_url_pattern = 'pattern-' || server_id"
        );
        $fields = [
            'server_id',
            'group_id',
            'content_type_id',
            'status_id',
            'streaming_type_id',
            'streaming_script',
            'streaming_key',
            'is_replace_domain_on_satellite',
            'urls',
            'is_remote',
            'control_script_url',
            'control_script_url_version',
            'control_script_url_lock_ip',
            'control_script_url_ttl',
            'control_script_url_pattern',
            'time_offset',
            'lb_weight',
            'lb_countries',
            'error_streaming_id',
            'error_streaming_iteration',
            'warning_id',
            'is_debug_enabled',
        ];
        $publisher = new StorageClusterDataPublisher($this->kvsPath . '/admin/data/system/cluster.dat');
        $installedRows = $publisher->fetchRows($this->db, $serverTable, $fields);
        file_put_contents(
            $this->kvsPath . '/admin/data/system/cluster.dat',
            $publisher->serializeRows($installedRows)
        );

        [$status, , $display] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $this->readWeightRevision(),
        ]);

        $this->assertSame(0, $status, $display);
        $publishedRows = $this->readClusterRows();
        foreach ($publishedRows as $index => $row) {
            $this->assertSame($fields, array_keys($row));
            $this->assertSame(
                $installedRows[$index]['control_script_url_version'],
                $row['control_script_url_version']
            );
            $this->assertSame($installedRows[$index]['control_script_url_ttl'], $row['control_script_url_ttl']);
            $this->assertSame(
                $installedRows[$index]['control_script_url_pattern'],
                $row['control_script_url_pattern']
            );
            $this->assertSame($installedRows[$index]['is_debug_enabled'], $row['is_debug_enabled']);
        }
    }

    public function testServerSetWeightsIsIdempotent(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();
        [, $first] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $this->readWeightRevision(),
        ]);
        $clusterBefore = file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat');

        [$status, $second, $display] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $first['revision_after'],
        ]);

        $this->assertSame(0, $status, $display);
        $this->assertFalse($second['changed']);
        $this->assertFalse($second['cluster_data_updated']);
        $this->assertSame([], $second['changes']);
        $this->assertSame($clusterBefore, file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat'));
    }

    public function testServerSetWeightsRejectsGroupWithoutUnrestrictedEligibleServer(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->db->exec(
            'UPDATE ' . TestHelper::table('admin_servers') . " SET lb_countries = 'FR' WHERE server_id = 1"
        );
        $this->writeCurrentClusterData();

        [$status, $result] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $status);
        $this->assertSame('no_unrestricted_active_server', $result['error']['code']);
    }

    public function testServerSetWeightsSupportsExplicitManualRecoveryOverride(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();

        [$status, $result, $display] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--ignore-revision' => true,
        ]);

        $this->assertSame(0, $status, $display);
        $this->assertTrue($result['changed']);
        $this->assertSame($weights, $this->fetchWeightMap(10));
    }

    public function testServerSetWeightsRollsBackDatabaseUpdateFailure(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();
        $databaseBefore = $this->fetchWeightRows();
        $clusterBefore = file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat');
        $this->db->exec(
            'CREATE TRIGGER fail_weight_update BEFORE UPDATE OF lb_weight ON '
            . TestHelper::table('admin_servers')
            . " BEGIN SELECT RAISE(FAIL, 'forced database failure'); END"
        );

        [$status, $result] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $this->readWeightRevision(),
        ]);

        $this->assertSame(1, $status);
        $this->assertSame('set_weights_failed', $result['error']['code']);
        $this->assertArrayNotHasKey('recovery_required', $result);
        $this->assertSame($databaseBefore, $this->fetchWeightRows());
        $this->assertSame($clusterBefore, file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat'));
    }

    public function testServerSetWeightsRollsBackTemporaryFileWriteFailure(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();
        $databaseBefore = $this->fetchWeightRows();
        $clusterBefore = file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat');
        $publisher = new class ($this->kvsPath . '/admin/data/system/cluster.dat') extends StorageClusterDataPublisher {
            private bool $failNextWrite = true;

            protected function writeFile(string $path, string $bytes): int|false
            {
                if ($this->failNextWrite) {
                    $this->failNextWrite = false;
                    return false;
                }

                return parent::writeFile($path, $bytes);
            }
        };
        $this->useClusterPublisher($publisher);

        [$status, $result] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $this->readWeightRevision(),
        ]);

        $this->assertSame(1, $status);
        $this->assertSame('set_weights_failed', $result['error']['code']);
        $this->assertArrayNotHasKey('recovery_required', $result);
        $this->assertSame($databaseBefore, $this->fetchWeightRows());
        $this->assertSame($clusterBefore, file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat'));
    }

    public function testServerSetWeightsRollsBackAtomicRenameFailure(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();
        $databaseBefore = $this->fetchWeightRows();
        $clusterBefore = file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat');
        $publisher = new class ($this->kvsPath . '/admin/data/system/cluster.dat') extends StorageClusterDataPublisher {
            private bool $failNextRename = true;

            protected function renameFile(string $source, string $target): bool
            {
                if ($this->failNextRename) {
                    $this->failNextRename = false;
                    return false;
                }

                return parent::renameFile($source, $target);
            }
        };
        $this->useClusterPublisher($publisher);

        [$status, $result] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $this->readWeightRevision(),
        ]);

        $this->assertSame(1, $status);
        $this->assertArrayNotHasKey('recovery_required', $result);
        $this->assertSame($databaseBefore, $this->fetchWeightRows());
        $this->assertSame($clusterBefore, file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat'));
    }

    public function testServerSetWeightsRollsBackFinalVerificationFailure(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();
        $databaseBefore = $this->fetchWeightRows();
        $clusterBefore = file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat');
        $publisher = new class ($this->kvsPath . '/admin/data/system/cluster.dat') extends StorageClusterDataPublisher {
            private bool $failNextVerification = true;

            public function readRows(): array
            {
                if ($this->failNextVerification) {
                    $this->failNextVerification = false;
                    throw new \RuntimeException('Forced final verification failure');
                }

                return parent::readRows();
            }
        };
        $this->useClusterPublisher($publisher);

        [$status, $result] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $this->readWeightRevision(),
        ]);

        $this->assertSame(1, $status);
        $this->assertArrayNotHasKey('recovery_required', $result);
        $this->assertSame($databaseBefore, $this->fetchWeightRows());
        $this->assertSame($clusterBefore, file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat'));
    }

    public function testServerSetWeightsReportsRollbackFailure(): void
    {
        $weights = $this->prepareWeightFixture();
        $this->writeCurrentClusterData();
        $publisher = new class ($this->kvsPath . '/admin/data/system/cluster.dat') extends StorageClusterDataPublisher {
            private bool $failNextVerification = true;

            public function readRows(): array
            {
                if ($this->failNextVerification) {
                    $this->failNextVerification = false;
                    throw new \RuntimeException('Forced final verification failure');
                }

                return parent::readRows();
            }
        };
        $this->useClusterPublisher($publisher);
        $this->db->exec(
            'CREATE TRIGGER fail_weight_rollback BEFORE UPDATE OF lb_weight ON '
            . TestHelper::table('admin_servers')
            . " WHEN OLD.server_id = 1 AND NEW.lb_weight = 1.5 "
            . "BEGIN SELECT RAISE(FAIL, 'forced rollback failure'); END"
        );

        [$status, $result] = $this->executeWeightJson([
            'action' => 'set-weights',
            'id' => '10',
            '--weight' => $this->weightOptions($weights),
            '--if-revision' => $this->readWeightRevision(),
        ]);

        $this->assertSame(1, $status);
        $this->assertTrue($result['recovery_required']);
        $this->assertSame($weights, $this->fetchWeightMap(10));
    }

    public function testServerWeightsJsonRequiresForceWithoutAdditionalOutput(): void
    {
        $this->prepareWeightFixture();
        $tester = new CommandTester($this->command);
        $tester->setInputs([]);
        $tester->execute([
            'action' => 'weights',
            'id' => '10',
            '--format' => 'json',
        ], ['interactive' => false]);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame('experimental_confirmation_required', $decoded['error']['code']);
        $this->assertSame($decoded, json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR));
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

    public function testServerDisableRejectsOnlyActiveNonBackupServerInGroup(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'disable',
            'id' => 1,
        ]);

        $output = $this->tester->getDisplay();
        $server = $this->fetchServerRow(1);

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Cannot disable the only active non-backup server', $output);
        $this->assertSame(1, (int) $server['status_id']);
        $this->assertClusterDataUnchanged();
    }

    public function testServerDisableRejectsWhenRemainingActiveServersAreCountryRestricted(): void
    {
        $this->db->exec(
            'UPDATE ' . TestHelper::table('admin_servers') .
            " SET status_id = 1, lb_countries = 'CA' WHERE server_id = 2"
        );

        $this->tester->execute([
            '--force' => true,
            'action' => 'disable',
            'id' => 1,
        ]);

        $output = $this->tester->getDisplay();
        $server = $this->fetchServerRow(1);

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('without country restrictions', $output);
        $this->assertSame(1, (int) $server['status_id']);
        $this->assertClusterDataUnchanged();
    }

    public function testServerDisableRejectsWhenClusterDataFileIsMissing(): void
    {
        unlink($this->kvsPath . '/admin/data/system/cluster.dat');

        $this->tester->execute([
            '--force' => true,
            'action' => 'disable',
            'id' => 1,
        ]);

        $output = $this->tester->getDisplay();
        $server = $this->fetchServerRow(1);

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Storage cluster data file is not writable', $output);
        $this->assertSame(1, (int) $server['status_id']);
        $this->assertFileDoesNotExist($this->kvsPath . '/admin/data/system/cluster.dat');
    }

    public function testServerDisableUpdatesErrorIterationsAndClusterData(): void
    {
        $this->db->exec(
            'UPDATE ' . TestHelper::table('admin_servers') .
            " SET status_id = 1, lb_countries = '' WHERE server_id = 2"
        );

        $this->tester->execute([
            '--force' => true,
            'action' => 'disable',
            'id' => 1,
        ]);

        $output = $this->tester->getDisplay();
        $server = $this->fetchServerRow(1);
        $clusterRows = $this->readClusterRows();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString("Server 'Video Local' disabled successfully", $output);
        $this->assertSame(0, (int) $server['status_id']);
        $this->assertSame(1, (int) $server['error_iteration']);
        $this->assertSame(1, (int) $server['error_streaming_iteration']);
        $this->assertCount(3, $clusterRows);
        $this->assertSame(0, (int) $clusterRows[0]['status_id']);
    }

    public function testServerEnableUpdatesErrorIterationsAndClusterData(): void
    {
        $this->db->exec(
            'UPDATE ' . TestHelper::table('admin_servers') .
            ' SET error_id = 1, error_iteration = 2, error_streaming_id = 6, error_streaming_iteration = 4 ' .
            'WHERE server_id = 2'
        );

        $this->tester->execute([
            '--force' => true,
            'action' => 'enable',
            'id' => 2,
        ]);

        $output = $this->tester->getDisplay();
        $server = $this->fetchServerRow(2);
        $clusterRows = $this->readClusterRows();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString("Server 'Video Disabled' enabled successfully", $output);
        $this->assertSame(1, (int) $server['status_id']);
        $this->assertSame(3, (int) $server['error_iteration']);
        $this->assertSame(5, (int) $server['error_streaming_iteration']);
        $this->assertCount(3, $clusterRows);
        $this->assertSame(1, (int) $clusterRows[1]['status_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchServerRow(int $serverId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . TestHelper::table('admin_servers') . ' WHERE server_id = :id');
        $stmt->execute(['id' => $serverId]);
        $row = $stmt->fetch();
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readClusterRows(): array
    {
        $clusterFile = $this->kvsPath . '/admin/data/system/cluster.dat';
        $this->assertFileExists($clusterFile);

        $content = file_get_contents($clusterFile);
        $this->assertIsString($content);
        $rows = unserialize($content, ['allowed_classes' => false]);
        $this->assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    private function assertClusterDataUnchanged(): void
    {
        $this->assertSame(
            self::INITIAL_CLUSTER_DATA,
            file_get_contents($this->kvsPath . '/admin/data/system/cluster.dat')
        );
    }

    /**
     * @return array<int, int>
     */
    private function prepareWeightFixture(): array
    {
        $table = TestHelper::table('admin_servers');
        $this->db->exec(
            "UPDATE {$table} SET status_id = 1, lb_weight = 1.5, lb_countries = '', "
            . "error_iteration = 7, error_streaming_iteration = 8 WHERE server_id = 1"
        );
        $this->db->exec(
            "UPDATE {$table} SET status_id = 0, lb_weight = 1, lb_countries = 'CA', "
            . "error_iteration = 9, error_streaming_iteration = 10 WHERE server_id = 2"
        );
        $this->db->exec(
            "INSERT INTO {$table} "
            . '(server_id, group_id, content_type_id, title, status_id, connection_type_id, streaming_type_id, '
            . 'is_remote, error_iteration, error_streaming_iteration, error_id, error_streaming_id, '
            . 'streaming_script, streaming_key, is_replace_domain_on_satellite, urls, control_script_url, '
            . 'control_script_url_lock_ip, time_offset, lb_weight, lb_countries, warning_id, added_date) VALUES '
            . "(4, 10, 1, 'Canada CDN', 1, 0, 4, 0, 11, 12, 0, 0, '', 'country-secret-a', 0, "
            . "'https://ca.example.test', '', 0, 0, 2, 'CA', 0, '2026-05-23 10:00:00'), "
            . "(5, 10, 1, 'US CDN', 1, 0, 4, 0, 13, 14, 0, 0, '', 'country-secret-b', 0, "
            . "'https://us.example.test', '', 0, 0, 3, 'US', 0, '2026-05-24 10:00:00'), "
            . "(6, 10, 1, 'Backup', 1, 0, 5, 0, 15, 16, 0, 0, '', 'backup-secret', 0, "
            . "'', '', 0, 0, 1, '', 0, '2026-05-25 10:00:00')"
        );

        return [1 => 4, 2 => 2, 4 => 3, 5 => 1, 6 => 1];
    }

    /**
     * @param array<int, int> $weights
     * @return list<string>
     */
    private function weightOptions(array $weights): array
    {
        $options = [];
        foreach ($weights as $serverId => $weight) {
            $options[] = $serverId . ':' . $weight;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{int, array<string, mixed>, string}
     */
    private function executeWeightJson(array $arguments): array
    {
        $tester = new CommandTester($this->command);
        $tester->execute([
            '--force' => true,
            '--format' => 'json',
            ...$arguments,
        ]);
        $display = $tester->getDisplay();
        $decoded = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return [$tester->getStatusCode(), $decoded, $display];
    }

    private function readWeightRevision(): string
    {
        [$status, $result, $display] = $this->executeWeightJson([
            'action' => 'weights',
            'id' => '10',
        ]);
        $this->assertSame(0, $status, $display);
        $revision = $result['revision'] ?? null;
        $this->assertIsString($revision);

        return $revision;
    }

    private function writeCurrentClusterData(): void
    {
        $publisher = new StorageClusterDataPublisher($this->kvsPath . '/admin/data/system/cluster.dat');
        $rows = $publisher->fetchRows($this->db, TestHelper::table('admin_servers'));
        file_put_contents($this->kvsPath . '/admin/data/system/cluster.dat', serialize($rows));
    }

    private function useClusterPublisher(StorageClusterDataPublisher $publisher): void
    {
        $this->command = $this->createCommand($this->db, $publisher);
        $this->tester = new CommandTester($this->command);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchWeightRows(): array
    {
        $stmt = $this->db->query(
            'SELECT server_id, lb_weight FROM ' . TestHelper::table('admin_servers') . ' ORDER BY server_id'
        );
        $this->assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @return array<int, int>
     */
    private function fetchWeightMap(int $groupId): array
    {
        $stmt = $this->db->prepare(
            'SELECT server_id, lb_weight FROM ' . TestHelper::table('admin_servers')
            . ' WHERE group_id = :group_id ORDER BY server_id'
        );
        $stmt->execute(['group_id' => $groupId]);
        $weights = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $weights[(int) $row['server_id']] = (int) $row['lb_weight'];
        }

        return $weights;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchServerRowsWithoutWeights(int $groupId): array
    {
        $stmt = $this->db->prepare(
            'SELECT server_id, group_id, status_id, streaming_type_id, lb_countries, streaming_script, '
            . 'streaming_key, is_replace_domain_on_satellite, urls, error_iteration, error_streaming_iteration, '
            . 'error_id, error_streaming_id, warning_id FROM ' . TestHelper::table('admin_servers')
            . ' WHERE group_id = :group_id ORDER BY server_id'
        );
        $stmt->execute(['group_id' => $groupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            'control_script_url_lock_ip INTEGER, time_offset REAL, lb_weight REAL, lb_countries TEXT, ' .
            'streaming_script TEXT, streaming_key TEXT, is_replace_domain_on_satellite INTEGER, warning_id INTEGER, ' .
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

    private function createCommand(PDO $db, ?StorageClusterDataPublisher $publisher = null): ServerCommand
    {
        return new class ($this->config, $db, $publisher) extends ServerCommand {
            public function __construct(
                Configuration $config,
                private PDO $testDb,
                private ?StorageClusterDataPublisher $testPublisher
            ) {
                parent::__construct($config);
                $this->setName('system:server');
                $this->setDescription('[EXPERIMENTAL] Manage KVS storage servers');
                $this->setAliases(['server', 'servers']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }

            protected function createStorageClusterDataPublisher(): StorageClusterDataPublisher
            {
                return $this->testPublisher ?? parent::createStorageClusterDataPublisher();
            }
        };
    }
}
