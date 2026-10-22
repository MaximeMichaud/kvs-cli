<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use KVS\CLI\Command\System\CheckCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\Process;

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
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            "<?php \$config['memcache_server'] = '127.0.0.1'; \$config['memcache_port'] = 0;"
        );
        $command = new class (new Configuration(['path' => $this->tempDir])) extends CheckCommand {
            protected function isDockerMode(): bool
            {
                return false;
            }

            protected function isExtensionLoaded(string $extension): bool
            {
                return true;
            }
        };
        $command->setName('system:check');
        $tester = new CommandTester($command);
        $tester->execute(['--quiet-ok' => true]);

        $output = $tester->getDisplay();

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

    public function testToolVersionEscapesVersionFlag(): void
    {
        $tool = $this->tempDir . '/fake-version-tool';
        $marker = $this->tempDir . '/check-command-injection';
        file_put_contents(
            $tool,
            <<<'SH'
#!/bin/sh
echo 'fake tool 1.2.3'
SH
        );
        chmod($tool, 0755);

        $method = new \ReflectionMethod(CheckCommand::class, 'getToolVersion');
        $version = $method->invoke(
            $this->command,
            $tool,
            '--version; touch ' . escapeshellarg($marker)
        );

        $this->assertSame('1.2.3', $version);
        $this->assertFileDoesNotExist($marker);
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

    /** @return array<string, array{int, bool, string}> */
    public static function connectivityResponses(): array
    {
        return [
            'endpoint rejects HEAD but accepts GET' => [200, true, 'ok'],
            'HTTP error remains a failure' => [404, false, 'error'],
            'connection closes without a response' => [0, false, 'error'],
        ];
    }

    #[DataProvider('connectivityResponses')]
    public function testCheckInternetConnectivity(int $httpCode, bool $online, string $status): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('The connectivity probe requires the cURL extension.');
        }

        $serverFile = $this->tempDir . '/connectivity-server.php';
        $requestFile = $this->tempDir . '/connectivity-request.txt';
        file_put_contents($serverFile, <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    exit(1);
}
echo stream_socket_get_name($server, false) . "\n";
flush();
$connection = stream_socket_accept($server, 5);
if ($connection === false) {
    exit(1);
}
$request = fgets($connection);
file_put_contents($argv[1], $request);
while (($header = fgets($connection)) !== false && trim($header) !== '') {
}
if ((int) $argv[2] !== 0) {
    $status = str_starts_with($request, 'GET ') ? (int) $argv[2] : 404;
    fwrite($connection, "HTTP/1.1 $status Test\r\nContent-Length: 3\r\nConnection: close\r\n\r\nok\n");
}
fclose($connection);
fclose($server);
PHP);
        $server = new Process([PHP_BINARY, $serverFile, $requestFile, (string) $httpCode]);
        $oldNoProxy = getenv('no_proxy');
        putenv('no_proxy=127.0.0.1');
        try {
            $server->start();
            $this->assertTrue($server->waitUntil(static fn(string $type, string $output): bool =>
                $type === Process::OUT && str_contains($output, "\n")), $server->getErrorOutput());
            $url = 'http://' . trim($server->getOutput()) . '/cdn-cgi/trace';
            $command = new class ($this->config, $url) extends CheckCommand {
                public function __construct(Configuration $config, private string $probeUrl)
                {
                    parent::__construct($config);
                }

                protected function getInternetCheckUrl(): string
                {
                    return $this->probeUrl;
                }
            };
            (new \ReflectionProperty(CheckCommand::class, 'silent'))->setValue($command, true);
            $result = (new \ReflectionMethod(CheckCommand::class, 'checkInternet'))->invoke($command, false, false);
            $server->wait();

            $this->assertTrue($server->isSuccessful(), $server->getErrorOutput());
            $this->assertSame($httpCode, $result['http_code']);
            $this->assertSame($online, $result['online']);
            $this->assertSame($status, $result['status']);
            $this->assertStringStartsWith('GET /cdn-cgi/trace ', file_get_contents($requestFile));
            $this->assertSame($online ? 0 : 1, (new \ReflectionProperty(CheckCommand::class, 'errors'))->getValue($command));
            if (!$online) {
                $this->assertNotEmpty($result['error']);
            }
        } finally {
            $server->stop(0.5);
            putenv($oldNoProxy === false ? 'no_proxy' : 'no_proxy=' . $oldNoProxy);
        }
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

    public function testCheckKvsUpdateShowsStaleLatestVersionCache(): void
    {
        file_put_contents(
            $this->tempDir . '/admin/include/version.php',
            '<?php $config[\'project_version\'] = "7.0.0";'
        );
        mkdir($this->tempDir . '/admin/data/plugins/kvs_news', 0755, true);
        file_put_contents(
            $this->tempDir . '/admin/data/plugins/kvs_news/data.dat',
            serialize(['latest_version' => '6.4.0'])
        );

        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Current Version: 7.0.0', $output);
        $this->assertStringContainsString('Latest Version: 6.4.0 (cache older than installed; run cron to refresh)', $output);
        $this->assertStringNotContainsString('Latest Version: 6.4.0 (up to date)', $output);
    }

    public function testCheckCanSkipOnlineChecks(): void
    {
        $this->tester->execute(['--json' => true, '--skip-online-checks' => true]);

        $data = json_decode($this->tester->getDisplay(), true);
        $this->assertIsArray($data);
        $this->assertSame('skip', $data['results']['internet']['status']);
        $this->assertSame('skip', $data['results']['end_of_life']['status']);
    }

    public function testOpcacheJitCheckUsesTargetPhpRuntimeVersion(): void
    {
        $command = new class ($this->config) extends CheckCommand {
            protected function getKvsPhpVersion(): string
            {
                return '7.4.33';
            }

            protected function getOpcacheConfig(): array|false
            {
                return [
                    'directives' => [
                        'opcache.enable' => true,
                        'opcache.memory_consumption' => 256 * 1024 * 1024,
                        'opcache.interned_strings_buffer' => 16,
                        'opcache.jit_buffer_size' => 64 * 1024 * 1024,
                    ],
                ];
            }
        };
        $tester = new CommandTester($command);

        $tester->execute(['--json' => true, '--skip-online-checks' => true]);

        $data = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('opcache', $data['results']);
        $this->assertFalse($data['results']['opcache']['jit_enabled']);
        $this->assertArrayNotHasKey('jit_buffer_size', $data['results']['opcache']);
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

    /** @return iterable<string, array{string, bool}> */
    public static function directCheckPaths(): iterable
    {
        foreach (['plain-tool', '<info>literal-tool'] as $name) {
            foreach ([false, true] as $decorated) {
                yield $name . ($decorated ? ' decorated' : ' plain') => [$name, $decorated];
            }
        }
    }

    #[DataProvider('directCheckPaths')]
    public function testDirectJsonPreservesDiagnosticToolPath(string $filename, bool $decorated): void
    {
        [$tester, $tool] = $this->createDiagnosticExportFixture($filename);
        $tester->execute(['--json' => true, '--skip-online-checks' => true], ['decorated' => $decorated]);
        $text = $tester->getDisplay();
        $this->assertSame(1, $tester->getStatusCode(), $text);
        $this->assertJson($text);
        $data = json_decode($text, true);
        $this->assertSame($tool, $data['results']['tools']['tools']['ffmpeg']['path']);
        $this->assertSame('skip', $data['results']['internet']['status']);
        $this->assertSame('skip', $data['results']['end_of_life']['status']);
        $this->assertSame('not_configured', $data['results']['memcached']['status']);
    }

    /** @return array{CommandTester, string} */
    private function createDiagnosticExportFixture(string $filename): array
    {
        $tool = $this->tempDir . '/' . $filename;
        file_put_contents($tool, "#!/bin/sh\nprintf '%s\\n' 'ffmpeg version 1.0' 'libx264 aac libavfilter webp avif'\n");
        chmod($tool, 0755);
        $setup = "<?php \$config['project_version'] = '7.0.0'; \$config['memcache_server'] = '';\n";
        foreach (['ffmpeg_path', 'image_magick_path', 'mysqldump_path'] as $key) {
            $setup .= '$config[' . var_export($key, true) . '] = ' . var_export($tool, true) . ";\n";
        }
        file_put_contents($this->tempDir . '/admin/include/setup.php', $setup);
        $configuration = new Configuration(['path' => $this->tempDir, 'disable_db_env_overrides' => true]);
        $detector = $this->createMock(\KVS\CLI\Docker\DockerDetector::class);
        $detector->method('getSummary')->willReturn([
            'docker_available' => false, 'kvs_in_docker' => false, 'prefix' => null, 'containers' => [],
        ]);
        $command = new class ($configuration, $detector) extends CheckCommand {
            public function __construct(Configuration $config, private \KVS\CLI\Docker\DockerDetector $detector)
            {
                parent::__construct($config);
            }

            protected function docker(): \KVS\CLI\Docker\DockerDetector
            {
                return $this->detector;
            }

            protected function isDockerMode(): bool
            {
                return false;
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return null;
            }
        };
        $tester = new CommandTester($command);
        return [$tester, $tool];
    }


    /** @return iterable<string, array{bool, bool}> */
    public static function jsonEncodingDiagnosticCases(): iterable
    {
        foreach ([false, true] as $valid) {
            foreach ([false, true] as $decorated) {
                yield (int) $valid . ' ' . (int) $decorated => [$valid, $decorated];
            }
        }
    }

    #[DataProvider('jsonEncodingDiagnosticCases')]
    public function testJsonEncodingCheckReportsTheEncodingFailure(bool $valid, bool $decorated): void
    {
        $filename = $valid ? 'synthetic-tool-é' : "synthetic-tool-\xff";
        [$tester, $tool] = $this->createDiagnosticExportFixture($filename);
        $contents = file_get_contents($tool);
        $tester->execute(['--json' => true, '--skip-online-checks' => true], ['decorated' => $decorated]);
        $display = $tester->getDisplay();
        $this->assertSame(1, $tester->getStatusCode(), 'The fixture intentionally has no database connection.');
        $this->assertSame($contents, file_get_contents($tool));
        if ($valid) {
            $this->assertJson($display);
            $data = json_decode($display, true);
            $this->assertSame($tool, $data['results']['tools']['tools']['ffmpeg']['path']);
            $this->assertSame('skip', $data['results']['internet']['status']);
        } else {
            $this->assertStringContainsString('Failed to encode diagnostic results as JSON:', $display);
            $this->assertStringContainsString('Malformed UTF-8', $display);
            $this->assertStringNotContainsString($tool, $display);
            $this->assertStringNotContainsString("\xff", $display);
        }
    }
}
