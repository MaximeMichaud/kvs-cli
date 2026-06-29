<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Command\Content\TagCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RelationUsageFilterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTestKvsInstallation();
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->tempDir);
    }

    public function testCategoryListFiltersBySearchAndGroup(): void
    {
        $db = $this->createDatabase();
        $this->createCategoryTables($db);
        $db->exec(
            "INSERT INTO ktvs_categories (category_id, title, category_group_id, status_id) VALUES " .
            "(1, 'Codex Canada', 3, 1), " .
            "(2, 'Codex Norway', 4, 1), " .
            "(3, 'Other Canada', 3, 1)"
        );

        $tester = new CommandTester($this->createCategoryCommand($db));
        $tester->execute([
            'action' => 'list',
            '--search' => 'Codex',
            '--group' => '3',
            '--format' => 'json',
            '--fields' => 'category_id,title,category_group_id',
            '--limit' => '10',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $this->columnAsInts($rows, 'category_id'));
        $this->assertSame('Codex Canada', $rows[0]['title']);
    }

    public function testCategoryListUnusedUsesKvsAdminUsageTotals(): void
    {
        $db = $this->createDatabase();
        $this->createCategoryTables($db);
        $db->exec(
            "INSERT INTO ktvs_categories (category_id, title, category_group_id, status_id, total_models) VALUES " .
            "(1, 'Unused', 0, 1, 0), " .
            "(2, 'Video used', 0, 1, 0), " .
            "(3, 'Model used', 0, 1, 1)"
        );
        $db->exec('INSERT INTO ktvs_categories_videos (category_id) VALUES (2)');

        $tester = new CommandTester($this->createCategoryCommand($db));
        $tester->execute([
            'action' => 'list',
            '--unused' => true,
            '--format' => 'json',
            '--fields' => 'category_id,total_usage',
            '--limit' => '10',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $this->columnAsInts($rows, 'category_id'));
        $this->assertSame(0, (int) $rows[0]['total_usage']);
    }

    public function testTagListUnusedIsSqliteCompatibleAndUsesKvsAdminUsageTotals(): void
    {
        $db = $this->createDatabase();
        $this->createTagTables($db);
        $db->exec(
            "INSERT INTO ktvs_tags (tag_id, tag, tag_dir, status_id) VALUES " .
            "(1, 'Unused', 'unused', 1), " .
            "(2, 'Post used', 'post-used', 1)"
        );
        $db->exec('INSERT INTO ktvs_tags_posts (tag_id) VALUES (2)');

        $tester = new CommandTester($this->createTagCommand($db));
        $tester->execute([
            'action' => 'list',
            '--unused' => true,
            '--format' => 'json',
            '--fields' => 'tag_id,total_usage',
            '--limit' => '10',
        ]);

        $rows = $this->decodeJsonRows($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $this->columnAsInts($rows, 'tag_id'));
        $this->assertSame(0, (int) $rows[0]['total_usage']);
    }

    private function createDatabase(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $db;
    }

    private function createCategoryTables(\PDO $db): void
    {
        $db->exec(
            'CREATE TABLE ktvs_categories ' .
            '(category_id INTEGER, title TEXT, dir TEXT, description TEXT, synonyms TEXT, ' .
            'category_group_id INTEGER, status_id INTEGER, total_content_sources INTEGER, ' .
            'total_playlists INTEGER, total_models INTEGER, total_dvds INTEGER, total_dvd_groups INTEGER)'
        );
        foreach ($this->relationSuffixes() as $suffix) {
            $db->exec("CREATE TABLE ktvs_categories_{$suffix} (category_id INTEGER)");
        }
    }

    private function createTagTables(\PDO $db): void
    {
        $db->exec(
            'CREATE TABLE ktvs_tags (tag_id INTEGER, tag TEXT, tag_dir TEXT, status_id INTEGER, ' .
            'total_content_sources INTEGER, total_playlists INTEGER, total_models INTEGER, ' .
            'total_dvds INTEGER, total_dvd_groups INTEGER)'
        );
        foreach ($this->relationSuffixes() as $suffix) {
            $db->exec("CREATE TABLE ktvs_tags_{$suffix} (tag_id INTEGER)");
        }
    }

    /**
     * @return list<string>
     */
    private function relationSuffixes(): array
    {
        return ['videos', 'albums', 'posts', 'playlists', 'content_sources', 'models', 'dvds', 'dvds_groups'];
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

    private function createConfig(): Configuration
    {
        return TestHelper::createTestConfiguration($this->tempDir);
    }
}
