<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\AlbumCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AlbumShowDisplayTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
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

    public function testAlbumShowDisplaysCountedImages(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_albums (' .
            'album_id INTEGER, user_id INTEGER, title TEXT, status_id INTEGER, is_private INTEGER, ' .
            'post_date TEXT, album_viewed INTEGER, rating INTEGER, rating_amount INTEGER)'
        );
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT)');
        $db->exec('CREATE TABLE ktvs_albums_images (album_id INTEGER)');
        $db->exec("INSERT INTO ktvs_users VALUES (7, 'owner')");
        $db->exec(
            "INSERT INTO ktvs_albums VALUES " .
            "(1, 7, 'Album With Images', 1, 1, '2024-01-01 00:00:00', 12, 40, 10)"
        );
        $db->exec('INSERT INTO ktvs_albums_images VALUES (1), (1), (1)');

        $tester = new CommandTester($this->createAlbumCommand($db));
        $tester->execute(['action' => 'show', 'id' => '1']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Images', $output);
        $this->assertMatchesRegularExpression('/Access\s*│\s*Private/', $output);
        $this->assertMatchesRegularExpression('/User\s*│\s*owner/', $output);
        $this->assertMatchesRegularExpression('/Images\s*│\s*3/', $output);
    }

    private function createSqliteConnection(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $db->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        return $db;
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
