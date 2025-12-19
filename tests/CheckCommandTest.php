<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\CheckCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class CheckCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private CheckCommand $command;
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
            '<?php $config["project_version"] = "6.3.2";'
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new CheckCommand($this->config);

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

    public function testCheckCommandOutput(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Check for expected sections
        $this->assertStringContainsString('KVS Configuration Check', $output);
        $this->assertStringContainsString('KVS Update', $output);
        $this->assertStringContainsString('PHP & KVS Compatibility', $output);
        $this->assertStringContainsString('PHP Extensions', $output);
        $this->assertStringContainsString('System Tools', $output);
        $this->assertStringContainsString('OPcache', $output);
        $this->assertStringContainsString('PHP Settings', $output);
        $this->assertStringContainsString('System Load', $output);
        $this->assertStringContainsString('Disk Space', $output);
        $this->assertStringContainsString('Internet Connectivity', $output);
    }

    public function testCheckCommandMetadata(): void
    {
        $this->assertEquals('system:check', $this->command->getName());
        $this->assertContains('check', $this->command->getAliases());
        $this->assertStringContainsString('configuration', $this->command->getDescription());
    }

    public function testCheckCommandHasJsonOption(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('json'));
        $this->assertTrue($definition->hasOption('quiet-ok'));
    }

    public function testCheckCommandJsonOutput(): void
    {
        $this->tester->execute(['--json' => true]);

        $output = $this->tester->getDisplay();

        // Parse JSON output
        $data = json_decode($output, true);

        // Should be valid JSON
        $this->assertNotNull($data, 'Output should be valid JSON');
        $this->assertIsArray($data);

        // Should have results and summary
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('summary', $data);

        // Summary should have error/warning counts
        $this->assertArrayHasKey('errors', $data['summary']);
        $this->assertArrayHasKey('warnings', $data['summary']);
    }

    public function testCheckCommandQuietOkOption(): void
    {
        $this->tester->execute(['--quiet-ok' => true]);

        $output = $this->tester->getDisplay();

        // Should still have the title
        $this->assertStringContainsString('KVS Configuration Check', $output);

        // Should have result summary
        $this->assertMatchesRegularExpression('/error|warning|passed/i', $output);
    }

    public function testCheckPhpSettings(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should check various PHP settings
        $this->assertStringContainsString('upload_max_filesize', $output);
        $this->assertStringContainsString('memory_limit', $output);
    }

    public function testCheckSystemLoad(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should show load average (unless not available)
        $hasLoad = str_contains($output, 'Load Average') || str_contains($output, 'N/A');
        $this->assertTrue($hasLoad, 'Should show Load Average or N/A');
    }

    public function testCheckDiskSpace(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should show disk usage percentage
        $this->assertMatchesRegularExpression('/\d+(\.\d+)?%\s+used/', $output);
    }

    public function testCheckInternetConnectivity(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should check Cloudflare (our connectivity test)
        $this->assertStringContainsString('Cloudflare', $output);
    }

    public function testCheckToolsSection(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should check for FFmpeg and ImageMagick
        $this->assertStringContainsString('FFmpeg', $output);
        $this->assertStringContainsString('ImageMagick', $output);
    }

    public function testCheckOpcacheSection(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should check OPcache settings
        $this->assertStringContainsString('OPcache', $output);
        $hasOpcacheInfo = str_contains($output, 'memory_consumption') ||
                          str_contains($output, 'Disabled') ||
                          str_contains($output, 'Extension not loaded');
        $this->assertTrue($hasOpcacheInfo, 'Should show OPcache information');
    }

    public function testCheckPhpExtensionsSection(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should check PHP extensions
        $this->assertStringContainsString('PHP Extensions', $output);
        // Should check for required extensions
        $this->assertStringContainsString('curl', $output);
        $this->assertStringContainsString('mbstring', $output);
    }

    public function testCheckKvsUpdateSection(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should show KVS Update section
        $this->assertStringContainsString('KVS Update', $output);
        // Should show current version or version info
        $hasVersionInfo = str_contains($output, 'Current Version') ||
                          str_contains($output, 'Version') ||
                          str_contains($output, 'detect');
        $this->assertTrue($hasVersionInfo, 'Should show version information');
    }

    public function testCheckFfmpegCodecs(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // If FFmpeg is found, should check codecs
        if (str_contains($output, 'FFmpeg') && !str_contains($output, 'Not found')) {
            $hasCodecInfo = str_contains($output, 'Codecs') ||
                            str_contains($output, 'libx264') ||
                            str_contains($output, 'AAC');
            $this->assertTrue($hasCodecInfo, 'Should show codec information when FFmpeg is available');
        } else {
            $this->assertTrue(true, 'FFmpeg not available, skipping codec test');
        }
    }
}
