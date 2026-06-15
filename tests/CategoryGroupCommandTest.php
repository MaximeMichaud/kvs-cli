<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\CategoryGroupCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CategoryGroupCommand::class)]
class CategoryGroupCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private CategoryGroupCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->db = $this->createDatabase();
        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->command = $this->createCommand($this->db);
        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testCommandMetadata(): void
    {
        $command = new CategoryGroupCommand($this->config);

        $this->assertSame('content:category-group', $command->getName());
        $this->assertStringContainsString('group', strtolower($command->getDescription()));

        $aliases = $command->getAliases();
        $this->assertContains('category-group', $aliases);
        $this->assertContains('cat-group', $aliases);
        $this->assertContains('cgroup', $aliases);
    }

    public function testCommandHasOptions(): void
    {
        $definition = $this->command->getDefinition();

        foreach (['title', 'description', 'status', 'external-id', 'dir', 'sort', 'format'] as $option) {
            $this->assertTrue($definition->hasOption($option), "missing option: $option");
        }
    }

    public function testHelpDocumentation(): void
    {
        $help = $this->command->getHelp();

        foreach (['list', 'show', 'create', 'delete', 'update', 'enable', 'disable'] as $action) {
            $this->assertStringContainsString($action, $help);
        }
        $this->assertStringContainsString('EXAMPLES', $help);
    }

    public function testInvalidAction(): void
    {
        $exitCode = $this->tester->execute(['action' => 'frobnicate']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown category-group action "frobnicate"', $this->tester->getDisplay());
    }

    // ------------------------------------------------------------------ list

    public function testListShowsGroupsWithCategoryCount(): void
    {
        $exitCode = $this->tester->execute(['action' => 'list']);
        $output = $this->tester->getDisplay();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Genres', $output);
        $this->assertStringContainsString('Studios', $output);
        $this->assertStringContainsString('Category count', $output);
    }

    public function testListCountFormat(): void
    {
        $exitCode = $this->tester->execute(['action' => 'list', '--format' => 'count']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('2', trim($this->tester->getDisplay()));
    }

    public function testListStatusFilterActive(): void
    {
        $exitCode = $this->tester->execute(['action' => 'list', '--status' => 'active']);
        $output = $this->tester->getDisplay();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Genres', $output);
        $this->assertStringNotContainsString('Studios', $output);
    }

    public function testListSearchFilter(): void
    {
        $exitCode = $this->tester->execute(['action' => 'list', '--search' => 'Studio']);
        $output = $this->tester->getDisplay();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Studios', $output);
        $this->assertStringNotContainsString('Genres', $output);
    }

    // ------------------------------------------------------------------ show

    public function testShowWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'show']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group ID is required', $this->tester->getDisplay());
    }

    public function testShowNonExistent(): void
    {
        $exitCode = $this->tester->execute(['action' => 'show', 'id' => '99999']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group not found: 99999', $this->tester->getDisplay());
    }

    public function testShowDetails(): void
    {
        $exitCode = $this->tester->execute(['action' => 'show', 'id' => '1']);
        $output = $this->tester->getDisplay();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Category group: Genres', $output);
        $this->assertStringContainsString('Movie genres', $output);
        $this->assertMatchesRegularExpression('/Categories\W+2/', $output);
    }

    // ---------------------------------------------------------------- create

    public function testCreateWithoutTitle(): void
    {
        $exitCode = $this->tester->execute(['action' => 'create']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group title is required', $this->tester->getDisplay());
    }

    public function testCreateDuplicateTitle(): void
    {
        $exitCode = $this->tester->execute(['action' => 'create', 'id' => 'Genres']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group already exists: Genres', $this->tester->getDisplay());
    }

    public function testCreateDuplicateExternalId(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Fresh Group',
            '--external-id' => 'studios-ext',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('external ID already exists: studios-ext', $this->tester->getDisplay());
    }

    public function testCreateInvalidSort(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Fresh Group',
            '--sort' => 'abc',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid value for --sort', $this->tester->getDisplay());
    }

    public function testCreateSuccess(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Comedy Stuff',
            '--description' => 'Funny things',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Category group created successfully', $output);

        $row = $this->fetchGroupByTitle('Comedy Stuff');
        $this->assertNotNull($row);
        $this->assertSame('comedy-stuff', $row['dir']);
        $this->assertSame('Funny things', $row['description']);
        $this->assertSame(1, (int) $row['status_id']);
    }

    public function testCreateInactiveWithExplicitFields(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            '--title' => 'Networks',
            '--dir' => 'tv-networks',
            '--external-id' => 'net-1',
            '--sort' => '7',
            '--status' => 'inactive',
        ]);

        $this->assertSame(0, $exitCode);

        $row = $this->fetchGroupByTitle('Networks');
        $this->assertNotNull($row);
        $this->assertSame('tv-networks', $row['dir']);
        $this->assertSame('net-1', $row['external_id']);
        $this->assertSame(7, (int) $row['sort_id']);
        $this->assertSame(0, (int) $row['status_id']);
    }

    // ---------------------------------------------------------------- update

    public function testUpdateWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'update']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group ID is required', $this->tester->getDisplay());
    }

    public function testUpdateNonExistent(): void
    {
        $exitCode = $this->tester->execute(['action' => 'update', 'id' => '99999', '--title' => 'X']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group not found: 99999', $this->tester->getDisplay());
    }

    public function testUpdateNoChanges(): void
    {
        $exitCode = $this->tester->execute(['action' => 'update', 'id' => '1']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No changes specified', $this->tester->getDisplay());
    }

    public function testUpdateRename(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '1',
            '--title' => 'Movie Genres',
            '--sort' => '3',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Category group updated successfully', $output);
        $this->assertStringContainsString('Category group: Movie Genres', $output);

        $row = $this->fetchGroupByTitle('Movie Genres');
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row['sort_id']);
    }

    public function testUpdateDuplicateTitleRejected(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '2',
            '--title' => 'Genres',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group already exists: Genres', $this->tester->getDisplay());
    }

    // ---------------------------------------------------------------- delete

    public function testDeleteWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'delete']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group ID is required', $this->tester->getDisplay());
    }

    public function testDeleteNonNumericId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'delete', 'id' => 'abc']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group ID must be numeric', $this->tester->getDisplay());
    }

    public function testDeleteNonExistent(): void
    {
        $exitCode = $this->tester->execute(['action' => 'delete', 'id' => '99999']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group not found: 99999', $this->tester->getDisplay());
    }

    public function testDeleteEmptyGroup(): void
    {
        $exitCode = $this->tester->execute(['action' => 'delete', 'id' => '2']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("Category group 'Studios' deleted successfully", $this->tester->getDisplay());
        $this->assertNull($this->fetchGroupByTitle('Studios'));
    }

    public function testDeleteGroupWithCategoriesCancelledWhenNonInteractive(): void
    {
        $exitCode = $this->tester->execute(
            ['action' => 'delete', 'id' => '1'],
            ['interactive' => false]
        );

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('cancelled', $this->tester->getDisplay());
        // Group and its categories are untouched.
        $this->assertNotNull($this->fetchGroupByTitle('Genres'));
        $this->assertSame(2, $this->countCategoriesInGroup(1));
    }

    public function testDeleteGroupWithCategoriesDetachesThemWhenConfirmed(): void
    {
        $this->tester->setInputs(['yes']);
        $exitCode = $this->tester->execute(['action' => 'delete', 'id' => '1']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("Category group 'Genres' deleted successfully", $this->tester->getDisplay());
        $this->assertNull($this->fetchGroupByTitle('Genres'));
        // Categories are detached (category_group_id = 0), not deleted.
        $this->assertSame(0, $this->countCategoriesInGroup(1));
        $this->assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM ' . TestHelper::table('categories'))->fetchColumn());
    }

    // -------------------------------------------------------- enable/disable

    public function testEnableWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'enable']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group ID is required', $this->tester->getDisplay());
    }

    public function testDisableNonExistent(): void
    {
        $exitCode = $this->tester->execute(['action' => 'disable', 'id' => '99999']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group not found: 99999', $this->tester->getDisplay());
    }

    public function testDisableSuccess(): void
    {
        $exitCode = $this->tester->execute(['action' => 'disable', 'id' => '1']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('disabled successfully', $this->tester->getDisplay());
        $this->assertSame(0, (int) $this->db->query(
            'SELECT status_id FROM ' . TestHelper::table('categories_groups') . ' WHERE category_group_id = 1'
        )->fetchColumn());
    }

    public function testEnableAlreadyActive(): void
    {
        $exitCode = $this->tester->execute(['action' => 'enable', 'id' => '1']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('already active', $this->tester->getDisplay());
    }

    // --------------------------------------------------------- malformed IDs

    public function testShowRejectsNonNumericId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'show', 'id' => '1abc']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group ID must be numeric', $this->tester->getDisplay());
    }

    public function testUpdateRejectsNonNumericId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'update', 'id' => '1abc', '--title' => 'Hijacked']);
        $output = $this->tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group ID must be numeric', $output);
        // "1abc" must NOT be coerced to row 1 on MySQL: group 1 stays "Genres".
        $this->assertNotNull($this->fetchGroupByTitle('Genres'));
        $this->assertNull($this->fetchGroupByTitle('Hijacked'));
    }

    public function testEnableRejectsNonNumericId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'enable', 'id' => '2abc']);
        $output = $this->tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group ID must be numeric', $output);
        // Disabled group 2 must not be enabled via coercion of "2abc" -> 2.
        $this->assertSame(0, (int) $this->db->query(
            'SELECT status_id FROM ' . TestHelper::table('categories_groups') . ' WHERE category_group_id = 2'
        )->fetchColumn());
    }

    public function testDisableRejectsNonNumericId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'disable', 'id' => '1abc']);
        $output = $this->tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category group ID must be numeric', $output);
        // Active group 1 must not be disabled via coercion of "1abc" -> 1.
        $this->assertSame(1, (int) $this->db->query(
            'SELECT status_id FROM ' . TestHelper::table('categories_groups') . ' WHERE category_group_id = 1'
        )->fetchColumn());
    }

    public function testDeleteContinuesWhenFileCleanupFails(): void
    {
        $command = new class ($this->config, $this->db) extends CategoryGroupCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:category-group');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }

            protected function deleteGroupFiles(string $groupId): void
            {
                throw new \RuntimeException('simulated cleanup failure');
            }
        };
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['action' => 'delete', 'id' => '2']);
        $output = $tester->getDisplay();

        // DB deletion already committed: a post-commit cleanup failure must not flip
        // the result to failure (that would make retries unsafe).
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('post-delete cleanup failed', $output);
        $this->assertStringContainsString("Category group 'Studios' deleted successfully", $output);
        $this->assertNull($this->fetchGroupByTitle('Studios'));
    }

    // ------------------------------------------------------------ status input

    public function testCreateRejectsInvalidStatus(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Typoed',
            '--status' => 'actve',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid status "actve"', $output);
        // A typo must not silently create (or disable) anything.
        $this->assertNull($this->fetchGroupByTitle('Typoed'));
    }

    public function testUpdateRejectsInvalidStatus(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '1',
            '--status' => 'enabled',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid status "enabled"', $output);
        // Active group 1 must not be silently disabled by an unrecognised value.
        $this->assertSame(1, (int) $this->db->query(
            'SELECT status_id FROM ' . TestHelper::table('categories_groups') . ' WHERE category_group_id = 1'
        )->fetchColumn());
    }

    public function testDeleteAbortsWhenCategoriesAttachedDuringOperation(): void
    {
        // Simulate a TOCTOU race: the attached set differs between the operator's review
        // and the locked re-read (here empty at review, one category present under lock).
        $command = new class ($this->config, $this->db) extends CategoryGroupCommand {
            private int $setCalls = 0;

            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:category-group');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }

            protected function categoryIdsInGroup(PDO $db, int $groupId): array
            {
                $this->setCalls++;

                return $this->setCalls === 1 ? [] : [42];
            }
        };
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['action' => 'delete', 'id' => '2']);
        $output = $tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('set of categories in this group changed', $output);
        // The transaction must roll back, leaving the group intact.
        $this->assertNotNull($this->fetchGroupByTitle('Studios'));
    }

    // ---------------------------------------------------------------- dir slug

    public function testCreateFallsBackToDefaultDirForEmptySlug(): void
    {
        // A title with no ASCII alphanumerics slugifies to an empty string.
        $exitCode = $this->tester->execute(['action' => 'create', 'id' => '日本語']);

        $this->assertSame(0, $exitCode);
        $row = $this->fetchGroupByTitle('日本語');
        $this->assertNotNull($row);
        $this->assertSame('group', $row['dir']);
    }

    public function testCreateDedupesGeneratedDir(): void
    {
        // "Genres!" is a distinct title but slugifies to "genres", already used by group 1.
        $exitCode = $this->tester->execute(['action' => 'create', 'id' => 'Genres!']);

        $this->assertSame(0, $exitCode);
        $row = $this->fetchGroupByTitle('Genres!');
        $this->assertNotNull($row);
        $this->assertSame('genres-2', $row['dir']);
    }

    public function testCreateRejectsExplicitDirWithoutSlugChars(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Valid Title',
            '--dir' => '!!!',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('usable slug characters', $output);
        $this->assertNull($this->fetchGroupByTitle('Valid Title'));
    }

    public function testUpdateDedupesDir(): void
    {
        // Group 2's requested dir collides with group 1's existing "genres".
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '2',
            '--dir' => 'Genres',
        ]);

        $this->assertSame(0, $exitCode);
        $row = $this->fetchGroupByTitle('Studios');
        $this->assertNotNull($row);
        $this->assertSame('genres-2', $row['dir']);
    }

    public function testUpdateCanClearExternalId(): void
    {
        // Group 2 (Studios) is seeded with external_id = 'studios-ext'. An explicitly
        // empty --external-id= must clear it, not be treated as "no change".
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '2',
            '--external-id' => '',
        ]);

        $this->assertSame(0, $exitCode);
        $row = $this->fetchGroupByTitle('Studios');
        $this->assertNotNull($row);
        $this->assertSame('', $row['external_id']);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGroupByTitle(string $title): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . TestHelper::table('categories_groups') . ' WHERE title = :title'
        );
        $stmt->execute(['title' => $title]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function countCategoriesInGroup(int $groupId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . TestHelper::table('categories') . ' WHERE category_group_id = :id'
        );
        $stmt->execute(['id' => $groupId]);

        return (int) $stmt->fetchColumn();
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_groups') . ' (' .
            'category_group_id INTEGER PRIMARY KEY, title TEXT, dir TEXT, description TEXT, ' .
            'status_id INTEGER, external_id TEXT, sort_id INTEGER, added_date TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories') . ' (' .
            'category_id INTEGER PRIMARY KEY, title TEXT, category_group_id INTEGER, status_id INTEGER)'
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories_groups') .
            ' (category_group_id, title, dir, description, status_id, external_id, sort_id, added_date) VALUES ' .
            "(1, 'Genres', 'genres', 'Movie genres', 1, '', 0, '2026-01-01 10:00:00'), " .
            "(2, 'Studios', 'studios', '', 0, 'studios-ext', 5, '2026-01-02 10:00:00')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories') .
            ' (category_id, title, category_group_id, status_id) VALUES ' .
            "(10, 'Action', 1, 1), (20, 'Drama', 1, 1), (30, 'Solo', 0, 1)"
        );

        return $db;
    }

    private function createCommand(PDO $db): CategoryGroupCommand
    {
        return new class ($this->config, $db) extends CategoryGroupCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:category-group');
                $this->setDescription('Manage KVS category groups');
                $this->setAliases(['category-group', 'category-groups', 'cat-group', 'cgroup']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
