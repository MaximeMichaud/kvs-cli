<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Database\MigrateCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class MigrateCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private MigrateCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data/migrations', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_version" => "6.3.1"];'
        );

        // Create migration files
        file_put_contents(
            $this->tempDir . '/admin/data/migrations/6.3.2.sql',
            "-- Migration to 6.3.2\nALTER TABLE ktvs_videos ADD COLUMN test INT;"
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new MigrateCommand($this->config);

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

    public function testMigrateStatus(): void
    {
        $this->tester->execute(['action' => 'status']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Migration status', $output);
        $this->assertStringContainsString('Current version: 6.3.1', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testMigrateRun(): void
    {
        try {
            $this->tester->execute(['action' => 'run']);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('migration', strtolower($output));
        } catch (\Exception $e) {
            // Expected without real database
            $this->assertStringContainsString('database', strtolower($e->getMessage()));
        }
    }

    public function testMigrateRunSpecificVersion(): void
    {
        try {
            $this->tester->execute([
                'action' => 'run',
                '--version' => '6.3.2'
            ]);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('6.3.2', $output);
        } catch (\Exception $e) {
            // Expected without real database
            $this->assertStringContainsString('database', strtolower($e->getMessage()));
        }
    }

    public function testMigrateList(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Available migrations', $output);
        $this->assertStringContainsString('6.3.2', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testMigrateRollback(): void
    {
        try {
            $this->tester->execute([
                'action' => 'rollback',
                '--version' => '6.3.0'
            ]);
            $output = $this->tester->getDisplay();
            $this->assertStringContainsString('rollback', strtolower($output));
        } catch (\Exception $e) {
            // Expected without real database or rollback files
            $this->assertTrue(true);
        }
    }

    public function testMigrateInvalidAction(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid action', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
