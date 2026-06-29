<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\ConversionCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;

class ConversionCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private ConversionCommand $command;
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

    public function testConversionListBasic(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Main Converter', $output);
        $this->assertStringContainsString('Disabled Converter', $output);
        $this->assertStringContainsString('Error Converter', $output);
    }

    public function testConversionListWithStatusFilter(): void
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
        $this->assertSame([3, 1], array_map(static fn (array $row): int => (int) $row['server_id'], $rows));
        $this->assertSame(['Active', 'Active'], array_column($rows, 'status'));
    }

    public function testConversionListWithErrorsFilter(): void
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
        $this->assertSame('Error Converter', $rows[0]['title']);
        $this->assertSame('Yes', $rows[0]['has_error']);
    }

    public function testMutationsRejectListAndOutputOptionsBeforeLookup(): void
    {
        $cases = [
            ['enable', '--format', 'json', 'format'],
            ['disable', '--fields', 'title', 'fields'],
            ['debug-on', '--format', 'json', 'format'],
            ['debug-off', '--no-truncate', true, 'no-truncate'],
            ['debug-on', '--status', 'active', 'status'],
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
            $this->assertStringNotContainsString('Conversion server not found', $output);
        }
    }

    public function testConversionListMarksPrioritizedMaxTasksLikeKvsAdmin(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,max_tasks',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'server_id');

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('4 (prioritize)', $rowsById[1]['max_tasks']);
        $this->assertSame(2, (int) $rowsById[2]['max_tasks']);
    }

    public function testConversionListExposesKvsAdminServerFields(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => implode(',', [
                'server_id',
                'title',
                'status_id',
                'api_version',
                'tasks_amount',
                'finished_tasks_amount',
                'load',
                'free_space',
                'heartbeat_date',
                'max_tasks',
                'process_priority',
                'connection_type_id',
                'task_types',
                'path',
                'ftp_host',
                'ftp_port',
                'ftp_user',
                'ftp_timeout',
                'is_debug_enabled',
                'added_date',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'server_id');

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('Main Converter', $rowsById[1]['title']);
        $this->assertSame(1, (int) $rowsById[1]['status_id']);
        $this->assertSame('7.0.0', $rowsById[1]['api_version']);
        $this->assertSame(2, (int) $rowsById[1]['tasks_amount']);
        $this->assertSame(2, (int) $rowsById[1]['finished_tasks_amount']);
        $this->assertSame('1.25', $rowsById[1]['load']);
        $this->assertSame('6 GB', $rowsById[1]['free_space']);
        $this->assertSame('2026-05-26 10:00:00', $rowsById[1]['heartbeat_date']);
        $this->assertSame('4 (prioritize)', $rowsById[1]['max_tasks']);
        $this->assertSame(9, (int) $rowsById[1]['process_priority']);
        $this->assertSame(0, (int) $rowsById[1]['connection_type_id']);
        $this->assertSame('+New videos from admins', $rowsById[1]['task_types']);
        $this->assertSame('/tmp/kvs-main-converter', $rowsById[1]['path']);
        $this->assertSame(0, (int) $rowsById[1]['is_debug_enabled']);
        $this->assertSame('2026-05-20 10:00:00', $rowsById[1]['added_date']);
        $this->assertSame('All', $rowsById[2]['task_types']);

        $this->assertSame('ftp.example.test', $rowsById[2]['ftp_host']);
        $this->assertSame('21', $rowsById[2]['ftp_port']);
        $this->assertSame('ftp-user', $rowsById[2]['ftp_user']);
        $this->assertSame('45', $rowsById[2]['ftp_timeout']);
    }

    public function testConversionListRejectsRawCredentialFields(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,ftp_pass',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown field(s): ftp_pass', $this->tester->getDisplay());
    }

    public function testConversionListExposesKvsAdminComputedFields(): void
    {
        $logsDir = $this->kvsPath . '/admin/logs';
        self::assertTrue(is_dir($logsDir) || mkdir($logsDir, 0777, true));
        file_put_contents($logsDir . '/debug_conversion_server_3.txt', 'debug log');

        $this->db->exec('CREATE TABLE ' . TestHelper::table('options') . ' (variable TEXT, value TEXT)');
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('options') .
            " VALUES ('SYSTEM_CONVERSION_API_VERSION', '8.0.0')"
        );

        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => implode(',', [
                'server_id',
                'api_version',
                'free_space_percent',
                'error_text',
                'is_error',
                'has_debug_log',
                'has_old_api',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'server_id');

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('7.0.0 (obsolete)', $rowsById[1]['api_version']);
        $this->assertSame('(60%)', $rowsById[1]['free_space_percent']);
        $this->assertSame('', $rowsById[1]['error_text']);
        $this->assertSame(0, (int) $rowsById[1]['is_error']);
        $this->assertSame(0, (int) $rowsById[1]['has_debug_log']);
        $this->assertSame(1, (int) $rowsById[1]['has_old_api']);

        $this->assertSame('(25%)', $rowsById[3]['free_space_percent']);
        $this->assertSame(
            '(Some libraries are not configured correctly on this server)',
            $rowsById[3]['error_text']
        );
        $this->assertSame(1, (int) $rowsById[3]['is_error']);
        $this->assertSame(1, (int) $rowsById[3]['has_debug_log']);
        $this->assertSame(1, (int) $rowsById[3]['has_old_api']);
    }

    public function testConversionListIgnoresDisabledServerErrorsLikeKvsAdmin(): void
    {
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('admin_conversion_servers') .
            ' (server_id, title, status_id, task_types, is_allow_any_tasks, max_tasks, max_tasks_priority, ' .
            'process_priority, option_storage_servers, option_pull_source_files, is_debug_enabled, connection_type_id, ' .
            'path, ftp_host, ftp_port, ftp_user, ftp_folder, total_space, free_space, `load`, heartbeat_date, ' .
            'api_version, error_id, error_iteration, added_date) VALUES ' .
            "(5, 'Disabled Error Converter', 0, '', 1, 1, 0, 14, 0, 0, 0, 0, " .
            "'/tmp/kvs-disabled-error-converter', '', '', '', '', 1073741824, 536870912, 0.10, " .
            "'2026-05-26 10:00:00', '7.0.0', 2, 3, '2026-05-24 10:00:00')"
        );

        $testerList = new CommandTester($this->command);
        $testerList->execute([
            '--force' => true,
            'action' => 'list',
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,title,has_error',
        ]);

        $rows = json_decode($testerList->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'server_id');

        $this->assertEquals(0, $testerList->getStatusCode());
        $this->assertSame('No', $rowsById[5]['has_error']);

        $testerErrors = new CommandTester($this->command);
        $testerErrors->execute([
            '--force' => true,
            'action' => 'list',
            '--errors' => true,
            '--limit' => 10,
            '--format' => 'json',
            '--fields' => 'server_id,title,has_error',
        ]);

        $errorRows = json_decode($testerErrors->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $testerErrors->getStatusCode());
        $this->assertSame([3], array_map(static fn (array $row): int => (int) $row['server_id'], $errorRows));

        $testerStats = new CommandTester($this->command);
        $testerStats->execute([
            '--force' => true,
            'action' => 'stats',
        ]);

        $this->assertEquals(0, $testerStats->getStatusCode());
        $this->assertMatchesRegularExpression('/With Errors\W+1/', $testerStats->getDisplay());
    }

    public function testConversionListJsonFormat(): void
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
        $this->assertSame(4, (int) $rows[0]['server_id']);
        $this->assertSame('Init Converter', $rows[0]['title']);
        $this->assertSame(0, (int) $rows[0]['tasks_pending']);
        $this->assertEquals(0, $testerJson->getStatusCode());
    }

    public function testConversionListCountFormat(): void
    {
        $testerCount = new CommandTester($this->command);
        $testerCount->execute([
            '--force' => true,
            'action' => 'list',
            '--format' => 'count'
        ]);

        $output = trim($testerCount->getDisplay());
        $this->assertSame('4', $output);
        $this->assertEquals(0, $testerCount->getStatusCode());
    }

    public function testConversionListWithInitStatusFilter(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--status' => 'init',
            '--format' => 'json',
            '--fields' => 'server_id,title,status',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(4, (int) $rows[0]['server_id']);
        $this->assertSame('Init Converter', $rows[0]['title']);
        $this->assertSame('Initializing', $rows[0]['status']);
    }

    public function testConversionShow(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '1'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion Server #1', $output);
        $this->assertStringContainsString('Main Converter', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertMatchesRegularExpression('/Max Tasks\W+4/', $output);
        $this->assertMatchesRegularExpression('/Tasks Pending\W+2/', $output);
        $this->assertMatchesRegularExpression('/Tasks Completed\W+2/', $output);
        $this->assertStringContainsString('10 GB', $output);
        $this->assertStringContainsString('6 GB', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConversionShowSupportsJsonFormat(): void
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
        $this->assertSame('Main Converter', $rows[0]['title']);
        $this->assertSame(['video_admins'], $rows[0]['task_types']);
        $this->assertStringNotContainsString('Conversion Server #1', $output);
    }

    public function testConversionShowRejectsCountFormat(): void
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

    public function testConversionShowHonorsFieldsSelectionInTableFormat(): void
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
        $this->assertStringNotContainsString('Conversion Server #1', $output);
        $this->assertStringNotContainsString('Property', $output);
    }

    public function testConversionShowRejectsNonIntegerIdBeforeQuery(): void
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

    public function testConversionShowParsesSerializedTaskTypes(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '1',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertMatchesRegularExpression('/\x{2713}\s+New videos from admins/u', $output);
    }

    public function testConversionShowTreatsEmptyTaskTypesAsAllTypesLikeKvsAdmin(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '2',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('No specific task types assigned (processes all types)', $output);
        $this->assertMatchesRegularExpression('/\x{2713}\s+Process any available task when free/u', $output);
    }

    public function testConversionShowDisplaysFtpConnectionInfo(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => '2'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Connection', $output);
        $this->assertStringContainsString('FTP', $output);
        $this->assertStringContainsString('FTP Host', $output);
        $this->assertStringContainsString('ftp.example.test:21', $output);
        $this->assertStringContainsString('FTP User', $output);
        $this->assertStringContainsString('ftp-user', $output);
        $this->assertStringContainsString('FTP Folder', $output);
        $this->assertStringContainsString('/incoming', $output);
    }

    public function testConversionShowNotFound(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion server not found: 999999', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionShowMissingId(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionStats(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'stats'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion Statistics', $output);
        $this->assertMatchesRegularExpression('/Total Servers\W+4/', $output);
        $this->assertMatchesRegularExpression('/Active\W+2/', $output);
        $this->assertMatchesRegularExpression('/Inactive\W+1/', $output);
        $this->assertMatchesRegularExpression('/Initializing\W+1/', $output);
        $this->assertMatchesRegularExpression('/With Errors\W+1/', $output);
        $this->assertStringContainsString('11 concurrent tasks', $output);
        $this->assertMatchesRegularExpression('/Pending\W+1/', $output);
        $this->assertMatchesRegularExpression('/Processing\W+1/', $output);
        $this->assertMatchesRegularExpression('/Failed\W+1/', $output);
        $this->assertMatchesRegularExpression('/Completed \(history\)\W+3/', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConversionStatsSupportsJsonFormat(): void
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
        $this->assertSame(4, (int) ($rowsByMetric['Total Servers']['value'] ?? 0));
        $this->assertSame(1, (int) ($rowsByMetric['Inactive']['value'] ?? 0));
        $this->assertArrayNotHasKey('Disabled', $rowsByMetric);
        $this->assertSame('task_queue', $rowsByMetric['Pending']['section'] ?? null);
        $this->assertSame('task_queue', $rowsByMetric['Failed']['section'] ?? null);
        $this->assertSame(1, (int) ($rowsByMetric['Failed']['value'] ?? 0));
        $this->assertStringNotContainsString('Conversion Statistics', $this->tester->getDisplay());
    }

    public function testConversionCommandMetadata(): void
    {
        $this->assertEquals('system:conversion', $this->command->getName());
        $this->assertStringContainsString('conversion', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('conversion', $aliases);
    }

    public function testConversionDefaultAction(): void
    {
        $this->tester->execute(['--force' => true]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Main Converter', $this->tester->getDisplay());
    }

    public function testConversionRejectsUnknownAction(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'unknown_action',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Unknown conversion action "unknown_action"', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionEnableMissingId(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'enable'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion server ID is required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionDisableMissingId(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'disable'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion server ID is required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionEnableNotFound(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'enable',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion server not found: 999999', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionLogRejectsFtpServer(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'log',
            'id' => '2'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Log viewing only available for Local/Mount servers', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionLogRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'log',
            'id' => '1abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Server ID', $this->tester->getDisplay());
    }

    public function testConversionLogRejectsCountFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'log',
            'id' => '1',
            '--format' => 'count',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('log action does not support --format=count', $this->tester->getDisplay());
    }

    public function testConversionLogJsonReportsMissingFileAsStructuredPayload(): void
    {
        $serverPath = $this->kvsPath . '/conversion-server-missing-log';
        $this->insertLocalConversionServer(50, 'Missing Log Converter', $serverPath);

        $this->tester->execute([
            '--force' => true,
            'action' => 'log',
            'id' => '50',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertSame('50', $rows[0]['server_id']);
        $this->assertSame('Missing Log Converter', $rows[0]['title']);
        $this->assertSame($serverPath . '/log.txt', $rows[0]['file']);
        $this->assertFalse($rows[0]['exists']);
        $this->assertSame('Log file not found', $rows[0]['message']);
        $this->assertStringNotContainsString('[WARNING]', $output);
    }

    public function testConversionLogFallsBackToCurrentInstallConversionPath(): void
    {
        $conversionPath = $this->kvsPath . '/admin/data/conversion';
        mkdir($conversionPath, 0755, true);
        file_put_contents($conversionPath . '/log.txt', "INFO  Local conversion log\n");
        file_put_contents($conversionPath . '/cron_log.txt', "INFO  Local conversion cron\n");
        $this->insertLocalConversionServer(
            52,
            'Moved Local Converter',
            '/old/kvs/root/admin/data/conversion'
        );

        $this->tester->execute([
            '--force' => true,
            'action' => 'log',
            'id' => '52',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Local conversion log', $output);
        $this->assertStringNotContainsString('Local conversion cron', $output);
        $this->assertStringNotContainsString('/old/kvs/root', $output);
    }

    public function testConversionLogRejectsUnknownFieldsWithFailureStatus(): void
    {
        $conversionPath = $this->kvsPath . '/conversion-server-log-fields';
        mkdir($conversionPath, 0755, true);
        file_put_contents($conversionPath . '/log.txt', "INFO  Local conversion log\n");
        $this->insertLocalConversionServer(54, 'Log Fields Converter', $conversionPath);

        $this->tester->execute([
            '--force' => true,
            'action' => 'log',
            'id' => '54',
            '--fields' => 'definitely_bad',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Unknown field(s): definitely_bad', $output);
        $this->assertStringNotContainsString('In Formatter.php line', $output);
    }

    public function testConversionDebugOnMissingId(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'debug-on'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server ID is required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionDebugOffMissingId(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'debug-off'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server ID is required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionDebugOnNotFound(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'debug-on',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion server not found: 999999', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionConfigRejectsFtpServer(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'config',
            'id' => '2'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Config viewing only available for Local/Mount servers', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionConfigRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'config',
            'id' => '1abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Server ID', $this->tester->getDisplay());
    }

    public function testConversionConfigRejectsCountFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'config',
            'id' => '1',
            '--format' => 'count',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('config action does not support --format=count', $this->tester->getDisplay());
    }

    public function testConversionConfigJsonReportsMissingFileAsStructuredPayload(): void
    {
        $serverPath = $this->kvsPath . '/conversion-server-missing-config';
        $this->insertLocalConversionServer(51, 'Missing Config Converter', $serverPath);

        $this->tester->execute([
            '--force' => true,
            'action' => 'config',
            'id' => '51',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertSame('51', $rows[0]['server_id']);
        $this->assertSame('Missing Config Converter', $rows[0]['title']);
        $this->assertSame($serverPath . '/config.properties', $rows[0]['file']);
        $this->assertFalse($rows[0]['exists']);
        $this->assertSame('Config file not found', $rows[0]['message']);
        $this->assertStringNotContainsString('[WARNING]', $output);
    }

    public function testConversionConfigFallsBackToCurrentInstallConversionPath(): void
    {
        $conversionPath = $this->kvsPath . '/admin/data/conversion';
        mkdir($conversionPath, 0755, true);
        file_put_contents($conversionPath . '/config.properties', "max.tasks=4\n");
        file_put_contents($conversionPath . '/heartbeat.dat', serialize(['libraries' => []]));
        $this->insertLocalConversionServer(
            53,
            'Moved Config Converter',
            '/old/kvs/root/admin/data/conversion'
        );

        $this->tester->execute([
            '--force' => true,
            'action' => 'config',
            'id' => '53',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertSame($conversionPath . '/config.properties', $rows[0]['file']);
        $this->assertSame("max.tasks=4\n", $rows[0]['content']);
        $this->assertTrue($rows[0]['heartbeat_exists']);
    }

    public function testConversionConfigRejectsUnknownFieldsWithFailureStatus(): void
    {
        $conversionPath = $this->kvsPath . '/conversion-server-config-fields';
        mkdir($conversionPath, 0755, true);
        file_put_contents($conversionPath . '/config.properties', "max.tasks=4\n");
        file_put_contents($conversionPath . '/heartbeat.dat', serialize(['libraries' => []]));
        $this->insertLocalConversionServer(55, 'Config Fields Converter', $conversionPath);

        $this->tester->execute([
            '--force' => true,
            'action' => 'config',
            'id' => '55',
            '--fields' => 'definitely_bad',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Unknown field(s): definitely_bad', $output);
        $this->assertStringNotContainsString('In Formatter.php line', $output);
    }

    public function testConversionLogMissingId(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'log'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server ID is required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionLogNotFound(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'log',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion server not found: 999999', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionConfigMissingId(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'config'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server ID is required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionConfigNotFound(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'config',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion server not found: 999999', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionShowRejectsListOnlyOptions(): void
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

    public function testConversionStatsRejectsListOnlyOptions(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'stats',
            '--errors' => true,
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('The stats action does not support --errors', $this->tester->getDisplay());
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_conversion_servers') . ' (' .
            'server_id INTEGER, title TEXT, status_id INTEGER, task_types TEXT, is_allow_any_tasks INTEGER, ' .
            'max_tasks INTEGER, max_tasks_priority INTEGER, process_priority INTEGER, option_storage_servers INTEGER, ' .
            'option_pull_source_files INTEGER, is_debug_enabled INTEGER, connection_type_id INTEGER, path TEXT, ' .
            'ftp_host TEXT, ftp_port TEXT, ftp_user TEXT, ftp_pass TEXT, ftp_folder TEXT, ftp_timeout TEXT, total_space INTEGER, ' .
            'free_space INTEGER, `load` REAL, heartbeat_date TEXT, api_version TEXT, error_id INTEGER, ' .
            'error_iteration INTEGER, added_date TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('background_tasks') . ' (' .
            'task_id INTEGER, status_id INTEGER, server_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('background_tasks_history') . ' (' .
            'task_id INTEGER, status_id INTEGER, server_id INTEGER)'
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('admin_conversion_servers') .
            ' (server_id, title, status_id, task_types, is_allow_any_tasks, max_tasks, max_tasks_priority, ' .
            'process_priority, option_storage_servers, option_pull_source_files, is_debug_enabled, connection_type_id, ' .
            'path, ftp_host, ftp_port, ftp_user, ftp_pass, ftp_folder, ftp_timeout, total_space, free_space, `load`, heartbeat_date, ' .
            'api_version, error_id, error_iteration, added_date) VALUES ' .
            "(1, 'Main Converter', 1, 'a:1:{i:0;s:12:\"video_admins\";}', 0, 4, 1, 9, 1, 0, 0, 0, " .
            "'/tmp/kvs-main-converter', '', '', '', '', '', '', 10737418240, 6442450944, 1.25, " .
            "'2026-05-26 10:00:00', '7.0.0', 0, 0, '2026-05-20 10:00:00'), " .
            "(2, 'Disabled Converter', 0, '', 1, 2, 0, 14, 0, 0, 0, 2, " .
            "'', 'ftp.example.test', '21', 'ftp-user', 'hidden-fixture-value', '/incoming', '45', 5368709120, 1073741824, 0.10, " .
            "'0000-00-00 00:00:00', '7.0.0', 0, 0, '2026-05-21 10:00:00'), " .
            "(3, 'Error Converter', 1, '', 1, 4, 0, 4, 1, 1, 1, 0, " .
            "'/tmp/kvs-error-converter', '', '', '', '', '', '', 2147483648, 536870912, 3.50, " .
            "'2026-05-26 10:00:00', '7.0.0', 4, 3, '2026-05-22 10:00:00'), " .
            "(4, 'Init Converter', 2, '', 1, 1, 0, 19, 0, 0, 0, 1, " .
            "'/mnt/kvs-init-converter', '', '', '', '', '', '', 1073741824, 536870912, 0.00, " .
            "'2026-05-26 10:00:00', '7.0.0', 0, 0, '2026-05-23 10:00:00')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('background_tasks') .
            ' VALUES (1, 0, 1), (2, 1, 1), (3, 2, 3)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('background_tasks_history') .
            ' VALUES (10, 3, 1), (11, 3, 1), (12, 4, 3)'
        );

        return $db;
    }

    private function insertLocalConversionServer(int $serverId, string $title, string $path): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ' . TestHelper::table('admin_conversion_servers') .
            ' (server_id, title, status_id, task_types, is_allow_any_tasks, max_tasks, max_tasks_priority, ' .
            'process_priority, option_storage_servers, option_pull_source_files, is_debug_enabled, connection_type_id, ' .
            'path, ftp_host, ftp_port, ftp_user, ftp_pass, ftp_folder, ftp_timeout, total_space, free_space, `load`, heartbeat_date, ' .
            'api_version, error_id, error_iteration, added_date) VALUES ' .
            "(:server_id, :title, 1, '', 1, 1, 0, 14, 0, 0, 0, 0, " .
            ":path, '', '', '', '', '', '', 1073741824, 536870912, 0.10, " .
            "'2026-05-26 10:00:00', '7.0.0', 0, 0, '2026-05-24 10:00:00')"
        );
        $stmt->execute([
            'server_id' => $serverId,
            'title' => $title,
            'path' => $path,
        ]);
    }

    private function createCommand(PDO $db): ConversionCommand
    {
        return new class ($this->config, $db) extends ConversionCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:conversion');
                $this->setDescription('[EXPERIMENTAL] Manage KVS conversion servers');
                $this->setAliases(['conversion']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
