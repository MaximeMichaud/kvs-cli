<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Settings\OptionsCommand;
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
        $this->tempDir = sys_get_temp_dir() . '/kvs-system-validation-regression-' . uniqid();
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

    private function createConfig(): Configuration
    {
        return new Configuration(['path' => $this->tempDir]);
    }
}
