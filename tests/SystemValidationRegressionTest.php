<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Settings\OptionsCommand;
use KVS\CLI\Command\Settings\VideoFormatCommand;
use KVS\CLI\Command\System\ConversionCommand;
use KVS\CLI\Command\System\QueueCommand;
use KVS\CLI\Command\System\ServerCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SystemValidationRegressionTest extends TestCase
{
    private string $tempDir;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-system-validation-regression-');
        TestHelper::createMockKvsInstallation($this->tempDir);
        $this->db = $this->createSqliteConnection();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            TestHelper::removeDir($this->tempDir);
        }
    }

    public function testQueueDefaultsToListAction(): void
    {
        $this->createQueueTables();
        $tester = new CommandTester($this->createQueueCommand());
        $tester->execute(['--format' => 'count']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame("0\n", $tester->getDisplay());
    }

    public function testQueueRejectsInvalidStatus(): void
    {
        $this->createQueueTables();
        $tester = new CommandTester($this->createQueueCommand());
        $tester->execute(['action' => 'list', '--status' => 'bogus', '--format' => 'count']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid status', $tester->getDisplay());
    }

    public function testQueueRejectsNegativeLimitBeforeSql(): void
    {
        $this->createQueueTables();
        $tester = new CommandTester($this->createQueueCommand());
        $tester->execute(['action' => 'list', '--limit' => '-1', '--format' => 'count']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --limit', $tester->getDisplay());
    }

    public function testQueueShowHistoryUsesConversionServerNameAndHistoryFields(): void
    {
        $this->createQueueTables();
        $this->db->exec("INSERT INTO ktvs_admin_conversion_servers VALUES (1, 'Local')");
        $this->db->exec(
            "INSERT INTO ktvs_background_tasks_history " .
            "(task_id, status_id, type_id, video_id, album_id, server_id, error_code, priority, " .
            "start_date, end_date, effective_duration) " .
            "VALUES (10, 3, 2, 20, 0, 1, 0, 0, '2026-05-15 00:11:02', '2026-05-15 00:12:02', 60)"
        );

        $tester = new CommandTester($this->createQueueCommand());
        $tester->execute(['action' => 'show', 'id' => '10']);
        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/Server\W+Local/', $output);
        $this->assertStringNotContainsString('Server #1', $output);
        $this->assertStringNotContainsString('Added', $output);
        $this->assertStringNotContainsString('Restarts', $output);
    }

    public function testOptionsRejectInvalidCategory(): void
    {
        $tester = new CommandTester($this->createOptionsCommand());
        $tester->execute([
            'action' => 'list',
            '--category' => 'doesnotexist',
            '--format' => 'count',
            '--force' => true,
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --category', $tester->getDisplay());
    }

    public function testOptionsSystemCategoryDoesNotMatchUserPrefix(): void
    {
        $this->db->exec('CREATE TABLE ktvs_options (variable TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec("INSERT INTO ktvs_options VALUES ('ENABLE_FEATURE', '1')");
        $this->db->exec("INSERT INTO ktvs_options VALUES ('USER_AVATAR_SIZE', '200')");
        $this->db->exec("INSERT INTO ktvs_options VALUES ('USE_POST_DATE_RANDOMIZATION', '1')");

        $tester = new CommandTester($this->createOptionsCommand());
        $tester->execute([
            'action' => 'list',
            '--category' => 'system',
            '--format' => 'json',
            '--force' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $systemRows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $systemVariables = array_column($systemRows, 'variable');
        $this->assertContains('ENABLE_FEATURE', $systemVariables);
        $this->assertNotContains('USER_AVATAR_SIZE', $systemVariables);
        $this->assertNotContains('USE_POST_DATE_RANDOMIZATION', $systemVariables);

        $websiteTester = new CommandTester($this->createOptionsCommand());
        $websiteTester->execute([
            'action' => 'list',
            '--category' => 'website',
            '--format' => 'json',
            '--force' => true,
        ]);

        $this->assertSame(0, $websiteTester->getStatusCode(), $websiteTester->getDisplay());
        $websiteRows = json_decode($websiteTester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $websiteVariables = array_column($websiteRows, 'variable');
        $this->assertNotContains('USER_AVATAR_SIZE', $websiteVariables);
        $this->assertContains('USE_POST_DATE_RANDOMIZATION', $websiteVariables);
    }

    public function testOptionsListNoTruncateShowsFullLongValues(): void
    {
        $this->db->exec('CREATE TABLE ktvs_options (variable TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $longValue = '%total_videos%*20 + %total_albums%*20 + %total_comments%*10 + %logins%';
        $stmt = $this->db->prepare('INSERT INTO ktvs_options VALUES (:variable, :value)');
        $stmt->execute(['variable' => 'ACTIVITY_INDEX_FORMULA', 'value' => $longValue]);

        $defaultTester = new CommandTester($this->createOptionsCommand());
        $defaultTester->execute([
            'action' => 'list',
            '--search' => 'ACTIVITY_INDEX_FORMULA',
            '--force' => true,
        ]);

        $fullTester = new CommandTester($this->createOptionsCommand());
        $fullTester->execute([
            'action' => 'list',
            '--search' => 'ACTIVITY_INDEX_FORMULA',
            '--no-truncate' => true,
            '--force' => true,
        ]);

        $this->assertSame(0, $defaultTester->getStatusCode(), $defaultTester->getDisplay());
        $this->assertSame(0, $fullTester->getStatusCode(), $fullTester->getDisplay());
        $this->assertStringContainsString('%total_videos%*20 + %total_albums%*20 + %total_...', $defaultTester->getDisplay());
        $this->assertStringContainsString($longValue, $fullTester->getDisplay());
    }

    public function testOptionsSetRequiresYesInNonInteractiveMode(): void
    {
        $this->db->exec('CREATE TABLE ktvs_options (variable TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec("INSERT INTO ktvs_options VALUES ('CODEX_OPTION', '0')");

        $tester = new CommandTester($this->createOptionsCommand());
        $tester->execute([
            'action' => 'set',
            'name' => 'CODEX_OPTION',
            'value' => '1',
            '--force' => true,
        ], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Use --yes', $tester->getDisplay());
        $this->assertSame('0', $this->db->query("SELECT value FROM ktvs_options WHERE variable = 'CODEX_OPTION'")->fetchColumn());
    }

    public function testOptionsSetYesUpdatesInNonInteractiveMode(): void
    {
        $this->db->exec('CREATE TABLE ktvs_options (variable TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec("INSERT INTO ktvs_options VALUES ('CODEX_OPTION', '0')");

        $tester = new CommandTester($this->createOptionsCommand());
        $tester->execute([
            'action' => 'set',
            'name' => 'CODEX_OPTION',
            'value' => '1',
            '--force' => true,
            '--yes' => true,
        ], ['interactive' => false]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('1', $this->db->query("SELECT value FROM ktvs_options WHERE variable = 'CODEX_OPTION'")->fetchColumn());
    }

    public function testServerRejectsInvalidTypeStatusAndConnection(): void
    {
        foreach (['type', 'status', 'connection'] as $option) {
            $tester = new CommandTester($this->createServerCommand());
            $tester->execute([
                'action' => 'list',
                '--' . $option => 'bogus',
                '--format' => 'count',
                '--force' => true,
            ]);

            $this->assertSame(1, $tester->getStatusCode());
            $this->assertStringContainsString("Invalid value for --$option", $tester->getDisplay());
        }
    }

    public function testServerRejectsNegativeLimitBeforeSql(): void
    {
        $this->createServerTables();
        $tester = new CommandTester($this->createServerCommand());
        $tester->execute([
            'action' => 'list',
            '--limit' => '-1',
            '--format' => 'count',
            '--force' => true,
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --limit', $tester->getDisplay());
        $this->assertStringNotContainsString('syntax error', strtolower($tester->getDisplay()));
    }

    public function testConversionRejectsInvalidStatus(): void
    {
        $tester = new CommandTester($this->createConversionCommand());
        $tester->execute([
            'action' => 'list',
            '--status' => 'bogus',
            '--format' => 'count',
            '--force' => true,
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --status', $tester->getDisplay());
    }

    public function testVideoFormatRejectsInvalidStatus(): void
    {
        $tester = new CommandTester($this->createVideoFormatCommand());
        $tester->execute([
            'action' => 'list',
            '--status' => 'bogus',
            '--force' => true,
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --status: bogus', $tester->getDisplay());
    }

    public function testVideoFormatShowRejectsNonTableFormat(): void
    {
        $this->createVideoFormatTables();
        $tester = new CommandTester($this->createVideoFormatCommand());
        $tester->execute([
            'action' => 'show',
            'id' => '1',
            '--format' => 'json',
            '--force' => true,
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('The show action only supports table output', $tester->getDisplay());
        $this->assertStringNotContainsString('Video Format #1', $tester->getDisplay());
    }

    public function testVideoFormatGroupsRejectsListFilters(): void
    {
        $this->createVideoFormatTables();

        foreach (['status' => 'required', 'group' => '1'] as $option => $value) {
            $tester = new CommandTester($this->createVideoFormatCommand());
            $tester->execute([
                'action' => 'groups',
                '--' . $option => $value,
                '--format' => 'json',
                '--force' => true,
            ]);

            $this->assertSame(1, $tester->getStatusCode());
            $this->assertStringContainsString("The groups action does not support --$option", $tester->getDisplay());
            $this->assertStringNotContainsString('Default', $tester->getDisplay());
        }
    }

    public function testConversionRejectsNegativeLimitBeforeSql(): void
    {
        $this->createConversionTables();
        $tester = new CommandTester($this->createConversionCommand());
        $tester->execute([
            'action' => 'list',
            '--limit' => '-1',
            '--format' => 'count',
            '--force' => true,
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --limit', $tester->getDisplay());
        $this->assertStringNotContainsString('syntax error', strtolower($tester->getDisplay()));
    }

    private function createSqliteConnection(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $db;
    }

    private function createQueueTables(): void
    {
        $this->db->exec('CREATE TABLE ktvs_background_tasks (
            task_id INTEGER,
            status_id INTEGER,
            type_id INTEGER,
            video_id INTEGER,
            album_id INTEGER,
            server_id INTEGER,
            error_code INTEGER,
            priority INTEGER,
            added_date TEXT
        )');
        $this->db->exec('CREATE TABLE ktvs_background_tasks_history (
            task_id INTEGER,
            status_id INTEGER,
            type_id INTEGER,
            video_id INTEGER,
            album_id INTEGER,
            server_id INTEGER,
            error_code INTEGER,
            priority INTEGER,
            start_date TEXT,
            effective_duration INTEGER,
            end_date TEXT
        )');
        $this->db->exec('CREATE TABLE ktvs_admin_conversion_servers (server_id INTEGER, title TEXT)');
    }

    private function createServerTables(): void
    {
        $this->db->exec('CREATE TABLE ktvs_admin_servers (
            server_id INTEGER,
            group_id INTEGER,
            title TEXT,
            content_type_id INTEGER,
            status_id INTEGER,
            streaming_type_id INTEGER,
            connection_type_id INTEGER,
            total_space INTEGER,
            free_space INTEGER,
            load REAL,
            error_iteration INTEGER,
            error_streaming_iteration INTEGER,
            urls TEXT
        )');
        $this->db->exec('CREATE TABLE ktvs_admin_servers_groups (group_id INTEGER, title TEXT)');
    }

    private function createConversionTables(): void
    {
        $this->db->exec('CREATE TABLE ktvs_admin_conversion_servers (
            server_id INTEGER,
            title TEXT,
            status_id INTEGER,
            process_priority INTEGER,
            total_space INTEGER,
            free_space INTEGER,
            load REAL,
            error_iteration INTEGER,
            is_debug_enabled INTEGER,
            max_tasks INTEGER,
            api_version TEXT,
            heartbeat_date TEXT
        )');
        $this->db->exec('CREATE TABLE ktvs_background_tasks (status_id INTEGER, server_id INTEGER)');
        $this->db->exec('CREATE TABLE ktvs_background_tasks_history (server_id INTEGER)');
    }

    private function createVideoFormatTables(): void
    {
        $this->db->exec('CREATE TABLE ktvs_formats_videos (
            format_video_id INTEGER,
            title TEXT,
            postfix TEXT,
            status_id INTEGER,
            size TEXT,
            access_level_id INTEGER,
            is_download_enabled INTEGER,
            is_timeline_enabled INTEGER,
            format_video_group_id INTEGER,
            ffmpeg_options TEXT
        )');
        $this->db->exec('CREATE TABLE ktvs_formats_videos_groups (
            format_video_group_id INTEGER,
            title TEXT,
            is_default INTEGER,
            is_premium INTEGER
        )');
        $this->db->exec(
            "INSERT INTO ktvs_formats_videos_groups " .
            "(format_video_group_id, title, is_default, is_premium) VALUES (1, 'Default', 1, 0)"
        );
        $this->db->exec(
            "INSERT INTO ktvs_formats_videos " .
            "(format_video_id, title, postfix, status_id, size, access_level_id, is_download_enabled, " .
            "is_timeline_enabled, format_video_group_id, ffmpeg_options) " .
            "VALUES (1, 'MP4 480p', '.mp4', 1, '848x480', 0, 0, 1, 1, '-vcodec libx264')"
        );
    }

    private function createQueueCommand(): QueueCommand
    {
        return new class ($this->createConfig(), $this->db) extends QueueCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:queue');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createOptionsCommand(): OptionsCommand
    {
        return new class ($this->createConfig(), $this->db) extends OptionsCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('settings:options');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createServerCommand(): ServerCommand
    {
        return new class ($this->createConfig(), $this->db) extends ServerCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:server');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createConversionCommand(): ConversionCommand
    {
        return new class ($this->createConfig(), $this->db) extends ConversionCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:conversion');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createVideoFormatCommand(): VideoFormatCommand
    {
        return new class ($this->createConfig(), $this->db) extends VideoFormatCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('settings:video-format');
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
