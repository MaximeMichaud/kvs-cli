<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\StatusCommand;
use KVS\CLI\Config\Configuration;
use KVS\CLI\Docker\DockerDetector;
use PDO;
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

    public function testStatusMapsDockerStoragePathBeforeMeasuringContent(): void
    {
        $contentPath = $this->config->getContentPath();
        mkdir($contentPath . '/videos_sources', 0755, true);

        $docker = new class extends DockerDetector {
            public function isKvsInDocker(): bool
            {
                return true;
            }

            public function mapHostPathToContainer(string $hostPath, string $service = 'php'): ?string
            {
                return '/container/contents';
            }

            public function exec(string $service, string $command, int $timeout = 10): ?string
            {
                $command = str_replace("'\\''", "'", $command);
                $marker = str_contains($command, 'KVS_STORAGE_OK') ? "\nKVS_STORAGE_OK\n" : '';
                if (str_contains($command, 'storage_path=')) {
                    return "EXISTS\n" . $marker;
                }
                if (str_contains($command, "test -d '/container/contents'")) {
                    return "EXISTS\n" . $marker;
                }
                if (str_contains($command, "test -d '/container/contents/videos_sources'")) {
                    return "EXISTS\n" . $marker;
                }
                if (str_contains($command, "du -sb '/container/contents/videos_sources'")) {
                    return "123\n" . $marker;
                }
                if (str_contains($command, "find '/container/contents/videos_sources'")) {
                    return "2\n" . $marker;
                }

                return null;
            }

            public function getPhpVersion(): ?string
            {
                return '8.1.0';
            }

            public function getPhpIni(string $setting): ?string
            {
                return match ($setting) {
                    'memory_limit' => '256M',
                    'max_execution_time' => '300',
                    'upload_max_filesize', 'post_max_size' => '128M',
                    default => '0',
                };
            }

            public function isPhpExtensionLoaded(string $extension): ?bool
            {
                return true;
            }

            public function getPhpInfo(array $settings = [], array $extensions = []): array
            {
                return [
                    'settings' => array_fill_keys($settings, '0'),
                    'extensions' => array_fill_keys($extensions, true),
                    'version' => '8.1.0',
                ];
            }

            public function checkCache(): array
            {
                return ['available' => false, 'type' => null, 'memory_mb' => null];
            }
        };

        $command = new class ($this->config, $docker) extends StatusCommand {
            public function __construct(Configuration $config, private DockerDetector $fakeDocker)
            {
                parent::__construct($config);
            }

            protected function docker(): DockerDetector
            {
                return $this->fakeDocker;
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return null;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([]);
        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode(), $output);
        $this->assertStringContainsString('(Docker: /container/contents)', $output);
        $this->assertStringContainsString('123.00 B', $output);
        $this->assertStringContainsString('2 files', $output);
        $this->assertStringNotContainsString('Directory not accessible from PHP container', $output);
    }

    /** @return iterable<string, array{string}> */
    public static function storageEnumerationModes(): iterable
    {
        yield 'local' => ['local'];
        yield 'mapped Docker path' => ['docker-local-enumeration'];
        yield 'remote Docker enumeration' => ['docker-remote-enumeration'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('storageEnumerationModes')]
    public function testStorageRetainsDirectoriesWhoseDisplayLabelsCollide(string $mode): void
    {
        $contentPath = $this->config->getContentPath();
        $fixtures = [
            'albums' => [17],
            'albums_sources' => [31, 37],
            'screenshots' => [47],
            'videos_screenshots' => [59, 61],
        ];
        foreach ($fixtures as $directory => $sizes) {
            mkdir($contentPath . '/' . $directory, 0755, true);
            foreach ($sizes as $index => $size) {
                file_put_contents($contentPath . '/' . $directory . '/' . $index . '.bin', str_repeat('x', $size));
            }
        }

        $config = $mode === 'docker-remote-enumeration'
            ? new class (['path' => $this->tempDir]) extends Configuration {
                public function getContentPath(): string
                {
                    return $this->getKvsPath() . '/not-mounted';
                }
            }
            : $this->config;
        $docker = new class ($fixtures) extends DockerDetector {
            /** @param array<string, list<int>> $fixtures */
            public function __construct(private array $fixtures)
            {
            }

            public function mapHostPathToContainer(string $hostPath, string $service = 'php'): ?string
            {
                return '/container/contents';
            }

            public function exec(string $service, string $command, int $timeout = 10): ?string
            {
                $command = str_replace("'\\''", "'", $command);
                $marker = str_contains($command, 'KVS_STORAGE_OK') ? "\nKVS_STORAGE_OK\n" : '';
                if (str_contains($command, 'storage_path=')) {
                    return "EXISTS\n" . $marker;
                }
                if (str_contains($command, 'test -d ')) {
                    return "EXISTS\n" . $marker;
                }
                if (str_contains($command, '-mindepth 1 -maxdepth 1')) {
                    return implode("\n", array_keys($this->fixtures)) . "\n" . $marker;
                }
                foreach ($this->fixtures as $directory => $sizes) {
                    if (str_contains($command, "'/container/contents/$directory'")) {
                        $measurement = str_contains($command, 'du -sb ') ? array_sum($sizes) : count($sizes);
                        return (string) $measurement . $marker;
                    }
                }
                return null;
            }
        };
        $command = new class ($config, $docker, $mode !== 'local') extends StatusCommand {
            public function __construct(
                Configuration $config,
                private DockerDetector $testDocker,
                private bool $dockerMode
            ) {
                parent::__construct($config);
            }

            protected function isDockerMode(): bool
            {
                return $this->dockerMode;
            }

            protected function docker(): DockerDetector
            {
                return $this->testDocker;
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                (new \ReflectionMethod(StatusCommand::class, 'showStorageBreakdown'))->invoke($this);
                return self::SUCCESS;
            }
        };
        $tester = new CommandTester($command);
        $tester->execute([]);
        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $rows = [];
        $total = null;
        foreach (explode("\n", $display) as $line) {
            if (!str_starts_with($line, '│')) {
                continue;
            }
            $cells = array_map('trim', array_slice(explode('│', $line), 1, -1));
            if ($cells[0] === 'Total Content') {
                $total = $cells[1];
            } elseif ($cells[0] !== 'Type' && $cells[0] !== '---') {
                $rows[] = [$cells[1], $cells[2]];
            }
        }
        sort($rows);
        $expected = [['17.00 B', '1 files'], ['68.00 B', '2 files'], ['47.00 B', '1 files'], ['120.00 B', '2 files']];
        sort($expected);
        $this->assertSame($expected, $rows, $mode . ': every directory must retain its own size and file count');
        $this->assertSame('252.00 B', $total, $mode . ': total must include all four directories');
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function unreadableStorageDirectories(): iterable
    {
        foreach ([false, true] as $dockerMode) {
            foreach ([false, true] as $rootDenied) {
                $label = ($dockerMode ? 'Docker' : 'local') . ($rootDenied ? ' root' : ' child');
                yield $label => [$dockerMode, $rootDenied];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableStorageDirectories')]
    public function testStorageReportsUnreadableDirectoriesAsUnavailable(bool $dockerMode, bool $rootDenied): void
    {
        $content = $this->config->getContentPath();
        mkdir($content . '/videos', 0755, true);
        mkdir($content . '/videos_sources', 0755, true);
        mkdir($content . '/empty', 0755, true);
        file_put_contents($content . '/videos/healthy.bin', str_repeat('h', 31));
        file_put_contents($content . '/videos_sources/hidden.bin', str_repeat('s', 17));
        $blocked = $rootDenied ? $content : $content . '/videos_sources';
        chmod($blocked, 0000);
        clearstatcache();
        if (is_readable($blocked)) {
            chmod($blocked, 0755);
            $this->markTestSkipped('Directory permission refusal requires an unprivileged process');
        }
        $tester = $this->createStorageOnlyTester($dockerMode);
        try {
            $tester->execute([]);
            $rows = $this->storageRows($tester->getDisplay());
            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            if ($rootDenied) {
                $this->assertSame(['Unavailable', ''], $rows['Total Content'] ?? null, $tester->getDisplay());
                $this->assertStringNotContainsString('Not found', $tester->getDisplay());
            } else {
                $this->assertSame(['Unavailable', 'Unavailable'], $rows['Videos Sources'] ?? null);
                $this->assertSame(['31.00 B', ''], $rows['Total Content (partial)'] ?? null);
                $this->assertSame(['0 B', '0 files'], $rows['Empty'] ?? null);
            }
            $this->assertStringContainsString('Storage data unavailable', $tester->getDisplay());
        } finally {
            chmod($blocked, 0755);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function failingStorageTools(): iterable
    {
        foreach (['du', 'find', 'awk'] as $tool) {
            yield $tool => [$tool];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failingStorageTools')]
    public function testDockerStorageRejectsToolOutputWhenItsProducerFails(string $tool): void
    {
        $content = $this->config->getContentPath();
        mkdir($content . '/videos', 0755, true);
        file_put_contents($content . '/videos/file.bin', str_repeat('x', 17));
        $tools = $this->tempDir . '/storage-tools';
        mkdir($tools);
        file_put_contents($tools . '/' . $tool, "#!/bin/sh\nprintf '999\\tignored\\n'\nexit 7\n");
        chmod($tools . '/' . $tool, 0755);
        $previousPath = getenv('PATH');
        putenv('PATH=' . $tools . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        try {
            $tester = $this->createStorageOnlyTester(true);
            $tester->execute([]);
            $rows = $this->storageRows($tester->getDisplay());
            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame(['Unavailable', 'Unavailable'], $rows['Videos'] ?? null, $tester->getDisplay());
            $this->assertArrayHasKey('Total Content (partial)', $rows);
            $this->assertStringContainsString('Storage data unavailable', $tester->getDisplay());
        } finally {
            $previousPath === false ? putenv('PATH') : putenv('PATH=' . $previousPath);
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function absentOrEmptyStorage(): iterable
    {
        foreach ([false, true] as $dockerMode) {
            foreach ([false, true] as $absent) {
                yield ($dockerMode ? 'Docker' : 'local') . ($absent ? ' absent' : ' empty') => [$dockerMode, $absent];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('absentOrEmptyStorage')]
    public function testStorageDistinguishesHealthyEmptyAndAbsentDirectories(bool $dockerMode, bool $absent): void
    {
        $content = $this->config->getContentPath();
        if ($absent && is_dir($content)) {
            rmdir($content);
        } elseif (!$absent && !is_dir($content)) {
            mkdir($content, 0755, true);
        }
        $tester = $this->createStorageOnlyTester($dockerMode);
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        if (!$absent) {
            $this->assertSame(['0 B', ''], $this->storageRows($tester->getDisplay())['Total Content'] ?? null);
            $this->assertStringNotContainsString('Storage data unavailable', $tester->getDisplay());
        } else {
            $this->assertArrayNotHasKey('Total Content', $this->storageRows($tester->getDisplay()));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function nativeStorageModes(): iterable
    {
        yield 'local' => [false];
        yield 'Docker' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeStorageModes')]
    public function testStorageCountsFileNamesWithNewlinesOnce(bool $dockerMode): void
    {
        $content = $this->config->getContentPath();
        mkdir($content . '/videos', 0755, true);
        file_put_contents($content . "/videos/one\nfile.bin", str_repeat('x', 17));
        $tester = $this->createStorageOnlyTester($dockerMode);
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['17.00 B', '1 files'], $this->storageRows($tester->getDisplay())['Videos'] ?? null);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function externalStoragePaths(): iterable
    {
        foreach ([false, true] as $dockerMode) {
            foreach ([false, true] as $symlink) {
                $label = ($dockerMode ? 'Docker' : 'local') . ($symlink ? ' symlink' : ' configured');
                yield $label => [$dockerMode, $symlink];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('externalStoragePaths')]
    public function testStorageDoesNotMistakeAnUnsearchableExternalAncestorForAbsence(
        bool $dockerMode,
        bool $symlink
    ): void {
        $blocked = $this->tempDir . '/external/blocked';
        $content = $blocked . '/contents';
        mkdir($content . '/videos', 0755, true);
        file_put_contents($content . '/videos/owned.bin', str_repeat('x', 17));
        $path = $content;
        if ($symlink) {
            $path = $this->tempDir . '/content-link';
            symlink($content, $path);
        }
        $this->useStoragePath($path);
        chmod($blocked, 0000);
        clearstatcache();
        if (is_executable($blocked)) {
            chmod($blocked, 0755);
            $this->markTestSkipped('Ancestor traversal refusal requires an unprivileged process');
        }
        try {
            $this->assertFalse(is_dir($path), 'The actual content directory must be hidden by the refused ancestor');
            $tester = $this->createStorageOnlyTester($dockerMode);
            $tester->execute([]);
            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame(['Unavailable', ''], $this->storageRows($tester->getDisplay())['Total Content'] ?? null);
            $this->assertStringContainsString('Storage data unavailable', $tester->getDisplay());
        } finally {
            chmod($blocked, 0755);
            clearstatcache();
        }
        $tester = $this->createStorageOnlyTester($dockerMode);
        $tester->execute([]);
        $this->assertSame(['17.00 B', '1 files'], $this->storageRows($tester->getDisplay())['Videos'] ?? null);
        $this->assertStringNotContainsString('Storage data unavailable', $tester->getDisplay());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeStorageModes')]
    public function testStorageAcceptsVerifiedAbsentExternalPath(bool $dockerMode): void
    {
        $this->useStoragePath($this->tempDir . '/absent-external/nested/contents');
        $tester = $this->createStorageOnlyTester($dockerMode);
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertArrayNotHasKey('Total Content', $this->storageRows($tester->getDisplay()));
        $this->assertStringNotContainsString('Storage data unavailable', $tester->getDisplay());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeStorageModes')]
    public function testStorageAcceptsVerifiedAbsentExternalSymlinkTarget(bool $dockerMode): void
    {
        $path = $this->tempDir . '/missing-content-link';
        symlink('missing-external/nested/contents', $path);
        $this->useStoragePath($path);
        $tester = $this->createStorageOnlyTester($dockerMode);
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertArrayNotHasKey('Total Content', $this->storageRows($tester->getDisplay()));
        $this->assertStringNotContainsString('Storage data unavailable', $tester->getDisplay());
        $this->assertTrue(is_link($path));
    }

    private function useStoragePath(string $path): void
    {
        $this->config = new class (['path' => $this->tempDir], $path) extends Configuration {
            public function __construct(array $options, private string $storagePath)
            {
                parent::__construct($options);
            }

            public function getContentPath(): string
            {
                return $this->storagePath;
            }
        };
    }

    private function createStorageOnlyTester(bool $dockerMode): CommandTester
    {
        $docker = new class extends DockerDetector {
            public function mapHostPathToContainer(string $hostPath, string $service = 'php'): ?string
            {
                return $hostPath;
            }

            public function exec(string $service, string $command, int $timeout = 10): ?string
            {
                $output = @shell_exec($command);
                return is_string($output) ? $output : null;
            }
        };
        $command = new class ($this->config, $docker, $dockerMode) extends StatusCommand {
            public function __construct(
                Configuration $config,
                private DockerDetector $testDocker,
                private bool $dockerMode
            ) {
                parent::__construct($config);
            }

            protected function isDockerMode(): bool
            {
                return $this->dockerMode;
            }

            protected function docker(): DockerDetector
            {
                return $this->testDocker;
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                (new \ReflectionMethod(StatusCommand::class, 'showStorageBreakdown'))->invoke($this);
                return self::SUCCESS;
            }
        };
        return new CommandTester($command);
    }

    /** @return array<string, array{string, string}> */
    private function storageRows(string $display): array
    {
        $rows = [];
        foreach (explode("\n", $display) as $line) {
            if (str_starts_with($line, '│')) {
                $cells = array_map('trim', array_slice(explode('│', $line), 1, -1));
                $rows[$cells[0]] = [$cells[1], $cells[2]];
            }
        }
        return $rows;
    }

    public function testStatusReadsKvsVersionFromVersionFile(): void
    {
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_name" => "Test KVS"];'
        );
        file_put_contents(
            $this->tempDir . '/admin/include/version.php',
            '<?php $config["project_version"] = "7.0.0";'
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new StatusCommand($this->config);
        $this->tester = new CommandTester($this->command);

        $this->tester->execute([]);
        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('KVS Version', $output);
        $this->assertStringContainsString('7.0.0', $output);
    }

    public function testStatusSecurityReadsMaintenanceFlagFromWebsiteParams(): void
    {
        mkdir($this->tempDir . '/admin/data/system', 0755, true);
        file_put_contents(
            $this->tempDir . '/admin/data/system/website_ui_params.dat',
            serialize(['DISABLE_WEBSITE' => 1])
        );

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Maintenance mode ENABLED', $this->normalizeStatusOutput($display));
    }

    public function testStatusSecurityReadsDebugContextsFile(): void
    {
        mkdir($this->tempDir . '/admin/data/system', 0755, true);
        file_put_contents($this->tempDir . '/admin/data/system/debug.dat', 'cron');

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Debug mode ENABLED', $this->normalizeStatusOutput($display));
    }

    public function testStatusSecurityReadsNativeKvsBackupFiles(): void
    {
        $backupDir = $this->tempDir . '/admin/data/backup';
        mkdir($backupDir, 0755, true);
        $backupFile = $backupDir . '/example-site-backup-auto-dwpsc-2026-06-29-123456.tar.gz';
        file_put_contents($backupFile, 'test');
        touch($backupFile, time() - 3600);

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        $normalizedDisplay = $this->normalizeStatusOutput($display);
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Database backups Last backup', $normalizedDisplay);
        $this->assertStringNotContainsString('Database backups No recent backups found', $normalizedDisplay);
    }

    public function testStatusSecurityReadsSetupDebugFlags(): void
    {
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            "<?php\n\$config['project_version']='6.3.2';\n\$config['enable_debug']='true';\n"
        );

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Debug mode ENABLED', $this->normalizeStatusOutput($display));
    }

    public function testStatusSecurityUsesFpmDisplayErrorsSetting(): void
    {
        $previousDisplayErrors = ini_get('display_errors');
        ini_set('display_errors', '1');

        $process = null;
        $pipes = [];

        try {
            $port = $this->reserveLocalPort();
            file_put_contents(
                $this->tempDir . '/admin/include/setup.php',
                '<?php $config = ' . var_export([
                    'project_version' => '6.3.2',
                    'project_name' => 'Test KVS',
                    'project_path' => $this->tempDir,
                    'project_url' => 'http://127.0.0.1:' . $port,
                ], true) . ';'
            );

            $process = proc_open(
                [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $this->tempDir],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes
            );
            $this->assertIsResource($process);

            $this->waitForHttpServer('http://127.0.0.1:' . $port . '/admin/include/setup.php');

            $db = new PDO('sqlite::memory:');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec('CREATE TABLE ktvs_background_tasks (task_id INTEGER, status_id INTEGER, added_date TEXT)');
            $db->exec('CREATE TABLE ktvs_background_tasks_history (task_id INTEGER, status_id INTEGER, effective_duration INTEGER)');

            $command = new class (TestHelper::createTestConfiguration($this->tempDir), $db) extends StatusCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            };
            $tester = new CommandTester($command);

            $tester->execute([]);

            $display = $tester->getDisplay();
            $normalizedDisplay = $this->normalizeStatusOutput($display);
            $this->assertSame(0, $tester->getStatusCode(), $display);
            $this->assertStringContainsString('Web Server PHP', $normalizedDisplay);
            $this->assertStringContainsString('Development Server', $normalizedDisplay);
            $this->assertStringContainsString('PHP Version ' . PHP_VERSION . ' FPM', $normalizedDisplay);
            $this->assertStringContainsString('PHP display_errors DISABLED', $normalizedDisplay);
            $this->assertStringNotContainsString('PHP display_errors ENABLED', $normalizedDisplay);
        } finally {
            if ($previousDisplayErrors !== false) {
                ini_set('display_errors', $previousDisplayErrors);
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($process)) {
                $status = proc_get_status($process);
                if (($status['running'] ?? false) === true) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
        }
    }

    public function testCheckCommandEscapesCommandName(): void
    {
        $marker = $this->tempDir . '/status-command-injection';
        $maliciousCommand = 'definitely-missing-command; touch ' . escapeshellarg($marker);

        $method = new \ReflectionMethod(StatusCommand::class, 'checkCommand');
        $result = $method->invoke($this->command, $maliciousCommand, '--version');

        $this->assertIsArray($result);
        $this->assertFalse($result['available']);
        $this->assertFileDoesNotExist($marker);
    }

    public function testStatusUsesConfiguredImageMagickPath(): void
    {
        $binDir = $this->tempDir . '/bin';
        mkdir($binDir, 0755, true);

        $magickPath = $binDir . '/magick';
        file_put_contents(
            $magickPath,
            "#!/bin/sh\nprintf '%s\\n' 'Version: ImageMagick 9.8.7 test-build'\n"
        );
        chmod($magickPath, 0755);

        TestHelper::createMockSetupConfig($this->tempDir, [
            'image_magick_path' => $magickPath,
        ]);

        $command = new StatusCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('ImageMagick', $display);
        $this->assertStringContainsString($magickPath, $display);
        $this->assertStringContainsString('9.8.7', $display);
        $this->assertStringNotContainsString('/usr/bin/convert', $display);
    }

    public function testStatusChecksDiskSpace(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should show disk information
        $this->assertStringContainsString('Disk', $output);
        $this->assertMatchesRegularExpression('/\d+(\.\d+)?\s*(TB|GB|MB)/', $output); // Should show size

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testStatusAverageTimeUsesBackgroundTaskHistory(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_background_tasks (task_id INTEGER, status_id INTEGER, effective_duration INTEGER, added_date TEXT)');
        $db->exec('CREATE TABLE ktvs_background_tasks_history (task_id INTEGER, status_id INTEGER, effective_duration INTEGER)');
        $db->exec("INSERT INTO ktvs_background_tasks (task_id, status_id, effective_duration, added_date) VALUES (1, 3, 999, '2026-05-26 12:00:00')");
        $db->exec('DELETE FROM ktvs_background_tasks WHERE status_id = 3');
        $db->exec('INSERT INTO ktvs_background_tasks_history (task_id, status_id, effective_duration) VALUES (10, 3, 30)');
        $db->exec('INSERT INTO ktvs_background_tasks_history (task_id, status_id, effective_duration) VALUES (11, 3, 90)');

        $command = new class (TestHelper::createTestConfiguration($this->tempDir), $db) extends StatusCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('Average Time', $display);
        $this->assertStringContainsString('1m 0s', $display);
    }

    public function testStatusShowsCurrentFailedBackgroundTasks(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_background_tasks (task_id INTEGER, status_id INTEGER, added_date TEXT)');
        $db->exec('CREATE TABLE ktvs_background_tasks_history (task_id INTEGER, status_id INTEGER, effective_duration INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_background_tasks (task_id, status_id, added_date) VALUES " .
            "(1, 2, '2026-01-01 00:00:00'), (2, 2, '2026-01-02 00:00:00')"
        );

        $command = new class (TestHelper::createTestConfiguration($this->tempDir), $db) extends StatusCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertMatchesRegularExpression('/Failed\W+2/', $display);
        $this->assertStringNotContainsString('Failed (24h)', $display);
    }

    public function testStatusReusesSingleDatabaseConnection(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_background_tasks (task_id INTEGER, status_id INTEGER, added_date TEXT)');
        $db->exec('CREATE TABLE ktvs_background_tasks_history (task_id INTEGER, status_id INTEGER, effective_duration INTEGER)');

        $command = new class (TestHelper::createTestConfiguration($this->tempDir), $db) extends StatusCommand {
            public int $connectionCalls = 0;

            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                ++$this->connectionCalls;

                return $this->testDb;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertSame(1, $command->connectionCalls);
    }

    public function testStatusLabelsFilteredUserCountAsEnabledUsers(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_videos (status_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums (status_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users (status_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories (category_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags (tag_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_models (model_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_dvds (dvd_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_background_tasks (task_id INTEGER, status_id INTEGER, added_date TEXT)');
        $db->exec('CREATE TABLE ktvs_background_tasks_history (task_id INTEGER, status_id INTEGER, effective_duration INTEGER)');
        $db->exec('INSERT INTO ktvs_users VALUES (0), (1), (2), (3), (4)');

        $command = new class (TestHelper::createTestConfiguration($this->tempDir), $db) extends StatusCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([]);

        $normalizedDisplay = preg_replace('/[^\w.]+/u', ' ', $tester->getDisplay());
        $this->assertIsString($normalizedDisplay);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Enabled Users 3', $normalizedDisplay);
        $this->assertStringNotContainsString('Users 5', $normalizedDisplay);
    }

    public function testStatusShowsDatabaseTableStats(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_background_tasks (task_id INTEGER, status_id INTEGER, added_date TEXT)');
        $db->exec('CREATE TABLE ktvs_background_tasks_history (task_id INTEGER, status_id INTEGER, effective_duration INTEGER)');

        $command = new class (TestHelper::createTestConfiguration($this->tempDir), $db) extends StatusCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $normalizedDisplay = preg_replace('/[^\w.]+/u', ' ', $display);
        $this->assertIsString($normalizedDisplay);
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringNotContainsString('Could not fetch database statistics', $display);
        $this->assertStringContainsString('Total Tables 2', $normalizedDisplay);
        $this->assertStringContainsString('Database Size', $display);
    }

    public function testStatusDetectsRedisProtocolCacheWithoutDockerCli(): void
    {
        $portFile = $this->tempDir . '/redis-port.txt';
        $serverScript = $this->tempDir . '/redis-server.php';
        file_put_contents(
            $serverScript,
            <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    exit(1);
}
$address = stream_socket_get_name($server, false);
if ($address === false) {
    exit(1);
}
file_put_contents($argv[1], $address);
$connection = @stream_socket_accept($server, 10);
if (is_resource($connection)) {
    $line = fgets($connection);
    fwrite($connection, is_string($line) && stripos($line, 'PING') !== false ? "+PONG\r\n" : "-ERR\r\n");
    fclose($connection);
}
fclose($server);
PHP
        );

        $process = proc_open(
            [PHP_BINARY, $serverScript, $portFile],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        $this->assertIsResource($process);

        try {
            $address = $this->waitForPortFile($portFile);
            $parts = explode(':', $address);
            $port = (int) end($parts);
            $this->assertGreaterThan(0, $port);

            file_put_contents(
                $this->tempDir . '/admin/include/setup.php',
                "<?php\n"
                . '$config = ["project_version" => "6.3.2", "project_name" => "Test KVS", '
                . "\"memcache_server\" => \"127.0.0.1\", \"memcache_port\" => $port];\n"
            );

            $db = new PDO('sqlite::memory:');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec('CREATE TABLE ktvs_background_tasks (task_id INTEGER, status_id INTEGER, added_date TEXT)');
            $db->exec('CREATE TABLE ktvs_background_tasks_history (task_id INTEGER, status_id INTEGER, effective_duration INTEGER)');

            $command = new class (new Configuration(['path' => $this->tempDir]), $db) extends StatusCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            };
            $tester = new CommandTester($command);

            $tester->execute([]);

            $display = $tester->getDisplay();
            $this->assertSame(0, $tester->getStatusCode(), $display);
            $this->assertStringContainsString('Dragonfly', $display);
            $this->assertStringContainsString("127.0.0.1:$port", $display);
            $this->assertStringContainsString('Connected', $display);
        } finally {
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === true) {
                proc_terminate($process);
            }
            proc_close($process);
        }
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

    private function waitForPortFile(string $portFile): string
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (is_file($portFile)) {
                $address = trim((string) file_get_contents($portFile));
                if ($address !== '') {
                    return $address;
                }
            }
            usleep(10000);
        }

        $this->fail('Redis test server did not start');
    }

    private function reserveLocalPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, $errstr);

        $address = stream_socket_get_name($server, false);
        fclose($server);
        $this->assertIsString($address);

        $parts = explode(':', $address);
        $port = (int) end($parts);
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    private function waitForHttpServer(string $url): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (@file_get_contents($url) !== false) {
                return;
            }
            usleep(10000);
        }

        $this->fail('PHP test server did not start');
    }

    private function normalizeStatusOutput(string $output): string
    {
        $normalized = preg_replace('/[^\w.]+/u', ' ', $output);
        $this->assertIsString($normalized);

        return $normalized;
    }
}
