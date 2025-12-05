<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

class IntegrationTest extends TestCase
{
    private string $tempDir;
    private Application $app;
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        $this->createMockKvsInstallation($this->tempDir);

        $this->app = new Application();
        $this->tester = new ApplicationTester($this->app);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    private function createMockKvsInstallation(string $dir): void
    {
        // Use TestHelper to create complete mock KVS installation
        TestHelper::createMockKvsInstallation($dir, [
            'project_version' => '6.3.2',
            'project_name' => 'Test KVS'
        ]);
    }

    public function testFullWorkflow(): void
    {
        // Test listing commands with KVS path
        $this->tester->run([
            'command' => 'list',
            '--path' => $this->tempDir
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('maintenance', $output);
        $this->assertStringContainsString('config', $output);
        $this->assertStringContainsString('system:status', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testMaintenanceModeToggle(): void
    {
        // Enable maintenance mode
        $this->tester->run([
            'command' => 'maintenance',
            'mode' => 'on',
            '--path' => $this->tempDir
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Maintenance mode enabled', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // Check status
        $this->tester->run([
            'command' => 'maintenance',
            'mode' => 'status',
            '--path' => $this->tempDir
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('ENABLED', $output);

        // Disable maintenance mode
        $this->tester->run([
            'command' => 'maintenance',
            'mode' => 'off',
            '--path' => $this->tempDir
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Maintenance mode disabled', $output);
    }

    public function testSystemStatusCommand(): void
    {
        $this->tester->run([
            'command' => 'system:status',
            '--path' => $this->tempDir
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('KVS System Status', $output);
        $this->assertStringContainsString('Version', $output);
        $this->assertStringContainsString('6.3.2', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCacheManagement(): void
    {
        // Create some cache files
        file_put_contents($this->tempDir . '/admin/data/engine/cache.dat', 'cache');
        file_put_contents($this->tempDir . '/admin/smarty/cache/test.cache', 'smarty cache');

        // Get cache info
        $this->tester->run([
            'command' => 'system:cache',
            'action' => 'info',
            '--path' => $this->tempDir
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Cache Information', $output);

        // Clear cache
        $this->tester->run([
            'command' => 'system:cache',
            'action' => 'clear',
            '--path' => $this->tempDir
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Cache cleared', $output);

        // Verify files are gone
        $this->assertFileDoesNotExist($this->tempDir . '/admin/data/engine/cache.dat');
        $this->assertFileDoesNotExist($this->tempDir . '/admin/smarty/cache/test.cache');
    }

    public function testConfigurationManagement(): void
    {
        // List config
        $this->tester->run([
            'command' => 'config',
            'action' => 'list',
            '--path' => $this->tempDir
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('KVS Configuration', $output);
        $this->assertStringContainsString('project_version', $output);
        $this->assertStringContainsString('6.3.2', $output);

        // Get specific value
        $this->tester->run([
            'command' => 'config',
            'action' => 'get',
            'key' => 'project_version',
            '--path' => $this->tempDir
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('6.3.2', $output);
    }

    public function testEnvironmentAutoDetection(): void
    {
        // Change to KVS directory and test auto-detection
        $oldCwd = getcwd();
        chdir($this->tempDir);

        $app = new Application();
        $tester = new ApplicationTester($app);

        $tester->run(['command' => 'list']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('maintenance', $output);

        chdir($oldCwd);
    }
}
