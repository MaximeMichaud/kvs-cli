<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\CronCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\InvalidOptionException;

class CronCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private CronCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation with cron files
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);

        // Create mock cron files
        file_put_contents($this->tempDir . '/admin/include/cron.php', '<?php echo "Main cron";');
        file_put_contents($this->tempDir . '/admin/include/cron_conversion.php', '<?php echo "Conversion cron";');
        file_put_contents($this->tempDir . '/admin/include/cron_optimize.php', '<?php echo "Optimize cron";');
        file_put_contents($this->tempDir . '/admin/include/cron_plugins.php', '<?php echo "Plugins cron";');
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

    public function testCronUsesConfiguredPhpPathWhenPhpIsNotInPath(): void
    {
        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);
        $phpPath = $toolsDir . '/custom-php';
        file_put_contents(
            $phpPath,
            <<<'SH'
#!/bin/sh
echo configured php used: "${1##*/}"
SH
        );
        chmod($phpPath, 0755);

        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["php_path" => "' . addslashes($phpPath) . '"];'
        );

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . '/empty');

        try {
            $tester = new CommandTester(new CronCommand(new Configuration(['path' => $this->tempDir])));
            $tester->execute(['task' => 'conversion']);

            $output = $tester->getDisplay();
            $this->assertSame(0, $tester->getStatusCode(), $output);
            $this->assertStringContainsString('configured php used: cron_conversion.php', $output);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testCronList(): void
    {
        $this->tester->execute(['--list' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('cron.php', $output);
        $this->assertStringContainsString('cron_conversion.php', $output);
        $this->assertStringContainsString('cron_optimize.php', $output);
        $this->assertStringContainsString('cron_billing.php', $output);
        $this->assertStringContainsString('cron_clone_db.php', $output);
        $this->assertStringContainsString('cron_custom.php', $output);
        $this->assertStringContainsString('cron_import.php', $output);
        $this->assertStringContainsString('cron_plugins.php', $output);
        $this->assertStringContainsString('cron_servers.php', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCronRunSupportsScriptBasenameAliases(): void
    {
        $this->tester->execute(['task' => 'cron_plugins']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Running cron task: cron_plugins', $output);
        $this->assertStringContainsString('Plugins cron', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCronHelpDoesNotExposeUnsupportedForceOption(): void
    {
        $this->tester->execute(['--help' => true]);

        $this->assertStringNotContainsString('--force', $this->tester->getDisplay());
    }

    public function testCronRejectsUnsupportedForceOption(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('The "--force" option does not exist');

        $this->tester->execute(['task' => 'conversion', '--force' => true]);
    }

    public function testCronStatus(): void
    {
        $this->tester->execute(['--status' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Database configuration missing', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testCronInvalidTask(): void
    {
        $this->tester->execute(['task' => 'nonexistent']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Unknown cron task', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
