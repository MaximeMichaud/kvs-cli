<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Command\Content\TagCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class TagCategoryCleanupTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php $config = ["tables_prefix" => "ktvs_"];');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testCategoryDeleteCleansAllKvsAssociationTablesAndReferences(): void
    {
        $db = $this->createDatabase();
        $this->createCategorySchema($db);

        $db->exec("INSERT INTO ktvs_categories (category_id, title, category_group_id) VALUES (1, 'Delete', 0)");
        $db->exec("INSERT INTO ktvs_categories (category_id, title, category_group_id) VALUES (2, 'Keep', 0)");

        foreach ($this->categoryRelationTables() as $suffix => $objectColumn) {
            $db->exec("INSERT INTO ktvs_categories_{$suffix} (category_id, {$objectColumn}) VALUES (1, 100)");
            $db->exec("INSERT INTO ktvs_categories_{$suffix} (category_id, {$objectColumn}) VALUES (2, 200)");
        }

        $db->exec('INSERT INTO ktvs_users (user_id, favourite_category_id) VALUES (1, 1), (2, 2)');
        $db->exec('INSERT INTO ktvs_stats_referers_list (referer_id, category_id) VALUES (1, 1), (2, 2)');

        $tester = new CommandTester($this->createCategoryCommand($db));
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'delete', 'id' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_categories WHERE category_id = 1'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_categories WHERE category_id = 2'));

        foreach ($this->categoryRelationTables() as $suffix => $objectColumn) {
            $deleteCountSql = "SELECT COUNT(*) FROM ktvs_categories_{$suffix} WHERE category_id = 1";
            $keepCountSql = "SELECT COUNT(*) FROM ktvs_categories_{$suffix} WHERE category_id = 2";

            $this->assertSame(0, $this->fetchInt($db, $deleteCountSql));
            $this->assertSame(1, $this->fetchInt($db, $keepCountSql));
        }

        $this->assertSame(0, $this->fetchInt($db, 'SELECT favourite_category_id FROM ktvs_users WHERE user_id = 1'));
        $this->assertSame(2, $this->fetchInt($db, 'SELECT favourite_category_id FROM ktvs_users WHERE user_id = 2'));
        $this->assertSame(
            0,
            $this->fetchInt($db, 'SELECT category_id FROM ktvs_stats_referers_list WHERE referer_id = 1')
        );
        $this->assertSame(
            2,
            $this->fetchInt($db, 'SELECT category_id FROM ktvs_stats_referers_list WHERE referer_id = 2')
        );
    }

    public function testTagDeleteCleansAllKvsAssociationTables(): void
    {
        $db = $this->createDatabase();
        $this->createTagSchema($db);

        $db->exec("INSERT INTO ktvs_tags (tag_id, tag) VALUES (1, 'Delete'), (2, 'Keep')");

        foreach ($this->tagRelationTables() as $suffix => $objectColumn) {
            $db->exec("INSERT INTO ktvs_tags_{$suffix} (tag_id, {$objectColumn}) VALUES (1, 100)");
            $db->exec("INSERT INTO ktvs_tags_{$suffix} (tag_id, {$objectColumn}) VALUES (2, 200)");
        }

        $tester = new CommandTester($this->createTagCommand($db));
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'delete', 'identifier' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_tags WHERE tag_id = 1'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_tags WHERE tag_id = 2'));

        foreach ($this->tagRelationTables() as $suffix => $objectColumn) {
            $this->assertSame(0, $this->fetchInt($db, "SELECT COUNT(*) FROM ktvs_tags_{$suffix} WHERE tag_id = 1"));
            $this->assertSame(1, $this->fetchInt($db, "SELECT COUNT(*) FROM ktvs_tags_{$suffix} WHERE tag_id = 2"));
        }
    }

    public function testTagMergeMovesAllKvsAssociationTablesAndDropsDuplicates(): void
    {
        $db = $this->createDatabase();
        $this->createTagSchema($db);

        $db->exec("INSERT INTO ktvs_tags (tag_id, tag) VALUES (1, 'Source'), (2, 'Target')");

        foreach ($this->tagRelationTables() as $suffix => $objectColumn) {
            $db->exec("INSERT INTO ktvs_tags_{$suffix} (tag_id, {$objectColumn}) VALUES (1, 100)");
            $db->exec("INSERT INTO ktvs_tags_{$suffix} (tag_id, {$objectColumn}) VALUES (1, 200)");
            $db->exec("INSERT INTO ktvs_tags_{$suffix} (tag_id, {$objectColumn}) VALUES (2, 200)");
        }

        $tester = new CommandTester($this->createTagCommand($db));
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'merge', 'identifier' => '1', 'target' => '2']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_tags WHERE tag_id = 1'));

        foreach ($this->tagRelationTables() as $suffix => $objectColumn) {
            $sourceRows = $this->fetchInt($db, "SELECT COUNT(*) FROM ktvs_tags_{$suffix} WHERE tag_id = 1");
            $targetRows = $this->fetchInt($db, "SELECT COUNT(*) FROM ktvs_tags_{$suffix} WHERE tag_id = 2");

            $this->assertSame(0, $sourceRows);
            $this->assertSame(2, $targetRows);
            $this->assertSame(
                [100, 200],
                $this->fetchInts($db, "SELECT {$objectColumn} FROM ktvs_tags_{$suffix} ORDER BY {$objectColumn}")
            );
        }
    }

    private function createDatabase(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
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

    private function createConfig(): Configuration
    {
        return new Configuration(['path' => $this->tempDir]);
    }

    private function createCategorySchema(\PDO $db): void
    {
        $db->exec('CREATE TABLE ktvs_categories (category_id INTEGER, title TEXT, category_group_id INTEGER)');
        foreach ($this->categoryRelationTables() as $suffix => $objectColumn) {
            $db->exec("CREATE TABLE ktvs_categories_{$suffix} (category_id INTEGER, {$objectColumn} INTEGER)");
        }
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, favourite_category_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_stats_referers_list (referer_id INTEGER, category_id INTEGER)');
    }

    private function createTagSchema(\PDO $db): void
    {
        $db->exec('CREATE TABLE ktvs_tags (tag_id INTEGER, tag TEXT)');
        foreach ($this->tagRelationTables() as $suffix => $objectColumn) {
            $db->exec("CREATE TABLE ktvs_tags_{$suffix} (tag_id INTEGER, {$objectColumn} INTEGER)");
        }
    }

    /**
     * @return array<string, string>
     */
    private function categoryRelationTables(): array
    {
        return [
            'videos' => 'video_id',
            'content_sources' => 'content_source_id',
            'albums' => 'album_id',
            'posts' => 'post_id',
            'playlists' => 'playlist_id',
            'dvds' => 'dvd_id',
            'dvds_groups' => 'dvd_group_id',
            'models' => 'model_id',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function tagRelationTables(): array
    {
        return [
            'videos' => 'video_id',
            'albums' => 'album_id',
            'posts' => 'post_id',
            'playlists' => 'playlist_id',
            'content_sources' => 'content_source_id',
            'models' => 'model_id',
            'dvds' => 'dvd_id',
            'dvds_groups' => 'dvd_group_id',
        ];
    }

    private function fetchInt(\PDO $db, string $sql): int
    {
        $value = $db->query($sql)->fetchColumn();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<int>
     */
    private function fetchInts(\PDO $db, string $sql): array
    {
        $statement = $db->query($sql);
        $values = $statement->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_map('intval', $values));
    }
}
