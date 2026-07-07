<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CategoryCommand::class)]
class CategoryCommandComprehensiveTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private CategoryCommand $command;
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
        $this->assertEquals('content:category', $this->command->getName());
        $this->assertStringContainsString('categor', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('category', $aliases);
        $this->assertContains('categories', $aliases);
        $this->assertContains('cat', $aliases);
    }

    public function testCommandHasAllOptions(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('title'));
        $this->assertTrue($definition->hasOption('description'));
        $this->assertTrue($definition->hasOption('parent'));
        $this->assertTrue($definition->hasOption('status'));
        $this->assertTrue($definition->hasOption('usage'));
        $this->assertTrue($definition->hasOption('field-filter'));
    }

    public function testHelpDocumentation(): void
    {
        $help = $this->command->getHelp();

        foreach (['list', 'tree', 'show', 'create', 'delete', 'update', 'enable', 'disable'] as $action) {
            $this->assertStringContainsString($action, $help);
        }

        $this->assertStringContainsString('EXAMPLES', $help);
        $this->assertStringContainsString('kvs category', $help);
    }

    public function testList(): void
    {
        $exitCode = $this->tester->execute(['action' => 'list']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Category id', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Video count', $output);
        $this->assertStringContainsString('Album count', $output);
        $this->assertStringContainsString('Status', $output);
        $this->assertStringContainsString('Action', $output);
        $this->assertStringContainsString('Drama', $output);
    }

    public function testTree(): void
    {
        $exitCode = $this->tester->execute(['action' => 'tree']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Category Tree', $output);
        $this->assertStringContainsString('Action (2 videos)', $output);
        $this->assertStringContainsString('Drama (1 videos)', $output);
        $this->assertStringContainsString('[Inactive]', $output);
    }

    public function testShowWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'show']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category ID or title is required', $output);
    }

    public function testShowNonExistent(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'show',
            'id' => '99999',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category not found: 99999', $output);
    }

    public function testCreateWithoutTitle(): void
    {
        $exitCode = $this->tester->execute(['action' => 'create']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category title is required', $output);
    }

    public function testCreateRejectsEmptyTitleLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            '--title' => '   ',
            '--description' => 'Should not be inserted',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category title is required', $output);
        $this->assertSame(
            0,
            (int) $this->db->query(
                'SELECT COUNT(*) FROM ' . TestHelper::table('categories') .
                " WHERE description = 'Should not be inserted'"
            )->fetchColumn()
        );
    }

    public function testCreateWithInvalidParent(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Test Category',
            '--parent' => '99999',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category group not found: 99999', $output);
    }

    public function testCreateGeneratesUniqueDirectoryLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Action!',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode, $output);
        $this->assertSame(
            'action2',
            $this->db->query('SELECT dir FROM ' . TestHelper::table('categories') . " WHERE title = 'Action!'")
                ->fetchColumn()
        );
    }

    public function testCreateAssignsCategoryGroupByTitleLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Grouped Category',
            '--group' => 'Genres',
        ]);

        $this->assertSame(0, $exitCode, $this->tester->getDisplay());
        $this->assertSame(
            2,
            $this->fetchInt(
                'SELECT category_group_id FROM ' . TestHelper::table('categories') .
                " WHERE title = 'Grouped Category'"
            )
        );
    }

    public function testCreateCreatesMissingCategoryGroupLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'New Group Category',
            '--group' => 'Fresh Group',
        ]);

        $this->assertSame(0, $exitCode, $this->tester->getDisplay());

        $row = $this->db->query(
            'SELECT c.category_group_id, g.title, g.dir, g.added_date
             FROM ' . TestHelper::table('categories') . ' c
             INNER JOIN ' . TestHelper::table('categories_groups') . ' g
                 ON g.category_group_id = c.category_group_id
             WHERE c.title = \'New Group Category\''
        )->fetch();

        $this->assertIsArray($row);
        $this->assertSame('Fresh Group', $row['title']);
        $this->assertSame('fresh-group', $row['dir']);
        $this->assertIsString($row['added_date']);
        $this->assertNotSame('', $row['added_date']);

        $this->assertSame(
            1,
            $this->fetchInt(
                'SELECT COUNT(*) FROM ' . TestHelper::table('admin_audit_log') .
                ' WHERE username = \'kvs-cli\' AND action_id = 100 AND object_id = ' .
                (int) $row['category_group_id'] . ' AND object_type_id = 7'
            )
        );
        $this->assertSame(
            1,
            $this->fetchInt(
                'SELECT COUNT(*) FROM ' . TestHelper::table('admin_audit_log') .
                ' WHERE username = \'kvs-cli\' AND action_id = 100 AND object_type_id = 6'
            )
        );
    }

    public function testCreateHonorsStatusOptionLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Inactive Category',
            '--status' => 'inactive',
        ]);

        $this->assertSame(0, $exitCode, $this->tester->getDisplay());
        $this->assertSame(
            0,
            $this->fetchInt(
                'SELECT status_id FROM ' . TestHelper::table('categories') .
                " WHERE title = 'Inactive Category'"
            )
        );
        $this->assertStringContainsString('Inactive', $this->tester->getDisplay());
    }

    public function testCreateLeavesLastContentDateAtKvsDefault(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Default Last Content Category',
        ]);

        $this->assertSame(0, $exitCode, $this->tester->getDisplay());
        $this->assertNull(
            $this->db->query(
                'SELECT last_content_date FROM ' . TestHelper::table('categories') .
                " WHERE title = 'Default Last Content Category'"
            )->fetchColumn()
        );
    }

    public function testCreateWritesKvsAdminAuditLogLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Audited Category',
        ]);

        $this->assertSame(0, $exitCode, $this->tester->getDisplay());

        $categoryId = (int) $this->db->query(
            'SELECT category_id FROM ' . TestHelper::table('categories') . " WHERE title = 'Audited Category'"
        )->fetchColumn();

        $this->assertSame(
            1,
            $this->fetchInt(
                'SELECT COUNT(*) FROM ' . TestHelper::table('admin_audit_log') .
                ' WHERE username = \'kvs-cli\' AND action_id = 100 AND object_id = ' . $categoryId .
                ' AND object_type_id = 6'
            )
        );
    }

    public function testUpdateWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'update']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category ID is required', $output);
    }

    public function testUpdateNonExistent(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '99999',
            '--title' => 'New Title',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category not found: 99999', $output);
    }

    public function testUpdatePreventsDuplicateTitlesLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '20',
            '--title' => 'Action',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category already exists: Action', $output);
        $this->assertSame(
            'Drama',
            $this->db->query('SELECT title FROM ' . TestHelper::table('categories') . ' WHERE category_id = 20')
                ->fetchColumn()
        );
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) FROM ' . TestHelper::table('categories') . " WHERE title = 'Action'")
            ->fetchColumn()
        );
    }

    public function testUpdateRejectsEmptyTitleLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '20',
            '--title' => '   ',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Category title is required', $output);
        $this->assertSame(
            'Drama',
            $this->db->query('SELECT title FROM ' . TestHelper::table('categories') . ' WHERE category_id = 20')
                ->fetchColumn()
        );
    }

    public function testUpdateRejectsInvalidStatusWithoutChangingCategory(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '10',
            '--status' => 'bogus',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Invalid status "bogus"', $output);
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT status_id FROM ' . TestHelper::table('categories') . ' WHERE category_id = 10')
                ->fetchColumn()
        );
    }

    public function testUpdateWithoutChanges(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '10',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('No changes specified', $output);
    }

    public function testUpdateSelfAsParent(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '10',
            '--parent' => '10',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category group not found: 10', $output);
    }

    public function testEnableWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'enable']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category ID is required', $output);
    }

    public function testEnableNonExistent(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'enable',
            'id' => '99999',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category not found: 99999', $output);
    }

    public function testDisableWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'disable']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category ID is required', $output);
    }

    public function testDisableNonExistent(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'disable',
            'id' => '99999',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category not found: 99999', $output);
    }

    public function testDeleteWithoutId(): void
    {
        $exitCode = $this->tester->execute(['action' => 'delete']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category ID is required', $output);
    }

    public function testDeleteNonExistent(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'delete',
            'id' => '99999',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category not found: 99999', $output);
    }

    public function testPreventsDuplicateTitles(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'Action',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category already exists: Action', $output);
    }

    public function testParentIdValidation(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'id' => 'New Category',
            '--group' => '99999',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category group not found: 99999', $output);
    }

    public function testCyclicParentPrevention(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '10',
            '--parent' => '10',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category group not found: 10', $output);
    }

    public function testListOutputHasRequiredColumns(): void
    {
        $this->tester->execute(['action' => 'list']);
        $output = $this->tester->getDisplay();

        $requiredColumns = ['Category id', 'Title', 'Video count', 'Album count', 'Status'];

        foreach ($requiredColumns as $column) {
            $this->assertStringContainsString($column, $output);
        }
    }

    public function testShowOutputHasDetails(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Category: Action', $output);
        $this->assertMatchesRegularExpression('/Group\W+Genres \(#2\)/', $output);
        $this->assertStringContainsString('High energy scenes', $output);
        $this->assertMatchesRegularExpression('/Videos\W+2/', $output);
        $this->assertMatchesRegularExpression('/Albums\W+1/', $output);
        $this->assertMatchesRegularExpression('/Posts\W+2/', $output);
        $this->assertMatchesRegularExpression('/Total Usage\W+5/', $output);
    }

    public function testTreeShowsHierarchy(): void
    {
        $this->tester->execute(['action' => 'tree']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Category Tree', $output);
        $this->assertStringContainsString('Action (2 videos)', $output);
    }

    public function testHandlesInvalidAction(): void
    {
        $exitCode = $this->tester->execute(['action' => 'invalid_action']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Unknown category action "invalid_action"', $output);
    }

    public function testHandlesNonNumericId(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'show',
            'id' => 'not_a_number',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Category not found: not_a_number', $output);
    }

    public function testCommandIntegrationWithHermeticDb(): void
    {
        $exitCode = $this->tester->execute(['action' => 'list']);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Total: 3 results', $this->tester->getDisplay());
    }

    public function testAllActionsAccessible(): void
    {
        $actions = ['list', 'tree', 'show', 'create', 'delete', 'update', 'enable', 'disable'];
        $help = $this->command->getHelp();

        foreach ($actions as $action) {
            $this->assertStringContainsString($action, strtolower($help));
        }
    }

    public function testStatusColorFormatting(): void
    {
        $this->tester->execute(['action' => 'list']);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Status', $output);
        $this->assertStringContainsString('Active', $output);
        $this->assertStringContainsString('Inactive', $output);
    }

    public function testAcceptsIdArgument(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasArgument('id'));
        $this->assertTrue($definition->hasArgument('action'));
    }

    public function testAcceptsAllUpdateOptions(): void
    {
        $definition = $this->command->getDefinition();

        foreach (['title', 'description', 'parent', 'status', 'usage', 'field-filter'] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }
    }

    public function testStatusOptionAcceptsValidValues(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '10',
            '--status' => 'active',
        ]);
        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Category updated successfully!', $output);
        $this->assertStringContainsString('Category: Action', $output);
    }

    public function testUpdateAssignsCategoryGroupByTitleLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '30',
            '--group' => 'Genres',
        ]);

        $this->assertSame(0, $exitCode, $this->tester->getDisplay());
        $this->assertSame(
            2,
            $this->fetchInt('SELECT category_group_id FROM ' . TestHelper::table('categories') . ' WHERE category_id = 30')
        );
        $this->assertSame(
            1,
            $this->fetchInt(
                'SELECT COUNT(*) FROM ' . TestHelper::table('admin_audit_log') .
                ' WHERE username = \'kvs-cli\' AND action_id = 150 AND object_id = 30' .
                ' AND object_type_id = 6 AND action_details = \'category_group_id\''
            )
        );
    }

    public function testUpdateCreatesMissingCategoryGroupLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '30',
            '--group' => 'Updated Fresh Group',
        ]);

        $this->assertSame(0, $exitCode, $this->tester->getDisplay());

        $row = $this->db->query(
            'SELECT c.category_group_id, g.title, g.dir, g.added_date
             FROM ' . TestHelper::table('categories') . ' c
             INNER JOIN ' . TestHelper::table('categories_groups') . ' g
                 ON g.category_group_id = c.category_group_id
             WHERE c.category_id = 30'
        )->fetch();

        $this->assertIsArray($row);
        $this->assertSame('Updated Fresh Group', $row['title']);
        $this->assertSame('updated-fresh-group', $row['dir']);
        $this->assertIsString($row['added_date']);
        $this->assertNotSame('', $row['added_date']);

        $this->assertSame(
            1,
            $this->fetchInt(
                'SELECT COUNT(*) FROM ' . TestHelper::table('admin_audit_log') .
                ' WHERE username = \'kvs-cli\' AND action_id = 100 AND object_id = ' .
                (int) $row['category_group_id'] . ' AND object_type_id = 7'
            )
        );
        $this->assertSame(
            1,
            $this->fetchInt(
                'SELECT COUNT(*) FROM ' . TestHelper::table('admin_audit_log') .
                ' WHERE username = \'kvs-cli\' AND action_id = 150 AND object_id = 30' .
                ' AND object_type_id = 6 AND action_details = \'category_group_id\''
            )
        );
    }

    public function testUpdateWritesKvsAdminAuditLogLikeKvsAdmin(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'update',
            'id' => '10',
            '--description' => 'Updated description',
        ]);

        $this->assertSame(0, $exitCode, $this->tester->getDisplay());
        $this->assertSame(
            1,
            $this->fetchInt(
                'SELECT COUNT(*) FROM ' . TestHelper::table('admin_audit_log') .
                ' WHERE username = \'kvs-cli\' AND action_id = 150 AND object_id = 10' .
                ' AND object_type_id = 6 AND action_details = \'description\''
            )
        );
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories') . ' (' .
            'category_id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, dir TEXT, description TEXT, synonyms TEXT, category_group_id INTEGER, ' .
            'status_id INTEGER, added_date TEXT, last_content_date TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_groups') . ' (' .
            'category_group_id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, dir TEXT, added_date TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_audit_log') . ' (' .
            'user_id INTEGER, username TEXT, action_id INTEGER, object_id INTEGER, object_type_id INTEGER, ' .
            'action_details TEXT NOT NULL, added_date TEXT)'
        );

        foreach ($this->relationTables() as $suffix => $objectColumn) {
            $db->exec(
                'CREATE TABLE ' . TestHelper::table('categories_' . $suffix) . ' (' .
                'category_id INTEGER, ' . $objectColumn . ' INTEGER)'
            );
        }

        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories_groups') .
            " (category_group_id, title, dir, added_date) VALUES (2, 'Genres', 'genres', '2026-05-25 09:00:00')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories') .
            ' (category_id, title, dir, description, category_group_id, status_id, added_date, last_content_date) VALUES ' .
            "(10, 'Action', 'action', 'High energy scenes', 2, 1, '2026-05-25 10:00:00', '2026-05-25 11:00:00'), " .
            "(20, 'Drama', 'drama', '', 0, 0, '2026-05-26 10:00:00', '2026-05-26 11:00:00'), " .
            "(30, 'Unused Category', 'unused-category', '', 0, 1, '2026-05-26 11:00:00', '2026-05-26 12:00:00')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories_videos') .
            ' (category_id, video_id) VALUES (10, 100), (10, 101), (20, 200)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories_albums') .
            ' (category_id, album_id) VALUES (10, 300)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories_posts') .
            ' (category_id, post_id) VALUES (10, 400), (10, 401)'
        );

        return $db;
    }

    private function fetchInt(string $sql): int
    {
        return (int) $this->db->query($sql)->fetchColumn();
    }

    /**
     * @return array<string, string>
     */
    private function relationTables(): array
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

    private function createCommand(PDO $db): CategoryCommand
    {
        return new class ($this->config, $db) extends CategoryCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:category');
                $this->setDescription('Manage KVS categories');
                $this->setAliases(['category', 'categories', 'cat']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
