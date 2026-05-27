<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Dev\DebugCommand;
use KVS\CLI\Config\Configuration;
use PDO;
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
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
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

    public function testDebugInfoShowsZeroMaxExecutionTimeAsUnlimited(): void
    {
        $previousValue = ini_get('max_execution_time');
        ini_set('max_execution_time', '0');

        try {
            $this->tester->execute(['--info' => true]);

            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('Max Execution Time', $output);
            $this->assertStringContainsString('0 (unlimited)', $output);
            $this->assertStringNotContainsString('Unknown', $output);
            $this->assertEquals(0, $this->tester->getStatusCode());
        } finally {
            if ($previousValue !== false) {
                ini_set('max_execution_time', $previousValue);
            }
        }
    }

    public function testDebugCheck(): void
    {
        $this->tester->execute(['--check' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('System Checks', $output);
        $this->assertStringContainsString('PHP Version', $output);
        // Status code depends on checks passing
    }

    public function testDebugCheckUsesConfiguredPhpRuntimeHelpers(): void
    {
        $tester = new CommandTester($this->createRuntimeAwareDebugCommand());

        $tester->execute(['--check' => true]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('9.9.9-container', $output);
        $this->assertMatchesRegularExpression('/PHP Extension: pdo\s*│\s*Missing\s*│\s*ERROR/', $output);
        $this->assertMatchesRegularExpression('/PHP Extension: json\s*│\s*Installed\s*│\s*OK/', $output);
    }

    public function testDebugInfoUsesConfiguredPhpRuntimeHelpers(): void
    {
        $tester = new CommandTester($this->createRuntimeAwareDebugCommand());

        $tester->execute(['--info' => true]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $output);
        $this->assertStringContainsString('9.9.9-container', $output);
        $this->assertStringContainsString('512M', $output);
        $this->assertStringContainsString('0 (unlimited)', $output);
        $this->assertMatchesRegularExpression('/Display Errors\s*│\s*Off/', $output);
        $this->assertMatchesRegularExpression('/Log Errors\s*│\s*On/', $output);
        $this->assertStringContainsString('/container/error.log', $output);
    }

    public function testDebugTestDb(): void
    {
        $this->tester->execute(['--test-db' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Database Connection Test', $output);
        // Will show config or error depending on DB setup
    }

    private function createRuntimeAwareDebugCommand(): DebugCommand
    {
        return new class ($this->config) extends DebugCommand {
            protected function getKvsPhpVersion(): string
            {
                return '9.9.9-container';
            }

            protected function isExtensionLoaded(string $extension): bool
            {
                return $extension !== 'pdo';
            }

            protected function getPhpSetting(string $name): string|false
            {
                return match ($name) {
                    'memory_limit' => '512M',
                    'max_execution_time' => '0',
                    'display_errors' => '0',
                    'log_errors' => '1',
                    'error_log' => '/container/error.log',
                    default => false,
                };
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return new PDO('sqlite::memory:');
            }
        };
    }
}
