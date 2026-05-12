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
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
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

        $db->exec('INSERT INTO ktvs_albums (album_id) VALUES (1), (2)');
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
        $this->assertStringContainsString('Album not found', $tester->getDisplay());
    }

    private function createDeleteSchema(\PDO $db): void
    {
        $db->exec('CREATE TABLE ktvs_albums (album_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums_images (album_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories_albums (album_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags_albums (album_id INTEGER)');
    }
}
