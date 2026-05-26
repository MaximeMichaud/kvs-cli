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
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
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
        $this->assertTrue($definition->hasOption('format'));
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

    public function testCheckCommandFormatJsonOutput(): void
    {
        $this->tester->execute(['--format' => 'json']);

        $data = json_decode($this->tester->getDisplay(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('summary', $data);
    }

    public function testCheckCommandRejectsInvalidFormat(): void
    {
        $this->tester->execute(['--format' => 'xml']);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --format', $this->tester->getDisplay());
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

    public function testQuietOkOmitsEmptySectionHeaders(): void
    {
        $this->tester->execute(['--quiet-ok' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringNotContainsString("PHP Extensions\n--------------", $output);
        $this->assertStringNotContainsString("IonCube\n-------", $output);
        $this->assertStringContainsString('Memcached', $output);
        $this->assertStringContainsString('Failed to connect', $output);
    }

    public function testFfmpegFilterDetectionIgnoresVersionHeaderOnStderr(): void
    {
        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);
        $ffmpeg = $toolsDir . '/ffmpeg';
        file_put_contents(
            $ffmpeg,
            <<<'SH'
#!/bin/sh
if [ "$1" = "-encoders" ]; then
  echo ' V..... libx264 H.264'
  echo ' A..... aac AAC'
  exit 0
fi
if [ "$1" = "-filters" ]; then
  echo 'ffmpeg version test' >&2
  echo 'built with test' >&2
  echo 'configuration: test' >&2
  echo 'libavutil test' >&2
  echo 'libavcodec test' >&2
  echo 'Filters:'
  echo ' T.. scale V->V Scale the input video size'
  exit 0
fi
exit 1
SH
        );
        chmod($ffmpeg, 0755);

        $method = new \ReflectionMethod(CheckCommand::class, 'checkFfmpegCodecs');
        $result = $method->invoke($this->command, $ffmpeg);

        $this->assertIsArray($result);
        $this->assertTrue($result['libavfilter']);
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

    public function testCheckCronUsesNativeKvsAdminProcessesSchema(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_admin_processes (
            pid TEXT PRIMARY KEY,
            last_exec_date TEXT,
            last_exec_duration INTEGER,
            exec_interval INTEGER,
            exec_tod INTEGER,
            status_data TEXT
        )');

        $now = date('Y-m-d H:i:s');
        foreach (['main', 'cron_optimize', 'cron_conversion', 'cron_check_db'] as $pid) {
            $quotedPid = $db->quote($pid);
            $quotedNow = $db->quote($now);
            $db->exec(
                "INSERT INTO ktvs_admin_processes
                    (pid, last_exec_date, last_exec_duration, exec_interval, exec_tod, status_data)
                 VALUES ({$quotedPid}, {$quotedNow}, 1, 60, 0, 'a:0:{}')"
            );
        }

        $tester = new CommandTester($this->createCheckCommand($db));
        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($data);
        $this->assertSame('ok', $data['results']['cron']['status']);
        $this->assertTrue($data['results']['cron']['processes']['main']['found']);
        $this->assertStringNotContainsString('Could not query process table', $tester->getDisplay());
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

    private function createCheckCommand(\PDO $db): CheckCommand
    {
        return new class ($this->config, $db) extends CheckCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:check');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }
}
