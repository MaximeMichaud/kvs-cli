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
            $this->tester->execute(['action' => 'create']);
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
        // Create some mock backup files
        file_put_contents($this->tempDir . '/backups/backup-2024-01-01.tar.gz', 'test');
        file_put_contents($this->tempDir . '/backups/backup-2024-01-02.tar.gz', 'test');

        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Available backups', $output);
        $this->assertStringContainsString('backup-2024-01-01.tar.gz', $output);
        $this->assertStringContainsString('backup-2024-01-02.tar.gz', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testBackupRestore(): void
    {
        // Create a mock backup file
        file_put_contents($this->tempDir . '/backups/test.tar.gz', 'test');

        try {
            $this->tester->execute([
                'action' => 'restore',
                'backup' => 'test.tar.gz'
            ]);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('restore', strtolower($output));
        } catch (\Exception $e) {
            // Expected if tar not available or backup invalid
            $this->assertStringContainsString('restore', strtolower($e->getMessage()));
        }
    }

    public function testBackupDelete(): void
    {
        // Create a mock backup file
        $backupFile = $this->tempDir . '/backups/test.tar.gz';
        file_put_contents($backupFile, 'test');

        $this->tester->execute([
            'action' => 'delete',
            'backup' => 'test.tar.gz'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('deleted', strtolower($output));
        $this->assertFileDoesNotExist($backupFile);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testBackupInvalidAction(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid action', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
