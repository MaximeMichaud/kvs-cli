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
        $this->command = new CacheCommand($this->config);

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

    public function testCacheStats(): void
    {
        $this->tester->execute(['--stats' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Engine cache', $output);
        $this->assertStringContainsString('Smarty cache', $output);
        $this->assertStringContainsString('Site template cache', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
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
}
