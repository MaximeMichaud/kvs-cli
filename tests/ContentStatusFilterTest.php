<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\AlbumCommand;
use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Command\Content\TagCommand;
use KVS\CLI\Command\Content\VideoCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ContentStatusFilterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        file_put_contents(
            $this->tempDir . '/admin/include/setup_db.php',
            "<?php\n"
            . "define('DB_HOST', 'localhost');\n"
            . "define('DB_LOGIN', 'user');\n"
            . "define('DB_PASS', 'pass');\n"
            . "define('DB_DEVICE', 'kvs');\n"
        );
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            "<?php\n\$config = ['tables_prefix' => 'ktvs_'];\n"
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testCategoryListAppliesNumericStatusFilter(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec('CREATE TABLE ktvs_categories (category_id INTEGER, title TEXT, status_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories_videos (category_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories_albums (category_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_categories (category_id, title, status_id) VALUES " .
            "(1, 'Active', 1), (2, 'Inactive', 0)"
        );

        $tester = new CommandTester($this->createCategoryCommand($db));
        $tester->execute([
            'action' => 'list',
            '--status' => '1',
            '--format' => 'json',
            '--fields' => 'category_id,status_id',
            '--limit' => '10',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $this->columnAsInts($this->decodeJsonRows($tester->getDisplay()), 'category_id'));
    }

    public function testTagListTreatsNumericActiveStatusAsActive(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec('CREATE TABLE ktvs_tags (tag_id INTEGER, tag TEXT, status_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags_videos (tag_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_tags_albums (tag_id INTEGER)');
        $db->exec("INSERT INTO ktvs_tags (tag_id, tag, status_id) VALUES (1, 'Active', 1), (2, 'Inactive', 0)");

        $tester = new CommandTester($this->createTagCommand($db));
        $tester->execute([
            'action' => 'list',
            '--status' => '1',
            '--format' => 'json',
            '--fields' => 'tag_id,status_id',
            '--limit' => '10',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $this->columnAsInts($this->decodeJsonRows($tester->getDisplay()), 'tag_id'));
    }

    public function testVideoListAppliesNumericStatusFilter(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, user_id INTEGER, status_id INTEGER, title TEXT, post_date TEXT, ' .
            'video_viewed INTEGER, rating_amount INTEGER, rating REAL)'
        );
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users (user_id, username) VALUES (1, 'user')");
        $db->exec(
            "INSERT INTO ktvs_videos (video_id, user_id, status_id, title, post_date, video_viewed, rating_amount, rating) VALUES " .
            "(1, 1, 1, 'Active', '2024-01-02 00:00:00', 0, 0, 0), " .
            "(2, 1, 0, 'Disabled', '2024-01-01 00:00:00', 0, 0, 0)"
        );

        $tester = new CommandTester($this->createVideoCommand($db));
        $tester->execute([
            'action' => 'list',
            '--status' => '1',
            '--format' => 'json',
            '--fields' => 'video_id,status_id',
            '--limit' => '10',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $this->columnAsInts($this->decodeJsonRows($tester->getDisplay()), 'video_id'));
    }

    public function testAlbumListAppliesNumericStatusFilter(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_albums (' .
            'album_id INTEGER, user_id INTEGER, status_id INTEGER, title TEXT, post_date TEXT, ' .
            'album_viewed INTEGER, rating_amount INTEGER, rating REAL)'
        );
        $db->exec('CREATE TABLE ktvs_albums_images (album_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec("INSERT INTO ktvs_users (user_id, username) VALUES (1, 'user')");
        $db->exec(
            "INSERT INTO ktvs_albums (album_id, user_id, status_id, title, post_date, album_viewed, rating_amount, rating) VALUES " .
            "(1, 1, 1, 'Active', '2024-01-02 00:00:00', 0, 0, 0), " .
            "(2, 1, 0, 'Disabled', '2024-01-01 00:00:00', 0, 0, 0)"
        );

        $tester = new CommandTester($this->createAlbumCommand($db));
        $tester->execute([
            'action' => 'list',
            '--status' => '1',
            '--format' => 'json',
            '--fields' => 'album_id,status_id',
            '--limit' => '10',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $this->columnAsInts($this->decodeJsonRows($tester->getDisplay()), 'album_id'));
    }

    private function createSqliteConnection(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $db;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeJsonRows(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function columnAsInts(array $rows, string $column): array
    {
        return array_map(static fn(array $row): int => (int) $row[$column], $rows);
    }

    private function createCategoryCommand(\PDO $db): CategoryCommand
    {
        return new class ($this->createConfig(), $db) extends CategoryCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:category');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createTagCommand(\PDO $db): TagCommand
    {
        return new class ($this->createConfig(), $db) extends TagCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:tag');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createVideoCommand(\PDO $db): VideoCommand
    {
        return new class ($this->createConfig(), $db) extends VideoCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:video');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createAlbumCommand(\PDO $db): AlbumCommand
    {
        return new class ($this->createConfig(), $db) extends AlbumCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:album');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }

    private function createConfig(): Configuration
    {
        return new Configuration(['path' => $this->tempDir]);
    }
}
