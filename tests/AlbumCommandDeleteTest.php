<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\AlbumCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class AlbumCommandDeleteTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testDeleteAlbumUsesKvsNativeCleanup(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);

        $db->exec('INSERT INTO ktvs_albums (album_id, status_id) VALUES (1, 1), (2, 1)');
        $db->exec('INSERT INTO ktvs_albums_images (album_id) VALUES (1)');
        $db->exec('INSERT INTO ktvs_categories_albums (album_id) VALUES (1)');
        $db->exec('INSERT INTO ktvs_tags_albums (album_id) VALUES (1)');

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends AlbumCommand {
            /** @var list<int> */
            public array $deletedAlbumIds = [];

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:album');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deleteAlbumWithKvs(int $albumId): void
            {
                $this->deletedAlbumIds[] = $albumId;
            }
        };

        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'delete', 'id' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $command->deletedAlbumIds);

        $albums = $db->query('SELECT album_id FROM ktvs_albums ORDER BY album_id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([1, 2], array_map('intval', $albums));

        $images = $db->query('SELECT album_id FROM ktvs_albums_images')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([1], array_map('intval', $images));
    }

    public function testDeleteAlbumDoesNotCallKvsCleanupWhenAlbumDoesNotExist(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends AlbumCommand {
            public bool $kvsCleanupCalled = false;

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:album');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deleteAlbumWithKvs(int $albumId): void
            {
                $this->kvsCleanupCalled = true;
            }
        };

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'delete', 'id' => '999']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertFalse($command->kvsCleanupCalled);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Album not found', $output);
        $this->assertStringNotContainsString('This will delete album #999', $output);
    }

    public function testDeleteAlbumNoInteractionFailsWithoutCleanup(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);
        $db->exec('INSERT INTO ktvs_albums (album_id, status_id) VALUES (1, 1)');

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends AlbumCommand {
            public bool $kvsCleanupCalled = false;

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:album');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deleteAlbumWithKvs(int $albumId): void
            {
                $this->kvsCleanupCalled = true;
            }
        };

        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'delete',
            'id' => '1',
        ], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertFalse($command->kvsCleanupCalled);
        $this->assertStringContainsString('confirmation was not provided', $tester->getDisplay());
    }

    public function testDeleteAlbumDoesNotCallKvsCleanupForDeletingStatus(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);
        $db->exec('INSERT INTO ktvs_albums (album_id, status_id) VALUES (1, 4)');

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends AlbumCommand {
            public bool $kvsCleanupCalled = false;

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:album');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deleteAlbumWithKvs(int $albumId): void
            {
                $this->kvsCleanupCalled = true;
            }
        };

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'delete', 'id' => '1']);

        $display = $tester->getDisplay();
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertFalse($command->kvsCleanupCalled);
        $this->assertStringContainsString('Album cannot be deleted in its current status: #1 (Deleting)', $display);
        $this->assertStringNotContainsString('This will delete album #1', $display);
    }

    private function createDeleteSchema(\PDO $db): void
    {
        $db->exec('CREATE TABLE ktvs_albums (album_id INTEGER, status_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums_images (album_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories_albums (album_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags_albums (album_id INTEGER)');
    }

    /**
     * @dataProvider nativeDeletionResultProvider
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNativeDeletionRequiresAPersistedTaskAndRollsBackFailures(
        string $result,
        string $engine,
        int $expectedStatus,
        int $expectedContentStatus
    ): void {
        $db = \KVS\CLI\Tests\Support\NativeDeletionFixture::create($this->tempDir, 'album', $result, $engine);
        $configuration = new Configuration(['path' => $this->tempDir]);
        $command = new class ($configuration, $db) extends AlbumCommand {
            public function __construct(Configuration $configuration, private \PDO $database)
            {
                parent::__construct($configuration);
                $this->setName('content:album');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->database;
            }
        };
        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'delete', 'id' => '1']);

        $queued = $expectedStatus === 0;
        $this->assertSame($expectedStatus, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame($expectedContentStatus, (int) $db->query('SELECT status_id FROM fixture_albums')->fetchColumn());
        $this->assertSame($queued ? 1 : 0, (int) $db->query('SELECT COUNT(*) FROM fixture_background_tasks')->fetchColumn());
        $this->assertSame($queued ? 1 : 0, (int) $db->query('SELECT COUNT(*) FROM fixture_admin_audit_log')->fetchColumn());
        $this->assertFalse($db->inTransaction());
        if (!$queued) {
            $this->assertStringNotContainsString('queued for KVS deletion', $tester->getDisplay());
        }
    }

    /**
     * @return iterable<string, array{string, string, int, int}>
     */
    public static function nativeDeletionResultProvider(): iterable
    {
        yield 'native success without a task' => ['no-task', 'InnoDB', 1, 1];
        yield 'native success without an audit entry' => ['missing-audit', 'InnoDB', 1, 1];
        yield 'native false after writing' => ['false', 'InnoDB', 1, 1];
        yield 'native exception after writing' => ['throw', 'InnoDB', 1, 1];
        yield 'native success with persisted task' => ['queued', 'InnoDB', 0, 4];
        yield 'status changed after confirmation' => ['status-changed', 'InnoDB', 1, 3];
        yield 'nontransactional storage' => ['queued', 'MyISAM', 1, 1];
    }
}
