<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CategoryCommandMergeAssignGroupTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-category-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/contents/categories', 0755, true);
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["tables_prefix" => "ktvs_", "tables_prefix_multi" => "ktvs_", '
            . '"content_path_categories" => "' . $this->tempDir . '/contents/categories"];'
        );
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->tempDir);
    }

    public function testMergeMovesNonOverlappingVideosAndDeletesSource(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->insertCategories($db, [
            1 => ['Source', 0],
            2 => ['Target', 0],
        ]);
        $db->exec('INSERT INTO ktvs_categories_videos (category_id, video_id) VALUES (1, 100), (2, 200)');

        $tester = $this->executeMerge($db, '1', '2');

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_categories WHERE category_id = 1'));
        $this->assertSame(
            [100, 200],
            $this->fetchInts($db, 'SELECT video_id FROM ktvs_categories_videos WHERE category_id = 2 ORDER BY video_id')
        );
    }

    public function testMergeDropsOverlappingVideoRelations(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->insertCategories($db, [
            1 => ['Source', 0],
            2 => ['Target', 0],
        ]);
        $db->exec(
            'INSERT INTO ktvs_categories_videos (category_id, video_id) VALUES '
            . '(1, 100), (1, 200), (2, 200), (2, 300)'
        );

        $tester = $this->executeMerge($db, '1', '2');

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_categories_videos WHERE category_id = 1'));
        $this->assertSame(
            [100, 200, 300],
            $this->fetchInts($db, 'SELECT video_id FROM ktvs_categories_videos WHERE category_id = 2 ORDER BY video_id')
        );
    }

    public function testMergeWithoutInteractiveConfirmationFailsWithoutWrites(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->insertCategories($db, [
            1 => ['Source', 0],
            2 => ['Target', 0],
        ]);
        $db->exec('INSERT INTO ktvs_categories_videos (category_id, video_id) VALUES (1, 100), (2, 200)');

        $tester = new CommandTester($this->createCategoryCommand($db));
        $tester->execute(
            ['action' => 'merge', 'id' => '1', 'values' => ['2']],
            ['interactive' => false]
        );

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString(
            'Category merge cancelled because confirmation was not provided.',
            $tester->getDisplay()
        );
        $this->assertSame(1, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_categories WHERE category_id = 1'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_categories_videos WHERE category_id = 1'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_categories_videos WHERE category_id = 2'));
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_admin_audit_log'));
    }

    public function testMergeWithSameIdFailsWithoutWrites(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->insertCategories($db, [
            1 => ['Source', 0],
            2 => ['Target', 0],
        ]);
        $db->exec('INSERT INTO ktvs_categories_videos (category_id, video_id) VALUES (1, 100)');

        $tester = new CommandTester($this->createCategoryCommand($db));
        $tester->execute(['action' => 'merge', 'id' => '1', 'values' => ['1']]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame(2, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_categories'));
        $this->assertSame(1, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_categories_videos WHERE category_id = 1'));
        $this->assertSame(0, $this->fetchInt($db, 'SELECT COUNT(*) FROM ktvs_admin_audit_log'));
    }

    public function testMergeWithMissingSourceFailsBeforeTransaction(): void
    {
        $db = new class () extends \PDO {
            public int $beginTransactionCalls = 0;

            public function __construct()
            {
                parent::__construct('sqlite::memory:');
                $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            }

            public function beginTransaction(): bool
            {
                $this->beginTransactionCalls++;
                return parent::beginTransaction();
            }
        };
        $this->createSchema($db);
        $this->insertCategories($db, [
            2 => ['Target', 0],
        ]);

        $tester = new CommandTester($this->createCategoryCommand($db));
        $tester->execute(['action' => 'merge', 'id' => '1', 'values' => ['2']]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame(0, $db->beginTransactionCalls);
    }

    public function testMergeMovesFavouriteCategoryReferencesToTarget(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->insertCategories($db, [
            1 => ['Source', 0],
            2 => ['Target', 0],
        ]);
        $db->exec('INSERT INTO ktvs_users (user_id, favourite_category_id) VALUES (1, 1), (2, 2)');
        $db->exec('INSERT INTO ktvs_stats_referers_list (referer_id, category_id) VALUES (1, 1), (2, 2)');

        $tester = $this->executeMerge($db, '1', '2');

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(2, $this->fetchInt($db, 'SELECT favourite_category_id FROM ktvs_users WHERE user_id = 1'));
        $this->assertSame(2, $this->fetchInt($db, 'SELECT favourite_category_id FROM ktvs_users WHERE user_id = 2'));
        $this->assertSame(2, $this->fetchInt($db, 'SELECT category_id FROM ktvs_stats_referers_list WHERE referer_id = 1'));
        $this->assertSame(2, $this->fetchInt($db, 'SELECT category_id FROM ktvs_stats_referers_list WHERE referer_id = 2'));
    }

    public function testAssignGroupWithCommaSeparatedIds(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->insertGroup($db, 5, 'Countries');
        $this->insertCategories($db, [
            12 => ['Austrian', 0],
            15 => ['Norwegian', 0],
            18 => ['Singapore', 3],
        ]);

        $tester = $this->executeAssignGroup($db, '5', ['12,15,18']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([5, 5, 5], $this->fetchInts($db, 'SELECT category_group_id FROM ktvs_categories ORDER BY category_id'));
    }

    public function testAssignGroupWithRepeatedArgs(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->insertGroup($db, 5, 'Countries');
        $this->insertCategories($db, [
            12 => ['Austrian', 0],
            15 => ['Norwegian', 0],
            18 => ['Singapore', 0],
        ]);

        $tester = $this->executeAssignGroup($db, '5', ['12', '15', '18']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([5, 5, 5], $this->fetchInts($db, 'SELECT category_group_id FROM ktvs_categories ORDER BY category_id'));
    }

    public function testAssignGroupAbortsWhenAnyCategoryIsMissing(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->insertGroup($db, 5, 'Countries');
        $this->insertCategories($db, [
            12 => ['Austrian', 1],
            18 => ['Singapore', 2],
        ]);

        $tester = $this->executeAssignGroup($db, '5', ['12', '15', '18']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame([1, 2], $this->fetchInts($db, 'SELECT category_group_id FROM ktvs_categories ORDER BY category_id'));
    }

    public function testAssignGroupDryRunPrintsPlanWithoutWrites(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->insertGroup($db, 5, 'Countries');
        $this->insertCategories($db, [
            12 => ['Austrian', 1],
            15 => ['Norwegian', 1],
        ]);

        $tester = $this->executeAssignGroup($db, '5', ['12,15'], true);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
        $this->assertSame([1, 1], $this->fetchInts($db, 'SELECT category_group_id FROM ktvs_categories ORDER BY category_id'));
    }

    public function testAssignGroupZeroClearsGroup(): void
    {
        $db = $this->createDatabase();
        $this->createSchema($db);
        $this->insertCategories($db, [
            12 => ['Austrian', 5],
            15 => ['Norwegian', 5],
        ]);

        $tester = $this->executeAssignGroup($db, '0', ['12', '15']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([0, 0], $this->fetchInts($db, 'SELECT category_group_id FROM ktvs_categories ORDER BY category_id'));
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

    private function createConfig(): Configuration
    {
        return new Configuration(['path' => $this->tempDir]);
    }

    private function createSchema(\PDO $db): void
    {
        $db->exec('CREATE TABLE ktvs_categories (category_id INTEGER PRIMARY KEY, title TEXT, category_group_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_categories_groups (category_group_id INTEGER PRIMARY KEY, title TEXT)');

        foreach ($this->categoryRelationTables() as $suffix => $objectColumn) {
            $db->exec(
                "CREATE TABLE ktvs_categories_{$suffix} "
                . "(category_id INTEGER, {$objectColumn} INTEGER, PRIMARY KEY (category_id, {$objectColumn}))"
            );
        }

        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER PRIMARY KEY, favourite_category_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_stats_referers_list (referer_id INTEGER PRIMARY KEY, category_id INTEGER)');
        $db->exec(
            'CREATE TABLE ktvs_admin_audit_log (
                user_id INTEGER,
                username TEXT,
                action_id INTEGER,
                object_id INTEGER,
                object_type_id INTEGER,
                action_details TEXT,
                added_date TEXT
            )'
        );
    }

    /**
     * @param array<int, array{0: string, 1: int}> $categories
     */
    private function insertCategories(\PDO $db, array $categories): void
    {
        $stmt = $db->prepare('INSERT INTO ktvs_categories (category_id, title, category_group_id) VALUES (:id, :title, :group)');
        foreach ($categories as $id => $category) {
            $stmt->execute([
                'id' => $id,
                'title' => $category[0],
                'group' => $category[1],
            ]);
        }
    }

    private function insertGroup(\PDO $db, int $id, string $title): void
    {
        $stmt = $db->prepare('INSERT INTO ktvs_categories_groups (category_group_id, title) VALUES (:id, :title)');
        $stmt->execute(['id' => $id, 'title' => $title]);
    }

    private function executeMerge(\PDO $db, string $sourceId, string $targetId): CommandTester
    {
        $tester = new CommandTester($this->createCategoryCommand($db));
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'merge', 'id' => $sourceId, 'values' => [$targetId]]);
        return $tester;
    }

    /**
     * @param list<string> $categoryIds
     */
    private function executeAssignGroup(\PDO $db, string $groupId, array $categoryIds, bool $dryRun = false): CommandTester
    {
        $tester = new CommandTester($this->createCategoryCommand($db));
        $input = ['action' => 'assign-group', 'id' => $groupId, 'values' => $categoryIds];
        if ($dryRun) {
            $input['--dry-run'] = true;
        }
        $tester->execute($input);
        return $tester;
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
