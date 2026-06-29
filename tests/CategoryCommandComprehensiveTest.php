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
        $this->assertStringContainsString('Category ID is required', $output);
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

        foreach (['title', 'description', 'parent', 'status'] as $option) {
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
            'category_group_id INTEGER, title TEXT)'
        );

        foreach ($this->relationTables() as $suffix => $objectColumn) {
            $db->exec(
                'CREATE TABLE ' . TestHelper::table('categories_' . $suffix) . ' (' .
                'category_id INTEGER, ' . $objectColumn . ' INTEGER)'
            );
        }

        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories_groups') .
            " (category_group_id, title) VALUES (2, 'Genres')"
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
