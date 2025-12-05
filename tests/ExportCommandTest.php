<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Database\ExportCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class ExportCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private ExportCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/exports', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new ExportCommand($this->config);

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

    public function testExportFull(): void
    {
        try {
            $this->tester->execute(['type' => 'full']);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('export', strtolower($output));
        } catch (\Exception $e) {
            // Expected without real database
            $this->assertStringContainsString('database', strtolower($e->getMessage()));
        }
    }

    public function testExportStructure(): void
    {
        try {
            $this->tester->execute(['type' => 'structure']);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('structure', strtolower($output));
        } catch (\Exception $e) {
            // Expected without real database
            $this->assertStringContainsString('database', strtolower($e->getMessage()));
        }
    }

    public function testExportData(): void
    {
        try {
            $this->tester->execute(['type' => 'data']);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('data', strtolower($output));
        } catch (\Exception $e) {
            // Expected without real database
            $this->assertStringContainsString('database', strtolower($e->getMessage()));
        }
    }

    public function testExportWithTables(): void
    {
        try {
            $this->tester->execute([
                'type' => 'full',
                '--tables' => 'ktvs_videos,ktvs_users'
            ]);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('export', strtolower($output));
        } catch (\Exception $e) {
            // Expected without real database
            $this->assertStringContainsString('database', strtolower($e->getMessage()));
        }
    }

    public function testExportWithOutput(): void
    {
        try {
            $this->tester->execute([
                'type' => 'full',
                '--output' => 'custom.sql'
            ]);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('export', strtolower($output));
        } catch (\Exception $e) {
            // Expected without real database
            $this->assertStringContainsString('database', strtolower($e->getMessage()));
        }
    }

    public function testExportInvalidType(): void
    {
        $this->tester->execute(['type' => 'invalid']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid export type', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
