<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Dev\LogCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class LogCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private LogCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation with log files
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/logs', 0755, true);

        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        // Create sample log files
        file_put_contents(
            $this->tempDir . '/admin/logs/system.log',
            "[2024-01-01 12:00:00] INFO: System started\n[2024-01-01 12:01:00] ERROR: Test error\n"
        );
        file_put_contents(
            $this->tempDir . '/admin/logs/debug.log',
            "[2024-01-01 12:00:00] DEBUG: Debug message\n"
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new LogCommand($this->config);

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

    public function testLogList(): void
    {
        // Default behavior (no args) shows list
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('system', $output);
        $this->assertStringContainsString('debug', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogListOption(): void
    {
        $this->tester->execute(['--list' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('system', $output);
        $this->assertStringContainsString('debug', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogViewSpecificType(): void
    {
        $this->tester->execute(['type' => 'system']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('System started', $output);
        $this->assertStringContainsString('Test error', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogViewDebug(): void
    {
        $this->tester->execute(['type' => 'debug']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Debug message', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogTail(): void
    {
        $this->tester->execute([
            'type' => 'system',
            '--tail' => '1'
        ]);

        $output = $this->tester->getDisplay();
        // Shows the log content
        $this->assertStringContainsString('Log: system', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogClear(): void
    {
        // Answer 'no' to confirmation
        $this->tester->setInputs(['no']);
        $this->tester->execute([
            'type' => 'system',
            '--clear' => true
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('clear', strtolower($output));
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogNotFound(): void
    {
        $this->tester->execute(['type' => 'nonexistent']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
