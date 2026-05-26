<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\CronCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CronCommandStatusTest extends TestCase
{
    private string $kvsPath;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testStatusReadsAdminProcessesTable(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_admin_processes (pid TEXT NOT NULL, last_exec_date TEXT NOT NULL)');
        $db->exec("INSERT INTO ktvs_admin_processes (pid, last_exec_date) VALUES ('cron_cleanup', '2026-05-26 12:30:01')");
        $db->exec("INSERT INTO ktvs_admin_processes (pid, last_exec_date) VALUES ('cron_custom', '0000-00-00 00:00:00')");

        $command = new class (TestHelper::createTestConfiguration($this->kvsPath), $db) extends CronCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute(['--status' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('cron_cleanup', $display);
        $this->assertStringContainsString('2026-05-26 12:30:01', $display);
        $this->assertStringContainsString('cron_custom', $display);
        $this->assertStringContainsString('Never', $display);
        $this->assertStringNotContainsString('No cron status information available', $display);
    }
}
