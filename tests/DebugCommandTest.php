<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Dev\DebugCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class DebugCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private DebugCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/logs', 0755, true);

        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["debug_mode" => false];'
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new DebugCommand($this->config);

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

    public function testDebugEnable(): void
    {
        $this->tester->execute(['action' => 'enable']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Debug mode enabled', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testDebugDisable(): void
    {
        $this->tester->execute(['action' => 'disable']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Debug mode disabled', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testDebugStatus(): void
    {
        $this->tester->execute(['action' => 'status']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Debug mode is', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testDebugClear(): void
    {
        // Create some debug log files
        file_put_contents($this->tempDir . '/admin/logs/debug.log', 'test');
        file_put_contents($this->tempDir . '/admin/logs/error.log', 'test');

        $this->tester->execute(['action' => 'clear']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Debug logs cleared', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // Check files are deleted
        $this->assertFileDoesNotExist($this->tempDir . '/admin/logs/debug.log');
    }

    public function testDebugInfo(): void
    {
        $this->tester->execute(['action' => 'info']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Debug Information', $output);
        $this->assertStringContainsString('PHP Version', $output);
        $this->assertStringContainsString('Memory', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testDebugInvalidAction(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid action', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
