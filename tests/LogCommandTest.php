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
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
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
        file_put_contents(
            $this->tempDir . '/admin/logs/cron.txt',
            "[2024-01-01 12:00:00] INFO: Cron started\n"
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
        $this->assertMatchesRegularExpression('/│\s*cron\s*│\s*cron\.txt\s*│/', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogListOption(): void
    {
        $this->tester->execute(['--list' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('system', $output);
        $this->assertStringContainsString('debug', $output);
        $this->assertMatchesRegularExpression('/│\s*cron\s*│\s*cron\.txt\s*│/', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogListIncludesAdminDataLogs(): void
    {
        mkdir($this->tempDir . '/admin/data/logs', 0755, true);
        file_put_contents($this->tempDir . '/admin/data/logs/email.txt', "[2024-01-01] INFO: Email sent\n");

        $this->tester->execute(['--list' => true]);

        $output = $this->tester->getDisplay();
        $this->assertMatchesRegularExpression('/│\s*email\s*│\s*email\.txt\s*│/', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogListIncludesNestedKvsLogs(): void
    {
        mkdir($this->tempDir . '/admin/logs/plugins', 0755, true);
        file_put_contents($this->tempDir . '/admin/logs/plugins/backup.txt', "[2024-01-01] INFO: Backup done\n");

        $this->tester->execute(['--list' => true]);

        $output = $this->tester->getDisplay();
        $this->assertMatchesRegularExpression('/│\s*plugins\/backup\s*│\s*plugins\/backup\.txt\s*│/', $output);
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

    public function testLogViewReadsAdminDataLogs(): void
    {
        mkdir($this->tempDir . '/admin/data/logs', 0755, true);
        file_put_contents($this->tempDir . '/admin/data/logs/email.txt', "[2024-01-01] INFO: Email sent\n");

        $this->tester->execute(['type' => 'email']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Email sent', $output);
        $this->assertStringContainsString('data', $output);
        $this->assertStringContainsString('/logs/email.txt', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogViewReadsNestedKvsLogs(): void
    {
        mkdir($this->tempDir . '/admin/logs/plugins', 0755, true);
        file_put_contents($this->tempDir . '/admin/logs/plugins/backup.txt', "[2024-01-01] INFO: Backup done\n");

        $this->tester->execute(['type' => 'plugins/backup']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Backup done', $output);
        $this->assertStringContainsString('plugins/backup.txt', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogViewAcceptsListedNestedLogFile(): void
    {
        mkdir($this->tempDir . '/admin/logs/plugins', 0755, true);
        file_put_contents($this->tempDir . '/admin/logs/plugins/backup.txt', "[2024-01-01] INFO: Backup done\n");

        $this->tester->execute(['type' => 'plugins/backup.txt']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Backup done', $output);
        $this->assertStringContainsString('plugins/backup.txt', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testLogViewRejectsParentDirectorySegments(): void
    {
        file_put_contents($this->tempDir . '/admin/include/private.txt', "SECRET-CONTENT\n");

        $this->tester->execute(['type' => '../include/private']);

        $output = $this->tester->getDisplay();
        $this->assertStringNotContainsString('SECRET-CONTENT', $output);
        $this->assertStringContainsString('not found', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
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

    public function testLogTailRejectsInvalidLineCounts(): void
    {
        foreach (['-1', '0', 'abc', '1.5'] as $tail) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'type' => 'system',
                '--tail' => $tail,
            ]);

            $this->assertSame(1, $tester->getStatusCode(), $tail . ': ' . $tester->getDisplay());
            $this->assertStringContainsString('Invalid value for --tail', $tester->getDisplay(), $tail);
            $this->assertStringNotContainsString('System started', $tester->getDisplay(), $tail);
        }
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

    public function testLogClearNoInteractionFailsWithoutClearing(): void
    {
        $logFile = $this->tempDir . '/admin/logs/system.log';
        $originalContent = file_get_contents($logFile);

        $this->tester->execute([
            'type' => 'system',
            '--clear' => true,
            '--no-interaction' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('confirmation was not provided', $output);
        $this->assertSame($originalContent, file_get_contents($logFile));
    }

    public function testFollowReadsReplacementLogFromBeginning(): void
    {
        $logFile = $this->tempDir . '/admin/logs/system.log';
        file_put_contents($logFile, "old\n");
        $lastPosition = (int) filesize($logFile);
        $lastInode = fileinode($logFile);

        rename($logFile, $logFile . '.1');
        file_put_contents($logFile, "ROTATED-BEGIN-LONG-LINE\n");

        $method = new \ReflectionMethod(LogCommand::class, 'readNewFollowLines');
        $args = [$logFile, &$lastPosition, &$lastInode];
        $lines = $method->invokeArgs($this->command, $args);

        $this->assertSame(["ROTATED-BEGIN-LONG-LINE\n"], $lines);
        $this->assertSame((int) filesize($logFile), $lastPosition);
        $this->assertSame(fileinode($logFile), $lastInode);
    }

    public function testLogNotFound(): void
    {
        $this->tester->execute(['type' => 'nonexistent']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
