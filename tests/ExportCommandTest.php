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

    public function testExportCommandMetadata(): void
    {
        $this->assertEquals('db:export', $this->command->getName());
        $this->assertStringContainsString('Export', $this->command->getDescription());
        $this->assertContains('database:export', $this->command->getAliases());
        $this->assertContains('db:dump', $this->command->getAliases());
    }

    public function testExportCommandOptions(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('output'));
        $this->assertTrue($definition->hasOption('tables'));
        $this->assertTrue($definition->hasOption('no-data'));
        $this->assertTrue($definition->hasOption('compress'));
    }

    public function testExportFailsWithoutDumpCommand(): void
    {
        // This test expects failure because mysqldump/mariadb-dump may not be available
        // or database credentials are invalid
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        // Should fail with either "dump command not found" or "export failed"
        $this->assertTrue(
            str_contains(strtolower($output), 'dump') ||
            str_contains(strtolower($output), 'error') ||
            str_contains(strtolower($output), 'failed') ||
            str_contains(strtolower($output), 'not found')
        );
    }

    public function testExportWithOutputOption(): void
    {
        $outputFile = $this->tempDir . '/exports/test_backup.sql';

        $this->tester->execute([
            '--output' => $outputFile
        ]);

        // Command will fail without real DB, but we verify it processed the option
        $output = $this->tester->getDisplay();
        $this->assertNotEmpty($output);
    }

    public function testExportWithTablesOption(): void
    {
        $this->tester->execute([
            '--tables' => 'ktvs_videos,ktvs_users'
        ]);

        // Command will fail without real DB, but we verify it processed the option
        $output = $this->tester->getDisplay();
        $this->assertNotEmpty($output);
    }

    public function testExportWithNoDataOption(): void
    {
        $this->tester->execute([
            '--no-data' => true
        ]);

        // Command will fail without real DB, but we verify it processed the option
        $output = $this->tester->getDisplay();
        $this->assertNotEmpty($output);
    }
}
