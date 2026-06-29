<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\ModelCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(ModelCommand::class)]
class ModelCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private ModelCommand $command;
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

    public function testListModelsDefault(): void
    {
        $this->tester->execute(['action' => 'list']);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Model', $this->tester->getDisplay());
        $this->assertStringContainsString('Disabled Model', $this->tester->getDisplay());
    }

    public function testListModelsWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 5
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Other Performer', $this->tester->getDisplay());
    }

    public function testListModelsActiveStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--format' => 'json',
            '--fields' => 'model_id,title,status'
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(2, $rows);
        $this->assertSame([30, 10], array_map(static fn (array $row): int => (int) $row['model_id'], $rows));
        $this->assertSame('Active', $rows[0]['status']);
    }

    public function testListModelsCountFormatIgnoresPaginationButAppliesFilters(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'count',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('3', trim($this->tester->getDisplay()));

        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--format' => 'count',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('2', trim($this->tester->getDisplay()));
    }

    public function testListModelsInactiveStatusAlias(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'inactive',
            '--format' => 'json',
            '--fields' => 'model_id,title,status'
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(20, (int) $rows[0]['model_id']);
        $this->assertSame('Disabled Model', $rows[0]['title']);
        $this->assertSame('Disabled', $rows[0]['status']);
    }

    public function testHelpDocumentsInactiveStatusAlias(): void
    {
        $statusOption = $this->command->getDefinition()->getOption('status');

        $this->assertStringContainsString('active|disabled|inactive', $statusOption->getDescription());
        $this->assertStringContainsString('kvs model list --status=inactive', $this->command->getHelp());
    }

    public function testListModelsSearch(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'test'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Model', $output);
        $this->assertStringNotContainsString('Disabled Model', $output);
    }

    public function testListModelsUsesKvsAdminRelationContentCounts(): void
    {
        $this->db->exec(
            'UPDATE ' . TestHelper::table('models') .
            ' SET total_videos = 9, total_albums = 8 WHERE model_id = 30'
        );

        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Test Model',
            '--fields' => 'model_id,videos,albums',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame(30, (int) $rows[0]['model_id']);
        $this->assertSame(2, (int) $rows[0]['videos']);
        $this->assertSame(1, (int) $rows[0]['albums']);
    }

    public function testListModelsExposesKvsAdminCountFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Test Model',
            '--fields' => 'model_id,videos_amount,albums_amount,posts_amount,other_amount,all_amount,comments_amount,subscribers_amount',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['model_id']);
        $this->assertSame(2, (int) $rows[0]['videos_amount']);
        $this->assertSame(1, (int) $rows[0]['albums_amount']);
        $this->assertSame(2, (int) $rows[0]['posts_amount']);
        $this->assertSame(7, (int) $rows[0]['other_amount']);
        $this->assertSame(12, (int) $rows[0]['all_amount']);
        $this->assertSame(2, (int) $rows[0]['comments_amount']);
        $this->assertSame(6, (int) $rows[0]['subscribers_amount']);
    }

    public function testListModelsExposesKvsAdminGroupField(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Test Model',
            '--fields' => 'model_id,title,model_group',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['model_id']);
        $this->assertSame('Test Model', $rows[0]['title']);
        $this->assertSame('Featured Models', $rows[0]['model_group']);
    }

    public function testListModelsExposesKvsAdminLocationAndDeathDateFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Test Model',
            '--fields' => 'model_id,city,state,death_date',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['model_id']);
        $this->assertSame('Montreal', $rows[0]['city']);
        $this->assertSame('Quebec', $rows[0]['state']);
        $this->assertSame('2026-01-01', $rows[0]['death_date']);
    }

    public function testListModelsExposesKvsAdminRawScalarFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Test Model',
            '--fields' => 'model_id,dir,description,alias,access_level_id,gender_id,hair_id,eye_color_id,gallery_url,added_date,sort_id,rank',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['model_id']);
        $this->assertSame('test-model', $rows[0]['dir']);
        $this->assertSame('Main model profile', $rows[0]['description']);
        $this->assertSame('Test Alias', $rows[0]['alias']);
        $this->assertSame(2, (int) $rows[0]['access_level_id']);
        $this->assertSame(1, (int) $rows[0]['gender_id']);
        $this->assertSame(2, (int) $rows[0]['hair_id']);
        $this->assertSame(3, (int) $rows[0]['eye_color_id']);
        $this->assertSame('https://example.test/model-gallery', $rows[0]['gallery_url']);
        $this->assertSame('2026-05-25 10:00:00', $rows[0]['added_date']);
        $this->assertSame(11, (int) $rows[0]['sort_id']);
        $this->assertSame('#7', $rows[0]['rank']);
    }

    #[DataProvider('provideOutputFormats')]
    public function testListModelsFormats(string $format): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => $format,
            '--limit' => 3
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        if ($format === 'json') {
            $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(3, $rows);
            $this->assertSame(30, (int) $rows[0]['model_id']);
            $this->assertSame('Test Model', $rows[0]['title']);
            return;
        }

        $this->assertStringContainsString('Test Model', $this->tester->getDisplay());
    }

    public static function provideOutputFormats(): array
    {
        return [
            'table format' => ['table'],
            'json format' => ['json'],
        ];
    }

    public function testShowModel(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Model: Test Model', $output);
        $this->assertStringContainsString('Model ID', $output);
        $this->assertMatchesRegularExpression('/Videos\W+2/', $output);
        $this->assertMatchesRegularExpression('/Albums\W+1/', $output);
        $this->assertMatchesRegularExpression('/Views\W+100/', $output);
        $this->assertStringContainsString('4.0/5 (10 votes)', $output);
        $this->assertStringContainsString('#7', $output);
        $this->assertStringContainsString('Canada', $output);
        $this->assertStringContainsString('Main model profile', $output);
    }

    public function testShowModelNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Model not found: 999999999', $output);
    }

    public function testShowModelMissingId(): void
    {
        $this->tester->execute([
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Model ID is required', $output);
    }

    public function testStats(): void
    {
        $this->tester->execute(['action' => 'stats']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Model Statistics', $output);
        $this->assertMatchesRegularExpression('/Total Models\W+3/', $output);
        $this->assertMatchesRegularExpression('/Active\W+2/', $output);
        $this->assertMatchesRegularExpression('/Disabled\W+1/', $output);
        $this->assertMatchesRegularExpression('/Models with Videos\W+3/', $output);
        $this->assertMatchesRegularExpression('/Total Video Relations\W+4/', $output);
    }

    public function testDefaultActionIsList(): void
    {
        $this->tester->execute([]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Model', $this->tester->getDisplay());
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('models') . ' (' .
            'model_id INTEGER, title TEXT, dir TEXT, status_id INTEGER, model_viewed INTEGER, access_level_id INTEGER, ' .
            'country TEXT, gender_id INTEGER, hair_id INTEGER, eye_color_id INTEGER, birth_date TEXT, age INTEGER, ' .
            'measurements TEXT, height TEXT, weight TEXT, rank INTEGER, rating INTEGER, rating_amount INTEGER, ' .
            'description TEXT, alias TEXT, gallery_url TEXT, added_date TEXT, sort_id INTEGER, total_videos INTEGER, total_albums INTEGER, ' .
            'total_dvds INTEGER, total_dvd_groups INTEGER, subscribers_count INTEGER, city TEXT, state TEXT, ' .
            'death_date TEXT, model_group_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('models_groups') . ' (' .
            'model_group_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('models_videos') . ' (' .
            'model_id INTEGER, video_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('models_albums') . ' (' .
            'model_id INTEGER, album_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('models_posts') . ' (' .
            'model_id INTEGER, post_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('comments') . ' (' .
            'object_type_id INTEGER, object_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('list_countries') . ' (' .
            'country_code TEXT, language_code TEXT, title TEXT)'
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('models') .
            ' (model_id, title, dir, status_id, model_viewed, access_level_id, country, gender_id, hair_id, eye_color_id, ' .
            'birth_date, age, measurements, height, weight, rank, rating, rating_amount, description, alias, gallery_url, ' .
            'added_date, sort_id, total_videos, total_albums, total_dvds, ' .
            'total_dvd_groups, subscribers_count, city, state, death_date, model_group_id) VALUES ' .
            "(30, 'Test Model', 'test-model', 1, 100, 2, 'CA', 1, 2, 3, '2000-01-01', 26, '34-24-34', '170 cm', " .
            "'55 kg', 7, 40, 10, 'Main model profile', 'Test Alias', 'https://example.test/model-gallery', " .
            "'2026-05-25 10:00:00', 11, 2, 1, 3, 4, 6, 'Montreal', 'Quebec', '2026-01-01', 3), " .
            "(20, 'Disabled Model', 'disabled-model', 0, 8, 0, '', 0, 0, 0, '', 0, '', '', '', 0, 0, 0, '', '', '', " .
            "'2026-05-26 10:00:00', 12, 1, 1, 0, 0, 0, '', '', '0000-00-00', 4), " .
            "(10, 'Other Performer', 'other-performer', 1, 25, 0, 'US', 0, 0, 0, '1999-02-03', 27, '', '', '', 0, 20, 5, '', '', '', " .
            "'2026-05-27 10:00:00', 13, 1, 0, 0, 0, 1, '', '', '0000-00-00', 0)"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('models_groups') .
            " VALUES (3, 'Featured Models'), (4, 'Archived Models')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('models_videos') .
            ' VALUES (30, 1), (30, 2), (20, 3), (10, 4)'
        );
        $db->exec('INSERT INTO ' . TestHelper::table('models_albums') . ' VALUES (30, 1), (20, 2)');
        $db->exec('INSERT INTO ' . TestHelper::table('models_posts') . ' VALUES (30, 1), (30, 2), (20, 3)');
        $db->exec(
            'INSERT INTO ' . TestHelper::table('comments') .
            ' VALUES (4, 30), (4, 30), (4, 20), (5, 30)'
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('list_countries') .
            " VALUES ('CA', 'en', 'Canada'), ('US', 'en', 'United States')"
        );

        return $db;
    }

    private function createCommand(PDO $db): ModelCommand
    {
        return new class ($this->config, $db) extends ModelCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:model');
                $this->setDescription('Manage KVS models (performers)');
                $this->setAliases(['model', 'models', 'performer', 'performers']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
