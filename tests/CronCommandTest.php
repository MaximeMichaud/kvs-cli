<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\CronCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class CronCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private CronCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation with cron files
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);

        // Create mock cron files
        file_put_contents($this->tempDir . '/admin/include/cron.php', '<?php echo "Main cron";');
        file_put_contents($this->tempDir . '/admin/include/cron_conversion.php', '<?php echo "Conversion cron";');
        file_put_contents($this->tempDir . '/admin/include/cron_optimize.php', '<?php echo "Optimize cron";');
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new CronCommand($this->config);

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

    public function testCronRunAll(): void
    {
        // Running without arguments runs all cron tasks
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Running all cron tasks', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCronRunSpecificTask(): void
    {
        $this->tester->execute(['task' => 'conversion']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Running cron task: conversion', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCronList(): void
    {
        $this->tester->execute(['--list' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('cron.php', $output);
        $this->assertStringContainsString('cron_conversion.php', $output);
        $this->assertStringContainsString('cron_optimize.php', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCronStatus(): void
    {
        $this->tester->execute(['--status' => true]);

        $output = $this->tester->getDisplay();
        // Without status file, shows warning
        $this->assertStringContainsString('status', strtolower($output));
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCronInvalidTask(): void
    {
        $this->tester->execute(['task' => 'nonexistent']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Unknown cron task', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
