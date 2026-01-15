<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\MaintenanceCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class MaintenanceCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private MaintenanceCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create temporary KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data/system', 0755, true);
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        // Setup command
        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new MaintenanceCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        // Cleanup
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testMaintenanceStatus(): void
    {
        $this->tester->execute(['action' => 'status']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Maintenance mode is DISABLED', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testMaintenanceEnable(): void
    {
        $this->tester->execute(['action' => 'on']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Maintenance mode enabled', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // Check file was created
        $settingsFile = $this->tempDir . '/admin/data/system/website_ui_params.dat';
        $this->assertFileExists($settingsFile);

        $settings = unserialize(file_get_contents($settingsFile));
        $this->assertEquals(1, $settings['DISABLE_WEBSITE']);
    }

    public function testMaintenanceDisable(): void
    {
        // First enable
        $this->tester->execute(['action' => 'on']);

        // Then disable
        $this->tester->execute(['action' => 'off']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Maintenance mode disabled', $output);

        // Check file was updated
        $settingsFile = $this->tempDir . '/admin/data/system/website_ui_params.dat';
        $settings = unserialize(file_get_contents($settingsFile));
        $this->assertEquals(0, $settings['DISABLE_WEBSITE']);
    }

    public function testInvalidMode(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid action', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
