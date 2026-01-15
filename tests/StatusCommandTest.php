<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\StatusCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class StatusCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private StatusCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data', 0755, true);
        mkdir($this->tempDir . '/content', 0755, true);

        // Create config files
        TestHelper::createMockDbConfig($this->tempDir);

        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_version" => "6.3.2", "project_name" => "Test KVS"];'
        );

        file_put_contents(
            $this->tempDir . '/admin/include/version.php',
            '<?php define("KVS_VERSION", "6.3.2");'
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new StatusCommand($this->config);

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

    public function testStatusCommandOutput(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Check for expected sections
        $this->assertStringContainsString('KVS System Status', $output);
        $this->assertStringContainsString('Installation', $output);
        $this->assertStringContainsString('Version', $output);
        $this->assertStringContainsString('Path', $output);
        $this->assertStringContainsString($this->tempDir, $output);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testStatusWithVerboseOutput(): void
    {
        $this->tester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        $output = $this->tester->getDisplay();

        // Verbose mode should show more details
        $this->assertStringContainsString('Database', $output);
        $this->assertStringContainsString('PHP Version', $output);
        $this->assertStringContainsString(PHP_VERSION, $output);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testStatusShowsSystemInfo(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should show system information (Installation section has this info)
        $this->assertStringContainsString('Installation', $output);

        // Should show version info
        $this->assertStringContainsString('Version', $output);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testStatusChecksDiskSpace(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should show disk information
        $this->assertStringContainsString('Disk', $output);
        $this->assertMatchesRegularExpression('/\d+(\.\d+)?\s*(GB|MB)/', $output); // Should show size

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testStatusExitCode(): void
    {
        // StatusCommand doesn't support --format option
        // Just verify the command runs successfully
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('KVS System Status', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }
}
