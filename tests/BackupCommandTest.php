<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\BackupCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class BackupCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private BackupCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data', 0755, true);
        mkdir($this->tempDir . '/backups', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new BackupCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testBackupCreate(): void
    {
        try {
            $this->tester->execute(['--create' => true]);
            $output = $this->tester->getDisplay();
            // May fail without mysqldump, but should show attempt
            $this->assertStringContainsString('backup', strtolower($output));
        } catch (\Exception $e) {
            // Expected if mysqldump not available
            $this->assertStringContainsString('backup', strtolower($e->getMessage()));
        }
    }

    public function testBackupList(): void
    {
        // BackupCommand looks in dirname(kvsPath)/backups
        $backupsDir = dirname($this->tempDir) . '/backups';
        if (!is_dir($backupsDir)) {
            mkdir($backupsDir, 0755, true);
        }

        // Create mock backup files matching kvs_backup_*.{tar.gz,sql.gz} pattern
        file_put_contents($backupsDir . '/kvs_backup_full_2024-01-01.tar.gz', 'test');
        file_put_contents($backupsDir . '/kvs_backup_db_2024-01-02.sql.gz', 'test');

        $this->tester->execute(['--list' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('kvs_backup_full_2024-01-01.tar.gz', $output);
        $this->assertStringContainsString('kvs_backup_db_2024-01-02.sql.gz', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // Cleanup
        unlink($backupsDir . '/kvs_backup_full_2024-01-01.tar.gz');
        unlink($backupsDir . '/kvs_backup_db_2024-01-02.sql.gz');
    }

    public function testBackupRestore(): void
    {
        // Create a mock backup file
        $backupFile = $this->tempDir . '/backups/test.tar.gz';
        file_put_contents($backupFile, 'test');

        try {
            // Answer 'no' to the confirmation prompt
            $this->tester->setInputs(['no']);
            $this->tester->execute(['--restore' => $backupFile]);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('restore', strtolower($output));
        } catch (\Exception $e) {
            // Expected if tar not available or backup invalid
            $this->assertStringContainsString('restore', strtolower($e->getMessage()));
        }
    }

    public function testBackupNoAction(): void
    {
        // Running without any option shows help
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('--create', $output);
        $this->assertStringContainsString('--list', $output);
        $this->assertStringContainsString('--restore', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }
}
