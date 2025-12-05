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

    public function testLogView(): void
    {
        $this->tester->execute(['action' => 'view']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('System started', $output);
        $this->assertStringContainsString('Test error', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogViewSpecificFile(): void
    {
        $this->tester->execute([
            'action' => 'view',
            '--file' => 'debug'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Debug message', $output);
        $this->assertStringNotContainsString('System started', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogTail(): void
    {
        $this->tester->execute([
            'action' => 'tail',
            '--lines' => '1'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('ERROR: Test error', $output);
        $this->assertStringNotContainsString('System started', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogClear(): void
    {
        $this->tester->execute(['action' => 'clear']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Logs cleared', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // Check files are cleared
        $this->assertEquals('', file_get_contents($this->tempDir . '/admin/logs/system.log'));
    }

    public function testLogSearch(): void
    {
        $this->tester->execute([
            'action' => 'search',
            '--pattern' => 'ERROR'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Test error', $output);
        $this->assertStringNotContainsString('System started', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogList(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Available log files', $output);
        $this->assertStringContainsString('system.log', $output);
        $this->assertStringContainsString('debug.log', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogInvalidAction(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid action', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
