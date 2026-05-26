<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\CategoryCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class CategoryCommandTest extends TestCase
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

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testCategoryListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 2
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Category id', $output);
        $this->assertStringContainsString('Action', $output);
        $this->assertStringContainsString('Drama', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCategoryListWithStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 1,
            '--format' => 'json',
            '--fields' => 'category_id,title,video_count,album_count,total_usage,status',
            '--limit' => 5,
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(2, $rows);
        $this->assertSame(['Action', 'Unused Category'], array_column($rows, 'title'));
        $this->assertSame('Active', $rows[0]['status']);
        $this->assertSame(2, (int) $rows[0]['video_count']);
        $this->assertSame(1, (int) $rows[0]['album_count']);
        $this->assertSame(3, (int) $rows[0]['total_usage']);
        $this->assertSame(0, (int) $rows[1]['total_usage']);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCategoryListFormats(): void
    {
        // Test JSON format
        $testerJson = new CommandTester($this->command);
        $testerJson->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json'
        ]);

        $output = $testerJson->getDisplay();
        $this->assertJson($output);
        $jsonRows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $jsonRows);
        $this->assertSame(10, (int) $jsonRows[0]['category_id']);
        $this->assertSame('Action', $jsonRows[0]['title']);
        $this->assertEquals(0, $testerJson->getStatusCode());

        // Test CSV format
        $testerCsv = new CommandTester($this->command);
        ob_start();
        $testerCsv->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'csv'
        ]);
        $csvOutput = ob_get_clean();

        $this->assertStringContainsString('category_id', $csvOutput);
        $this->assertStringContainsString('Action', $csvOutput);
        $this->assertEquals(0, $testerCsv->getStatusCode());

        // Test count format
        $testerCount = new CommandTester($this->command);
        $testerCount->execute([
            'action' => 'list',
            '--format' => 'count'
        ]);

        $output = trim($testerCount->getDisplay());
        $this->assertSame('3', $output);
        $this->assertEquals(0, $testerCount->getStatusCode());
    }

    public function testCategoryShow(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '10'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Category: Action', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertStringContainsString('Action', $output);
        $this->assertMatchesRegularExpression('/Videos\W+2/', $output);
        $this->assertMatchesRegularExpression('/Albums\W+1/', $output);
        $this->assertStringContainsString('High energy scenes', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCategoryCommandMetadata(): void
    {
        $this->assertEquals('content:category', $this->command->getName());
        $this->assertStringContainsString('manage', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('category', $aliases);
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories') . ' (' .
            'category_id INTEGER, title TEXT, description TEXT, category_group_id INTEGER, ' .
            'status_id INTEGER, added_date TEXT)'
        );

        foreach ($this->relationTables() as $suffix => $objectColumn) {
            $db->exec(
                'CREATE TABLE ' . TestHelper::table('categories_' . $suffix) . ' (' .
                'category_id INTEGER, ' . $objectColumn . ' INTEGER)'
            );
        }

        $db->exec(
            "INSERT INTO " . TestHelper::table('categories') .
            " (category_id, title, description, category_group_id, status_id, added_date) VALUES " .
            "(10, 'Action', 'High energy scenes', 2, 1, '2026-05-25 10:00:00'), " .
            "(20, 'Drama', '', 0, 0, '2026-05-26 10:00:00'), " .
            "(30, 'Unused Category', '', 0, 1, '2026-05-26 11:00:00')"
        );
        $db->exec("INSERT INTO " . TestHelper::table('categories_videos') . " VALUES (10, 100), (10, 101), (20, 200)");
        $db->exec("INSERT INTO " . TestHelper::table('categories_albums') . " VALUES (10, 300)");

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
