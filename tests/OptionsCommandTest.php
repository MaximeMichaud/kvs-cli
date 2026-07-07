<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Settings\OptionsCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class OptionsCommandTest extends TestCase
{
    private string $kvsPath;
    private PDO $db;
    private OptionsCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE ' . TestHelper::table('options') . ' (variable TEXT PRIMARY KEY, value TEXT)');
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('options') .
            " (variable, value) VALUES " .
            "('ENABLE_DVD_FIELD_1', '1'), ('ENABLEXDUMMY', '1'), ('SCREENSHOTS_SIZE', '320x180')"
        );

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->command = new class ($config, $this->db) extends OptionsCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testGetOptionSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'ENABLE_DVD_FIELD_1',
            '--format' => 'json',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $rows = json_decode($display, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame('ENABLE_DVD_FIELD_1', $rows[0]['variable'] ?? null);
        $this->assertSame('1', $rows[0]['value'] ?? null);
        $this->assertSame('System', $rows[0]['category'] ?? null);
        $this->assertSame('Enabled', $rows[0]['status'] ?? null);
        $this->assertStringNotContainsString('Option:', $display);
    }

    public function testGetOptionKeepsTableOutputByDefault(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'SCREENSHOTS_SIZE',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Option: SCREENSHOTS_SIZE', $display);
        $this->assertStringContainsString('Dimension (WxH)', $display);
    }

    public function testGetOptionHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'ENABLE_DVD_FIELD_1',
            '--fields' => 'variable',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Variable', $display);
        $this->assertStringContainsString('ENABLE_DVD_FIELD_1', $display);
        $this->assertStringNotContainsString('Option: ENABLE_DVD_FIELD_1', $display);
        $this->assertStringNotContainsString('Category', $display);
    }

    public function testListEmptyResultHonorsFieldsSelectionInTableFormat(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '__missing_option__',
            '--fields' => 'variable',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('No results found.', $display);
        $this->assertStringNotContainsString('No options found matching criteria', $display);
    }

    public function testListEmptyResultRejectsUnknownFieldsSelection(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => '__missing_option__',
            '--fields' => 'definitely_bad',
            '--format' => 'json',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Unknown field(s): definitely_bad', $display);
        $this->assertNotSame("[]\n", $display);
    }

    public function testListRejectsEmptyPrefixAndSearchFilters(): void
    {
        foreach (['prefix', 'search'] as $option) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--' . $option => '',
                '--format' => 'count',
                '--force' => true,
            ]);

            $display = $tester->getDisplay();

            $this->assertSame(1, $tester->getStatusCode(), "--$option: $display");
            $this->assertStringContainsString("Invalid value for --$option", $display);
        }
    }

    public function testListPrefixTreatsUnderscoreAsLiteralPrefixSeparator(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--prefix' => 'ENABLE',
            '--fields' => 'variable',
            '--format' => 'json',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $rows = json_decode($display, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame(['ENABLE_DVD_FIELD_1'], array_column($rows, 'variable'));
    }

    public function testListRejectsSetOnlyYesOption(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('list action does not support --yes', $display);
    }

    public function testListRejectsIgnoredNameArgument(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'name' => 'ENABLE_DVD_FIELD_1',
            '--format' => 'count',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('list action does not support option name or value arguments', $display);
        $this->assertNotSame("2\n", $display);
    }

    public function testGetRejectsSetOnlyYesOption(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'ENABLE_DVD_FIELD_1',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('get action does not support --yes', $display);
        $this->assertStringNotContainsString('ENABLE_DVD_FIELD_1', $display);
    }

    public function testGetRejectsIgnoredValueArgument(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'ENABLE_DVD_FIELD_1',
            'value' => 'ignored-value',
            '--format' => 'json',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('get action does not support a value argument', $display);
        $this->assertStringNotContainsString('ENABLE_DVD_FIELD_1', $display);
    }

    public function testGetRejectsCountFormat(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'ENABLE_DVD_FIELD_1',
            '--format' => 'count',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('get action does not support --format=count', $display);
    }

    public function testSetRejectsListOnlyFiltersBeforeUpdatingOption(): void
    {
        $this->tester->execute([
            'action' => 'set',
            'name' => 'ENABLE_DVD_FIELD_1',
            'value' => '0',
            '--prefix' => 'ENABLE',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $value = $this->db->query(
            'SELECT value FROM ' . TestHelper::table('options') . " WHERE variable = 'ENABLE_DVD_FIELD_1'"
        )->fetchColumn();

        $this->assertSame(1, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('set action does not support --prefix', $display);
        $this->assertSame('1', $value);
    }

    public function testSetRejectsOutputOptionsBeforeLookingUpOption(): void
    {
        $cases = [
            ['--format', 'json', 'format'],
            ['--fields', 'variable', 'fields'],
            ['--no-truncate', true, 'no-truncate'],
        ];

        foreach ($cases as [$option, $value, $optionName]) {
            $this->tester->execute([
                'action' => 'set',
                'name' => 'KVS_CLI_DOES_NOT_EXIST',
                'value' => '1',
                $option => $value,
                '--force' => true,
            ]);

            $display = $this->tester->getDisplay();

            $this->assertSame(1, $this->tester->getStatusCode(), $optionName . ': ' . $display);
            $this->assertStringContainsString("set action does not support --$optionName", $display);
            $this->assertStringNotContainsString('Option not found', $display);
        }
    }

    public function testSetShowsValidCacheClearCommandHint(): void
    {
        $this->tester->execute([
            'action' => 'set',
            'name' => 'ENABLE_DVD_FIELD_1',
            'value' => '0',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $value = $this->db->query(
            'SELECT value FROM ' . TestHelper::table('options') . " WHERE variable = 'ENABLE_DVD_FIELD_1'"
        )->fetchColumn();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame('0', $value);
        $normalizedDisplay = preg_replace('/\s+/', ' ', $display) ?? $display;
        $this->assertStringContainsString('kvs cache', $normalizedDisplay);
        $this->assertStringContainsString('--clear', $normalizedDisplay);
        $this->assertStringNotContainsString('kvs cache clear', $normalizedDisplay);
    }

    public function testSetRotatorScheduleIntervalUpdatesAdminProcessLikeKvs(): void
    {
        $this->db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_processes') .
            ' (pid TEXT PRIMARY KEY, exec_interval INTEGER NOT NULL)'
        );
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('options') .
            " (variable, value) VALUES ('ROTATOR_SCHEDULE_INTERVAL', '15')"
        );
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('admin_processes') .
            " (pid, exec_interval) VALUES ('cron_rotator', 900)"
        );

        $this->tester->execute([
            'action' => 'set',
            'name' => 'ROTATOR_SCHEDULE_INTERVAL',
            'value' => '20',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame(
            1200,
            (int) $this->db->query(
                'SELECT exec_interval FROM ' . TestHelper::table('admin_processes') .
                " WHERE pid = 'cron_rotator'"
            )->fetchColumn()
        );
    }

    public function testSetBackgroundTasksPauseDisabledRemovesPauseFileLikeKvs(): void
    {
        $systemDir = $this->kvsPath . '/admin/data/system';
        self::assertTrue(is_dir($systemDir) || mkdir($systemDir, 0777, true));
        $pauseFile = $systemDir . '/background_tasks_pause.dat';
        file_put_contents($pauseFile, '1');
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('options') .
            " (variable, value) VALUES ('ENABLE_BACKGROUND_TASKS_PAUSE', '1')"
        );

        $this->tester->execute([
            'action' => 'set',
            'name' => 'ENABLE_BACKGROUND_TASKS_PAUSE',
            'value' => '0',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertFileDoesNotExist($pauseFile);
    }

    public function testSetRotatorScreenshotsEnableResetsVideoCountersLikeKvs(): void
    {
        $this->db->exec(
            'CREATE TABLE ' . TestHelper::table('videos') .
            ' (video_id INTEGER PRIMARY KEY, rs_dlist INTEGER NOT NULL, rs_ccount INTEGER NOT NULL)'
        );
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('options') .
            " (variable, value) VALUES ('ROTATOR_SCREENSHOTS_ENABLE', '0')"
        );
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('videos') .
            ' (video_id, rs_dlist, rs_ccount) VALUES (1, 15, 7), (2, 3, 11)'
        );

        $this->tester->execute([
            'action' => 'set',
            'name' => 'ROTATOR_SCREENSHOTS_ENABLE',
            'value' => '1',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame(
            0,
            (int) $this->db->query(
                'SELECT COUNT(*) FROM ' . TestHelper::table('videos') .
                ' WHERE rs_dlist <> 0 OR rs_ccount <> 0'
            )->fetchColumn()
        );
    }

    public function testSetMainServerMinFreeSpaceUpdatesPrimaryDiskNotificationLikeKvs(): void
    {
        $this->createAdminNotificationsTable();
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('options') .
            " (variable, value) VALUES ('MAIN_SERVER_MIN_FREE_SPACE_MB', '1')"
        );
        $freeBytes = disk_free_space($this->kvsPath);
        $this->assertNotFalse($freeBytes);
        $thresholdMb = (string) ((int) ceil($freeBytes / 1024 / 1024) + 1024);

        $this->tester->execute([
            'action' => 'set',
            'name' => 'MAIN_SERVER_MIN_FREE_SPACE_MB',
            'value' => $thresholdMb,
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $row = $this->db->query(
            'SELECT objects, details FROM ' . TestHelper::table('admin_notifications') .
            " WHERE notification_id = 'settings.general.primary_disk_space'"
        )->fetch();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertIsArray($row);
        $this->assertSame(1, (int) $row['objects']);
        $this->assertSame('[' . $thresholdMb . ']', $row['details']);
    }

    public function testSetKeepVideoSourceFilesDisabledClearsProtectionNotificationLikeKvs(): void
    {
        $this->createAdminNotificationsTable();
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('options') .
            " (variable, value) VALUES ('KEEP_VIDEO_SOURCE_FILES', '1')"
        );
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('admin_notifications') .
            " (notification_id, objects, details) VALUES " .
            "('settings.general.video_source_files_protection', 1, '[]')"
        );

        $this->tester->execute([
            'action' => 'set',
            'name' => 'KEEP_VIDEO_SOURCE_FILES',
            'value' => '0',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $count = $this->db->query(
            'SELECT COUNT(*) FROM ' . TestHelper::table('admin_notifications') .
            " WHERE notification_id = 'settings.general.video_source_files_protection'"
        )->fetchColumn();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame(0, (int) $count);
    }

    public function testSetGeneralSystemOptionWritesKvsAuditLog(): void
    {
        $this->createAdminAuditLogTable();
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('options') .
            " (variable, value) VALUES ('KEEP_VIDEO_SOURCE_FILES', '1')"
        );

        $this->tester->execute([
            'action' => 'set',
            'name' => 'KEEP_VIDEO_SOURCE_FILES',
            'value' => '0',
            '--yes' => true,
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $row = $this->db->query(
            'SELECT username, action_id, object_id, object_type_id, action_details FROM ' .
            TestHelper::table('admin_audit_log')
        )->fetch();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertIsArray($row);
        $this->assertSame('kvs-cli', $row['username']);
        $this->assertSame(220, (int) $row['action_id']);
        $this->assertSame(0, (int) $row['object_id']);
        $this->assertSame(30, (int) $row['object_type_id']);
        $this->assertSame('KEEP_VIDEO_SOURCE_FILES', $row['action_details']);
    }

    public function testListRejectsConflictingEnabledDisabledFilters(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--enabled' => true,
            '--disabled' => true,
            '--format' => 'count',
            '--force' => true,
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('cannot be used together', $this->tester->getDisplay());
    }

    private function createAdminNotificationsTable(): void
    {
        $this->db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_notifications') .
            ' (notification_id TEXT PRIMARY KEY, objects INTEGER NOT NULL DEFAULT 0, details TEXT NOT NULL DEFAULT "[]")'
        );
    }

    private function createAdminAuditLogTable(): void
    {
        $this->db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_audit_log') .
            ' (
                record_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                username TEXT NOT NULL,
                action_id INTEGER NOT NULL,
                object_id INTEGER NOT NULL,
                object_type_id INTEGER NOT NULL,
                action_details TEXT NOT NULL,
                added_date TEXT NOT NULL
            )'
        );
    }
}
