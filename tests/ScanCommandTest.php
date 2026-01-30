<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Migrate\ScanCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class ScanCommandTest extends TestCase
{
    private const TEST_VERSION = '6.4.0';

    private string $tempDir;
    private Configuration $config;
    private ScanCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kvs-scan-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data', 0755, true);
        mkdir($this->tempDir . '/content/videos/sources', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);

        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_version" => "' . self::TEST_VERSION . '", "project_name" => "Test KVS"];'
        );

        file_put_contents(
            $this->tempDir . '/admin/include/version.php',
            "<?php \$config['project_version'] = '" . self::TEST_VERSION . "';"
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new ScanCommand($this->config);

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

    public function testScanCommandShowsInstallation(): void
    {
        $this->tester->execute(['--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('KVS Installation Scan', $output);
        $this->assertStringContainsString('Installation', $output);
        $this->assertStringContainsString('KVS Path', $output);
        $this->assertStringContainsString($this->tempDir, $output);
    }

    public function testScanCommandShowsEnvironment(): void
    {
        $this->tester->execute(['--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Environment', $output);
        $this->assertStringContainsString('Type', $output);
        $this->assertStringContainsString('Standalone', $output);
    }

    public function testScanCommandShowsDatabase(): void
    {
        $this->tester->execute(['--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Database', $output);
        $this->assertStringContainsString('Host', $output);
        $this->assertStringContainsString('Status', $output);
    }

    public function testScanCommandShowsContentStats(): void
    {
        $this->tester->execute(['--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Content Statistics', $output);
        $this->assertStringContainsString('Videos', $output);
        $this->assertStringContainsString('Albums', $output);
        $this->assertStringContainsString('Users', $output);
    }

    public function testScanCommandShowsMigrationSummary(): void
    {
        $this->tester->execute(['--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Migration Summary', $output);
        $this->assertStringContainsString('Database Size', $output);
        $this->assertStringContainsString('Content Size', $output);
        $this->assertStringContainsString('Total Size', $output);
    }

    public function testScanCommandJsonOutput(): void
    {
        $this->tester->execute(['--json' => true, '--force' => true]);

        $output = $this->tester->getDisplay();
        $data = json_decode($output, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('path', $data);
        $this->assertArrayHasKey('ready_for_migration', $data);
        $this->assertArrayHasKey('installation', $data);
        $this->assertArrayHasKey('environment', $data);
        $this->assertArrayHasKey('database', $data);
        $this->assertArrayHasKey('content', $data);
        $this->assertArrayHasKey('storage', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('assessment', $data);
    }

    public function testScanCommandWithExplicitPath(): void
    {
        $this->tester->execute(['path' => $this->tempDir, '--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('KVS Installation Scan', $output);
        $this->assertStringContainsString($this->tempDir, $output);
    }

    public function testScanCommandWithInvalidPath(): void
    {
        $this->tester->execute(['path' => '/nonexistent/path', '--force' => true]);

        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testScanCommandJsonWithInvalidPath(): void
    {
        $this->tester->execute(['path' => '/nonexistent/path', '--json' => true, '--force' => true]);

        $output = $this->tester->getDisplay();
        $data = json_decode($output, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertTrue($data['error']);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testScanCommandShowsKvsVersion(): void
    {
        $this->tester->execute(['--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('KVS Version', $output);
        // Version may be "Unknown" if DB connection fails in test env
        $this->assertTrue(
            str_contains($output, self::TEST_VERSION) || str_contains($output, 'Unknown')
        );
    }

    public function testScanCommandShowsZstdEstimate(): void
    {
        $this->tester->execute(['--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Est. Package Size (zstd)', $output);
    }

    public function testScanCommandShowsAssessment(): void
    {
        $this->tester->execute(['--force' => true]);

        $output = $this->tester->getDisplay();

        // Should show either success or recommendations
        $this->assertTrue(
            str_contains($output, 'Ready for migration') ||
            str_contains($output, 'Cannot migrate') ||
            str_contains($output, 'Recommendations')
        );
    }
}
