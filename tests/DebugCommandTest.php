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

    public function testDebugInfo(): void
    {
        // Default behavior shows debug info
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Debug Information', $output);
        $this->assertStringContainsString('PHP Version', $output);
        $this->assertStringContainsString('Memory', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testDebugInfoOption(): void
    {
        $this->tester->execute(['--info' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Debug Information', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testDebugCheck(): void
    {
        $this->tester->execute(['--check' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('System Checks', $output);
        $this->assertStringContainsString('PHP Version', $output);
        // Status code depends on checks passing
    }

    public function testDebugTestDb(): void
    {
        $this->tester->execute(['--test-db' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Database Connection Test', $output);
        // Will show config or error depending on DB setup
    }
}
