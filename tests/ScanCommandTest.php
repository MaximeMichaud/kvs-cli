<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Constants;
use KVS\CLI\Docker\DockerDetector;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private ?\PDO $fixtureDatabase = null;
    private string $fixturePrefix = '';

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-scan-test-');
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
        if ($this->fixtureDatabase !== null) {
            $this->fixtureDatabase->exec('DROP VIEW IF EXISTS `' . $this->fixturePrefix . 'comments`');
            foreach (['videos', 'albums', 'users', 'comment_rows'] as $table) {
                $this->fixtureDatabase->exec('DROP TABLE IF EXISTS `' . $this->fixturePrefix . $table . '`');
            }
        }
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

    public function testScanCommandUsesMediaAwarePackageEstimate(): void
    {
        TestHelper::createMockDbConfig($this->tempDir, [
            'host' => '127.0.0.1',
            'port' => 1,
            'user' => 'missing',
            'password' => 'missing',
            'database' => 'missing',
        ]);

        $videosDir = $this->tempDir . '/content/' . Constants::CONTENT_VIDEOS_SOURCES;
        mkdir($videosDir, 0755, true);
        file_put_contents($videosDir . '/source.mp4', str_repeat('a', 1024 * 1024));

        $command = new ScanCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);
        $tester->execute(['--json' => true, '--force' => true]);

        $data = json_decode($tester->getDisplay(), true);

        $this->assertIsArray($data);
        $this->assertIsArray($data['totals']);
        $totalSize = (int) $data['totals']['total_size_bytes'];
        $estimatedPackageSize = (int) $data['totals']['estimated_package_size_bytes'];

        $this->assertGreaterThan(0, $totalSize);
        $this->assertGreaterThan((int) floor($totalSize * 0.9), $estimatedPackageSize);
        $this->assertGreaterThan((int) floor($totalSize * 0.7), $estimatedPackageSize);
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

    public function testScanRetainsDirectoriesWithTheSameDisplayLabel(): void
    {
        foreach (['albums' => 47, 'albums_sources' => 43] as $directory => $bytes) {
            mkdir($this->tempDir . '/content/' . $directory);
            file_put_contents($this->tempDir . '/content/' . $directory . '/image.bin', str_repeat('x', $bytes));
        }

        $this->tester->execute(['--json' => true, '--force' => true]);
        $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $entries = $data['storage']['breakdown'];

        $this->assertCount(3, $entries, 'Both album directories and the existing video directory must remain visible');
        $byPath = array_column(array_values($entries), null, 'path');
        $this->assertSame(1, $byPath['albums']['files']);
        $this->assertSame(1, $byPath['albums_sources']['files']);
        $this->assertSame(2, $data['storage']['total_files']);
        $this->assertSame(array_sum(array_column($entries, 'size_bytes')), $data['storage']['total_bytes']);
        $estimate = $data['database']['size_bytes'] * 0.30
            + $byPath['albums']['size_bytes'] * 0.70
            + $byPath['albums_sources']['size_bytes'] * 0.95
            + $byPath['videos']['size_bytes'] * 0.98;
        $this->assertSame((int) ceil($estimate), $data['totals']['estimated_package_size_bytes']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function unreadableStorageCases(): iterable
    {
        foreach ([false, true] as $docker) {
            foreach (['', '/videos', '/videos/nested'] as $suffix) {
                yield ($docker ? 'docker ' : 'local ') . ($suffix === '' ? 'root' : $suffix) => [$suffix, $docker];
            }
        }
    }

    #[DataProvider('unreadableStorageCases')]
    public function testScanReportsUnreadableStorage(string $suffix, bool $docker): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
            $this->markTestSkipped('Requires Linux filesystem permissions under an unprivileged user');
        }
        mkdir($this->tempDir . '/content/videos/nested', 0755, true);
        file_put_contents($this->tempDir . '/content/videos/nested/source.bin', 'owned storage witness');
        $configuration = new \ReflectionProperty(ScanCommand::class, 'targetConfig');
        $configuration->setValue($this->command, $this->config);
        if ($docker) {
            $this->useLocalDockerDetector();
        }
        $path = $this->tempDir . '/content' . $suffix;
        chmod($path, 0000);
        try {
            $result = @(new \ReflectionMethod(ScanCommand::class, 'scanStorage'))->invoke($this->command);
            $this->assertArrayHasKey('error', $result, 'An inaccessible directory is not a verified empty directory');
            $this->assertStringContainsString('Unable to inspect', $result['error']);
        } finally {
            chmod($path, 0755);
        }
    }

    public function testScanRejectsUnreadableStorageInReadiness(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
            $this->markTestSkipped('Requires Linux filesystem permissions under an unprivileged user');
        }
        $this->createContentDatabase();
        chmod($this->tempDir . '/content', 0000);
        try {
            $this->tester->execute(['--json' => true, '--force' => true]);
            $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $this->tester->getStatusCode());
            $this->assertFalse($data['ready_for_migration']);
            $this->assertFalse($data['assessment']['can_migrate']);
            $this->assertStringContainsString('Unable to inspect', implode(' ', $data['issues']));
        } finally {
            chmod($this->tempDir . '/content', 0755);
        }
    }

    public function testScanAcceptsReadableEmptyAndAbsentContentStorage(): void
    {
        $this->createContentDatabase();
        $this->tester->execute(['--json' => true, '--force' => true]);
        $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertTrue($data['storage']['content_exists']);
        $this->assertSame(0, $data['storage']['total_files']);
        $this->assertTrue($data['ready_for_migration']);
        rename($this->tempDir . '/content', $this->tempDir . '/content-held');
        $this->tester->execute(['--json' => true, '--force' => true]);
        $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertFalse($data['storage']['content_exists']);
        $this->assertSame([], $data['issues']);
        $this->assertTrue($data['ready_for_migration']);
        $this->assertTrue($data['assessment']['can_migrate']);
    }

    public function testScanRejectsUnreadableRequiredContent(): void
    {
        $db = $this->createContentDatabase();
        $db->exec('DROP TABLE `' . $this->fixturePrefix . 'videos`');

        $this->tester->execute(['--json' => true, '--force' => true]);
        $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            1,
            $this->tester->getStatusCode(),
            'A connected database with unreadable required data is not ready'
        );
        $this->assertFalse($data['ready_for_migration']);
        $this->assertFalse($data['assessment']['can_migrate']);
        $this->assertStringContainsString('42S02', implode(' ', $data['issues']));
    }

    public function testScanRejectsErrorsInExistingOptionalTables(): void
    {
        $db = $this->createContentDatabase();
        $db->exec('CREATE TABLE `' . $this->fixturePrefix . 'comment_rows` (id INT)');
        $db->exec(
            'CREATE VIEW `' . $this->fixturePrefix . 'comments` AS SELECT * FROM `'
            . $this->fixturePrefix . 'comment_rows`'
        );
        $db->exec('DROP TABLE `' . $this->fixturePrefix . 'comment_rows`');

        $this->tester->execute(['--json' => true, '--force' => true]);
        $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            1,
            $this->tester->getStatusCode(),
            'An invalid existing optional table must not become a zero count'
        );
        $this->assertFalse($data['ready_for_migration']);
        $this->assertFalse($data['assessment']['can_migrate']);
        $this->assertStringContainsString('1356', implode(' ', $data['issues']));
    }

    public function testScanAllowsAbsentLegacyOptionalTables(): void
    {
        $this->createContentDatabase();
        $this->tester->execute(['--json' => true, '--force' => true]);
        $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertTrue($data['ready_for_migration']);
        $this->assertTrue($data['assessment']['can_migrate']);
        $this->assertSame([], $data['issues']);
        $this->assertSame(['total' => 1, 'active' => 1, 'disabled' => 0, 'error' => 0], $data['content']['videos']);
        $this->assertSame(0, $data['content']['comments']);
    }

    private function createContentDatabase(): \PDO
    {
        $config = TestHelper::getDbConfig();
        try {
            $db = new \PDO(
                'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'],
                $config['user'],
                $config['pass'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $error) {
            if (getenv('KVS_CLI_TEST_SCAN_MARIADB') === '1') {
                $this->fail('The configured disposable MySQL test database is required for this run');
            }
            $this->markTestSkipped('Requires the configured disposable MySQL test database');
        }
        $this->fixtureDatabase = $db;
        $this->fixturePrefix = 'scan_probe_' . bin2hex(random_bytes(6)) . '_';
        foreach (['videos', 'albums', 'users'] as $table) {
            $db->exec('CREATE TABLE `' . $this->fixturePrefix . $table . '` (status_id INT NOT NULL)');
            $db->exec('INSERT INTO `' . $this->fixturePrefix . $table . '` VALUES (1)');
        }
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ' . var_export(['tables_prefix' => $this->fixturePrefix], true) . ';'
        );
        $this->command = new ScanCommand(new Configuration(['path' => $this->tempDir]));
        $this->tester = new CommandTester($this->command);
        return $db;
    }

    /** @return iterable<string, array{bool}> */
    public static function measurementTransports(): iterable
    {
        yield 'local' => [false];
        yield 'docker dispatch' => [true];
    }

    #[DataProvider('measurementTransports')]
    public function testScanCountsNewlineNamedFilesOnce(bool $docker): void
    {
        $path = $this->tempDir . '/content/videos';
        file_put_contents($path . "/line\nbreak.bin", 'owned newline');
        file_put_contents($path . '/normal.bin', 'owned');
        (new \ReflectionProperty(ScanCommand::class, 'targetConfig'))->setValue($this->command, $this->config);
        if ($docker) {
            $this->useLocalDockerDetector();
        }
        $actualFiles = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $actualFiles++;
            }
        }
        $this->assertSame(2, $actualFiles);
        $storage = (new \ReflectionMethod(ScanCommand::class, 'scanStorage'))->invoke($this->command);
        $this->assertSame($actualFiles, $storage['total_files'], 'File names must not add count records');
        $this->assertSame(2, $storage['breakdown']['videos']['files']);
        $this->assertArrayNotHasKey('error', $storage);
    }

    public function testScanRejectsContentBehindAnInaccessibleAncestor(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
            $this->markTestSkipped('Requires Linux filesystem permissions under an unprivileged user');
        }
        $this->createContentDatabase();
        $ancestor = $this->tempDir . '/outside-parent';
        mkdir($ancestor);
        rename($this->tempDir . '/content', $ancestor . '/contents');
        symlink($ancestor . '/contents', $this->tempDir . '/contents');
        chmod($ancestor, 0000);
        try {
            $this->assertTrue(is_readable($this->tempDir . '/admin/include/setup.php'));
            $this->assertTrue(is_link($this->tempDir . '/contents'));
            $this->assertFalse(is_dir($this->tempDir . '/contents'));
            $this->tester->execute(['--json' => true, '--force' => true]);
            $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $this->tester->getStatusCode());
            $this->assertFalse($data['ready_for_migration']);
            $this->assertFalse($data['assessment']['can_migrate']);
            $this->assertStringContainsString('Unable to inspect', implode(' ', $data['issues']));
            $this->assertStringNotContainsString('external storage', implode(' ', $data['assessment']['warnings']));
        } finally {
            chmod($ancestor, 0755);
            unlink($this->tempDir . '/contents');
            rename($ancestor . '/contents', $this->tempDir . '/content');
            rmdir($ancestor);
        }
    }

    public function testScanAllowsVerifiablyMissingLinkedContent(): void
    {
        $this->createContentDatabase();
        $ancestor = $this->tempDir . '/outside-parent';
        mkdir($ancestor);
        rename($this->tempDir . '/content', $ancestor . '/held-contents');
        symlink($ancestor . '/missing-contents', $this->tempDir . '/contents');
        try {
            $this->tester->execute(['--json' => true, '--force' => true]);
            $data = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(0, $this->tester->getStatusCode());
            $this->assertTrue($data['ready_for_migration']);
            $this->assertTrue($data['assessment']['can_migrate']);
            $this->assertSame([], $data['issues']);
            $this->assertStringContainsString('external storage', implode(' ', $data['assessment']['warnings']));
        } finally {
            unlink($this->tempDir . '/contents');
            rename($ancestor . '/held-contents', $this->tempDir . '/content');
            rmdir($ancestor);
        }
    }

    private function useLocalDockerDetector(): void
    {
        $detector = new class extends DockerDetector {
            public function isKvsInDocker(): bool
            {
                return true;
            }
            public function mapHostPathToContainer(string $hostPath, string $service = 'php'): ?string
            {
                return $hostPath;
            }
            public function exec(string $service, string $command, int $timeout = 10): ?string
            {
                return shell_exec($command);
            }
        };
        (new \ReflectionProperty(ScanCommand::class, 'targetDocker'))->setValue($this->command, $detector);
    }
}
