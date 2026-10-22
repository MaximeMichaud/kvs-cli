<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\CacheCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class CacheCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private CacheCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation with cache directories
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data/engine', 0755, true);
        mkdir($this->tempDir . '/admin/smarty/cache', 0755, true);
        mkdir($this->tempDir . '/admin/smarty/template-c', 0755, true);
        mkdir($this->tempDir . '/admin/smarty/template-c-site', 0755, true);
        mkdir($this->tempDir . '/tmp', 0755, true);

        // Create some cache files
        file_put_contents($this->tempDir . '/admin/data/engine/cache_test.dat', 'cache data');
        file_put_contents($this->tempDir . '/admin/smarty/cache/test.cache', 'smarty cache');
        file_put_contents($this->tempDir . '/admin/smarty/template-c-site/site.tpl.php', 'site template cache');
        file_put_contents($this->tempDir . '/tmp/test.tmp', 'temp file');

        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new class ($this->config) extends CacheCommand {
            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return new PDO('sqlite::memory:');
            }

            protected function databaseCacheTableExists(PDO $db, string $table): bool
            {
                return false;
            }
        };
        $this->command->setName('system:cache');

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

    public function testCacheClearAll(): void
    {
        // Verify cache files exist before
        $this->assertFileExists($this->tempDir . '/admin/data/engine/cache_test.dat');
        $this->assertFileExists($this->tempDir . '/admin/smarty/cache/test.cache');

        $this->tester->execute(['--clear' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Cache cleared', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // Cache files should be deleted
        $this->assertFileDoesNotExist($this->tempDir . '/admin/data/engine/cache_test.dat');
        $this->assertFileDoesNotExist($this->tempDir . '/admin/smarty/cache/test.cache');
        $this->assertFileDoesNotExist($this->tempDir . '/admin/smarty/template-c-site/site.tpl.php');
    }

    public function testCacheClearFileType(): void
    {
        $this->tester->execute([
            '--clear' => true,
            '--type' => 'file'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Cache cleared', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // File cache should be cleared
        $this->assertFileDoesNotExist($this->tempDir . '/admin/data/engine/cache_test.dat');
        $this->assertFileDoesNotExist($this->tempDir . '/admin/smarty/cache/test.cache');
        $this->assertFileDoesNotExist($this->tempDir . '/admin/smarty/template-c-site/site.tpl.php');
    }

    public function testCacheClearInvalidTypeFailsWithoutDeletingFiles(): void
    {
        $this->tester->execute([
            '--clear' => true,
            '--type' => 'bogus'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Invalid value for --type', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertFileExists($this->tempDir . '/admin/data/engine/cache_test.dat');
    }

    public function testCacheClearDbWarnsWhenNoDatabaseCacheTablesFound(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $command = new class ($this->config, $db) extends CacheCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }

            protected function databaseCacheTableExists(PDO $db, string $table): bool
            {
                return false;
            }
        };

        $tester = new CommandTester($command);
        $tester->execute([
            '--clear' => true,
            '--type' => 'db',
        ]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $output);
        $this->assertStringContainsString('No database cache tables found', $output);
        $this->assertStringContainsString('ktvs_stats_cache', $output);
        $this->assertStringContainsString('ktvs_admin_system_cache', $output);
    }

    public function testCacheClearReportsFailedFileDeletion(): void
    {
        $command = new class ($this->config) extends CacheCommand {
            protected function removeCacheFile(string $path): bool
            {
                return false;
            }
        };
        $tester = new CommandTester($command);
        $tester->execute(['--clear' => true, '--type' => 'file']);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Failed to delete cache file', $tester->getDisplay());
        $this->assertStringNotContainsString('Cache cleared successfully', $tester->getDisplay());
        $this->assertStringNotContainsString('Cleared 1 files from engine', $tester->getDisplay());
        $this->assertFileExists($this->tempDir . '/admin/data/engine/cache_test.dat');
    }

    public function testCacheClearUnlinksSymbolicLinkWithoutDeletingItsTarget(): void
    {
        $target = $this->tempDir . '/outside-cache.dat';
        $link = $this->tempDir . '/admin/data/engine/link.cache';
        file_put_contents($target, 'preserve outside data');
        $this->assertTrue(symlink($target, $link));

        $this->tester->execute(['--clear' => true, '--type' => 'file']);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertFileExists($target);
        $this->assertSame('preserve outside data', file_get_contents($target));
        $this->assertFalse(is_link($link));
    }

    public function testCacheClearFailsWhenDatabaseConnectionIsUnavailable(): void
    {
        $command = new class ($this->config) extends CacheCommand {
            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return null;
            }
        };
        $tester = new CommandTester($command);
        $tester->execute(['--clear' => true, '--type' => 'db']);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringNotContainsString('Cache cleared successfully', $tester->getDisplay());
    }

    public function testCacheClearFailsWhenDatabaseDeletionIsRejected(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE cache_entries (marker TEXT)');
        $db->exec("INSERT INTO cache_entries VALUES ('preserve cached data')");
        $db->exec("CREATE TRIGGER reject_cache_deletion BEFORE DELETE ON cache_entries
            BEGIN SELECT RAISE(ABORT, 'Cache deletion rejected'); END");
        $command = new class ($this->config, $db) extends CacheCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }

            protected function databaseCacheTableExists(PDO $db, string $table): bool
            {
                return true;
            }

            protected function truncateDatabaseCacheTable(PDO $db, string $table): void
            {
                $db->exec('DELETE FROM cache_entries');
            }
        };
        $tester = new CommandTester($command);
        $tester->execute(['--clear' => true, '--type' => 'db']);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Cache deletion rejected', $tester->getDisplay());
        $this->assertStringNotContainsString('Cache cleared successfully', $tester->getDisplay());
        $this->assertSame('preserve cached data', $db->query('SELECT marker FROM cache_entries')->fetchColumn());
    }

    public function testCacheStats(): void
    {
        $this->tester->execute(['--stats' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Engine cache', $output);
        $this->assertStringContainsString('Smarty cache', $output);
        $this->assertStringContainsString('Site template cache', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCacheStatsReportsPartialResultsWhenOneDirectoryIsUnavailable(): void
    {
        $cache = $this->tempDir . '/admin/data/engine';
        chmod($cache, 0000);
        clearstatcache();

        try {
            if (is_readable($cache)) {
                $this->markTestSkipped('Filesystem permission refusal requires an unprivileged process');
            }

            $this->tester->execute(['--stats' => true]);

            $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $this->assertStringContainsString('Smarty cache', $this->tester->getDisplay());
            $this->assertStringContainsString('Cache statistics are incomplete', $this->tester->getDisplay());
            $this->assertStringNotContainsString('No cache directories found', $this->tester->getDisplay());
        } finally {
            chmod($cache, 0755);
        }
    }

    public function testCacheStatsRejectsClearOnlyType(): void
    {
        $this->tester->execute([
            '--stats' => true,
            '--type' => 'db',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('stats action does not support --type', $output);
        $this->assertStringNotContainsString('Engine cache', $output);
    }

    public function testCacheStatsAndClearCannotBeCombined(): void
    {
        $this->tester->execute([
            '--stats' => true,
            '--clear' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('cannot be used together', $output);
        $this->assertFileExists($this->tempDir . '/admin/data/engine/cache_test.dat');
    }

    public function testCacheNoAction(): void
    {
        // Running without any option shows help
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('--clear', $output);
        $this->assertStringContainsString('--stats', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCacheNoActionRejectsClearOnlyType(): void
    {
        $this->tester->execute(['--type' => 'db']);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('default action does not support --type', $output);
        $this->assertStringNotContainsString('Available options', $output);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function unavailableFileCachePaths(): iterable
    {
        $cases = [
            'ancestor-unsearchable',
            'root-unreadable',
            'root-unsearchable',
            'child-unreadable',
            'child-unsearchable',
            'root-symlink-blocked',
            'ancestor-symlink-blocked',
            'root-wrong-type',
            'ancestor-wrong-type',
            'root-symlink-to-file',
            'root-symlink-loop',
            'missing-below-unsearchable-ancestor',
        ];
        foreach ($cases as $case) {
            foreach ([false, true] as $clear) {
                yield $case . ($clear ? ' clear' : ' stats') => [$case, $clear];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableFileCachePaths')]
    public function testFileCacheRejectsUnavailablePathsWithoutFalseSuccess(string $case, bool $clear): void
    {
        $fixture = $this->createCachePathFixture($case);
        try {
            $result = $this->executeCachePathFixture($fixture, $clear);
            $protectedRemains = $this->cacheFixtureProtectedEntryExists($fixture);
            $recovery = $fixture['permission_path'] !== null
                ? $this->executeCachePathFixture($fixture, $clear, false)
                : null;
            $message = json_encode([
                'case' => $case,
                'result' => $result,
                'protected_entry_remained' => $protectedRemains,
                'recovery' => $recovery,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->assertNull($result['exception'], $message);
            $this->assertSame(1, $result['exit'], $message);
            $this->assertStringContainsString('[ERROR]', $result['output'], $message);
            $this->assertStringNotContainsString('Cache cleared successfully', $result['output'], $message);
            $this->assertStringNotContainsString('No cache directories found', $result['output'], $message);
            $this->assertStringNotContainsString('Cache directories exist but are empty', $result['output'], $message);
            if ($fixture['protected_path'] !== null) {
                $this->assertTrue($protectedRemains, $message);
            }
            if ($recovery !== null) {
                $this->assertNull($recovery['exception'], $message);
                $this->assertSame(0, $recovery['exit'], $message);
                if ($fixture['protected_path'] !== null) {
                    $this->assertSame(!$clear, $this->cacheFixtureProtectedEntryExists($fixture), $message);
                }
            }
        } finally {
            TestHelper::removeDir($fixture['site']);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function healthyOrAbsentFileCachePaths(): iterable
    {
        $cases = [
            'absent',
            'empty',
            'healthy',
            'absent-below-search-only-ancestor',
            'healthy-below-search-only-ancestor',
            'healthy-root-symlink-absolute',
            'healthy-root-symlink-relative',
            'healthy-ancestor-symlink',
            'unreadable-file',
        ];
        foreach ($cases as $case) {
            foreach ([false, true] as $clear) {
                yield $case . ($clear ? ' clear' : ' stats') => [$case, $clear];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('healthyOrAbsentFileCachePaths')]
    public function testFileCacheAcceptsVerifiedAbsenceAndHealthyPaths(string $case, bool $clear): void
    {
        $fixture = $this->createCachePathFixture($case);
        try {
            $result = $this->executeCachePathFixture($fixture, $clear);
            $message = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->assertNull($result['exception'], $message);
            $this->assertSame(0, $result['exit'], $message);
            $this->assertStringNotContainsString('[ERROR]', $result['output'], $message);
            if ($fixture['protected_path'] !== null) {
                $this->assertSame(!$clear, $this->cacheFixtureProtectedEntryExists($fixture), $message);
            }
            if (str_starts_with($case, 'healthy-root-symlink')) {
                $this->assertTrue(is_link($fixture['cache']), 'A valid cache root link must be preserved');
            }
            if ($case === 'healthy-ancestor-symlink') {
                $this->assertTrue(is_link($fixture['site'] . '/admin/data'));
            }
            if (!$clear) {
                if (str_starts_with($case, 'absent')) {
                    $this->assertStringContainsString('No cache directories found', $result['output']);
                } else {
                    $this->assertStringContainsString('Engine cache', $result['output']);
                    $this->assertStringContainsString($case === 'empty' ? '0 B' : '23.00 B', $result['output']);
                }
            }
        } finally {
            TestHelper::removeDir($fixture['site']);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function internalCacheLinks(): iterable
    {
        foreach (['file', 'directory'] as $kind) {
            foreach ([false, true] as $clear) {
                yield $kind . ($clear ? ' clear' : ' stats') => [$kind, $clear];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('internalCacheLinks')]
    public function testFileCachePreservesInternalSymlinkTargets(string $kind, bool $clear): void
    {
        $fixture = $this->createCachePathFixture('healthy');
        try {
            $target = $fixture['site'] . '/external-target';
            if ($kind === 'directory') {
                mkdir($target, 0755);
                $targetFile = $target . '/preserved.bin';
            } else {
                $targetFile = $target;
            }
            file_put_contents($targetFile, 'Preserve synthetic external data');
            $link = $fixture['cache'] . '/linked-entry';
            $this->assertTrue(symlink($target, $link));
            $result = $this->executeCachePathFixture($fixture, $clear);
            $message = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->assertNull($result['exception'], $message);
            $this->assertSame(0, $result['exit'], $message);
            $this->assertSame('Preserve synthetic external data', file_get_contents($targetFile));
            $this->assertSame(!$clear || $kind === 'directory', is_link($link), $message);
            $this->assertSame(!$clear, $this->cacheFixtureProtectedEntryExists($fixture), $message);
            if (!$clear && $kind === 'directory') {
                $this->assertStringContainsString('23.00 B', $result['output']);
            }
        } finally {
            TestHelper::removeDir($fixture['site']);
        }
    }

    /**
     * @return array{site: string, cache: string, protected_path: ?string, protected_link: bool,
     *     permission_path: ?string, permission_mode: ?int, expected_refusal: ?string}
     */
    private function createCachePathFixture(string $case): array
    {
        $site = $this->tempDir . '/cache-path-' . $case;
        $cache = $site . '/admin/data/engine';
        mkdir($site . '/admin/include', 0755, true);
        file_put_contents($site . '/admin/include/setup.php', '<?php');
        file_put_contents($site . '/admin/include/setup_db.php', '<?php');
        $fixture = [
            'site' => $site,
            'cache' => $cache,
            'protected_path' => null,
            'protected_link' => false,
            'permission_path' => null,
            'permission_mode' => null,
            'expected_refusal' => null,
        ];
        if ($case === 'absent') {
            return $fixture;
        }
        if (in_array($case, ['absent-below-search-only-ancestor', 'missing-below-unsearchable-ancestor'], true)) {
            mkdir(dirname($cache), 0755, true);
            $fixture['permission_path'] = dirname($cache);
            $fixture['permission_mode'] = $case === 'absent-below-search-only-ancestor' ? 0111 : 0000;
            $fixture['expected_refusal'] = $case === 'absent-below-search-only-ancestor' ? 'read' : 'search';
            return $fixture;
        }
        if ($case === 'ancestor-wrong-type') {
            file_put_contents(dirname($cache), 'Synthetic wrong ancestor type');
            $fixture['protected_path'] = dirname($cache);
            return $fixture;
        }
        mkdir(dirname($cache), 0755, true);
        if ($case === 'root-wrong-type') {
            file_put_contents($cache, 'Synthetic wrong root type');
            $fixture['protected_path'] = $cache;
            return $fixture;
        }
        if ($case === 'root-symlink-to-file') {
            $target = $site . '/external-file';
            file_put_contents($target, 'Synthetic target must remain');
            symlink($target, $cache);
            $fixture['protected_path'] = $target;
            return $fixture;
        }
        if ($case === 'root-symlink-loop') {
            symlink('engine', $cache);
            $fixture['protected_path'] = $cache;
            $fixture['protected_link'] = true;
            return $fixture;
        }
        if (in_array($case, ['root-symlink-blocked', 'healthy-root-symlink-absolute', 'healthy-root-symlink-relative'], true)) {
            $target = $site . '/external/blocked/cache';
            mkdir($target, 0755, true);
            symlink($case === 'healthy-root-symlink-relative' ? '../../external/blocked/cache' : $target, $cache);
            if ($case === 'root-symlink-blocked') {
                $fixture['permission_path'] = dirname($target);
                $fixture['permission_mode'] = 0000;
                $fixture['expected_refusal'] = 'search';
            }
        } elseif (in_array($case, ['ancestor-symlink-blocked', 'healthy-ancestor-symlink'], true)) {
            rmdir(dirname($cache));
            mkdir($site . '/external/data/engine', 0755, true);
            symlink('../external/data', dirname($cache));
            if ($case === 'ancestor-symlink-blocked') {
                $fixture['permission_path'] = $site . '/external/data';
                $fixture['permission_mode'] = 0000;
                $fixture['expected_refusal'] = 'search';
            }
        } else {
            mkdir($cache, 0755);
        }
        if ($case === 'empty') {
            return $fixture;
        }
        $fixture['protected_path'] = $cache . '/fixture.cache';
        if (str_starts_with($case, 'child-')) {
            file_put_contents($cache . '/visible.cache', 'Visible partial cache data');
            mkdir($cache . '/blocked', 0755);
            $fixture['protected_path'] = $cache . '/blocked/fixture.cache';
            $fixture['permission_path'] = $cache . '/blocked';
            $fixture['permission_mode'] = $case === 'child-unreadable' ? 0300 : 0600;
            $fixture['expected_refusal'] = $case === 'child-unreadable' ? 'read' : 'search';
        } elseif ($case === 'ancestor-unsearchable') {
            $fixture['permission_path'] = dirname($cache);
            $fixture['permission_mode'] = 0000;
            $fixture['expected_refusal'] = 'search';
        } elseif (in_array($case, ['root-unreadable', 'root-unsearchable'], true)) {
            $fixture['permission_path'] = $cache;
            $fixture['permission_mode'] = $case === 'root-unreadable' ? 0300 : 0600;
            $fixture['expected_refusal'] = $case === 'root-unreadable' ? 'read' : 'search';
        } elseif ($case === 'healthy-below-search-only-ancestor') {
            $fixture['permission_path'] = dirname($cache);
            $fixture['permission_mode'] = 0111;
            $fixture['expected_refusal'] = 'read';
        } elseif ($case === 'unreadable-file') {
            $fixture['permission_path'] = $fixture['protected_path'];
            $fixture['permission_mode'] = 0000;
            $fixture['expected_refusal'] = 'read';
        }
        file_put_contents($fixture['protected_path'], 'Synthetic cache payload');
        return $fixture;
    }

    /**
     * @param array{site: string, cache: string, protected_path: ?string, protected_link: bool,
     *     permission_path: ?string, permission_mode: ?int, expected_refusal: ?string} $fixture
     * @return array{exit: ?int, exception: ?string, output: string}
     */
    private function executeCachePathFixture(array $fixture, bool $clear, bool $restrict = true): array
    {
        $permissionPath = $restrict ? $fixture['permission_path'] : null;
        $originalMode = $permissionPath === null ? null : (fileperms($permissionPath) & 0777);
        $tester = new CommandTester(new CacheCommand(new Configuration([
            'path' => $fixture['site'],
            'disable_db_env_overrides' => true,
        ])));
        $exit = null;
        $exception = null;
        try {
            if ($permissionPath !== null) {
                $this->assertTrue(chmod($permissionPath, $fixture['permission_mode']));
                clearstatcache();
                $effective = $fixture['expected_refusal'] === 'search'
                    ? !is_executable($permissionPath)
                    : !is_readable($permissionPath);
                if (!$effective) {
                    $this->markTestSkipped('Filesystem permission refusal requires an unprivileged process');
                }
            }
            try {
                $exit = $tester->execute($clear ? ['--clear' => true, '--type' => 'file'] : ['--stats' => true]);
            } catch (\Throwable $error) {
                $exception = get_class($error) . ': ' . $error->getMessage();
            }
        } finally {
            if ($permissionPath !== null && (file_exists($permissionPath) || is_link($permissionPath))) {
                chmod($permissionPath, $originalMode);
                clearstatcache();
            }
        }
        return ['exit' => $exit, 'exception' => $exception, 'output' => $tester->getDisplay()];
    }

    /** @param array{protected_path: ?string, protected_link: bool} $fixture */
    private function cacheFixtureProtectedEntryExists(array $fixture): bool
    {
        if ($fixture['protected_path'] === null) {
            return false;
        }
        clearstatcache();
        return $fixture['protected_link'] ? is_link($fixture['protected_path']) : is_file($fixture['protected_path']);
    }
}
