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
        $this->assertSame('Inactive', $rows[0]['status']);
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

    public function testListModelsSearchesDescriptionLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Main model profile',
            '--format' => 'json',
            '--fields' => 'model_id,description',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame([30], array_map(static fn (array $row): int => (int) $row['model_id'], $rows));
        $this->assertSame('Main model profile', $rows[0]['description']);
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

    public function testListModelsExposesKvsAdminThumbAndRelationFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Test Model',
            '--fields' => 'model_id,thumb,screenshot1,screenshot2,tags,categories',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['model_id']);
        $this->assertSame('model-1.jpg', $rows[0]['thumb']);
        $this->assertSame('model-1.jpg', $rows[0]['screenshot1']);
        $this->assertSame('model-2.jpg', $rows[0]['screenshot2']);
        $this->assertSame('interview,featured', $rows[0]['tags']);
        $this->assertSame('Guests,Performers', $rows[0]['categories']);
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

    public function testListModelsExposesKvsAdminListFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'Test Model',
            '--fields' => 'model_id,title,tags,categories',
            '--format' => 'json',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['model_id']);
        $this->assertSame('Test Model', $rows[0]['title']);
        $this->assertSame('interview,featured', $rows[0]['tags']);
        $this->assertSame('Guests,Performers', $rows[0]['categories']);
    }

    public function testListModelsFiltersByKvsAdminRelations(): void
    {
        $cases = [
            ['--group' => 'Featured Models', 'expected' => [30]],
            ['--model-group' => 'Archived Models', 'expected' => [20]],
            ['--tag' => 'featured', 'expected' => [30]],
            ['--category' => 'Performers', 'expected' => [30]],
            ['--group' => 'Missing Group', 'expected' => []],
            ['--tag' => 'missing', 'expected' => []],
            ['--category' => 'Missing Category', 'expected' => []],
        ];

        foreach ($cases as $case) {
            $expected = $case['expected'];
            unset($case['expected']);

            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                ...$case,
                '--format' => 'json',
                '--fields' => 'model_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expected, array_map(static fn (array $row): int => (int) $row['model_id'], $rows));
        }
    }

    public function testListModelsRejectsConflictingGroupAliases(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--group' => 'Featured Models',
            '--model-group' => 'Archived Models',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Options --group and --model-group cannot be used together', $this->tester->getDisplay());
    }

    public function testListModelsFiltersByKvsAdminUsage(): void
    {
        $cases = [
            'used/videos' => [30, 20, 10],
            'notused/videos' => [],
            'used/albums' => [30, 20],
            'notused/albums' => [10],
            'used/posts' => [30, 20],
            'notused/posts' => [10],
            'used/other' => [30],
            'notused/other' => [20, 10],
            'used/all' => [30, 20, 10],
            'notused/all' => [],
        ];

        foreach ($cases as $usage => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--usage' => $usage,
                '--format' => 'json',
                '--fields' => 'model_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['model_id'], $rows), $usage);
        }
    }

    public function testListModelsFiltersByKvsAdminFieldFilter(): void
    {
        $cases = [
            'filled/description' => [30],
            'empty/description' => [20, 10],
            'filled/alias' => [30],
            'empty/alias' => [20, 10],
            'filled/screenshot1' => [30],
            'empty/screenshot1' => [20, 10],
            'filled/screenshot2' => [30],
            'empty/screenshot2' => [20, 10],
            'filled/country' => [30, 10],
            'empty/country' => [20],
            'filled/city' => [30],
            'empty/city' => [20, 10],
            'filled/state' => [30],
            'empty/state' => [20, 10],
            'filled/height' => [30],
            'empty/height' => [20, 10],
            'filled/weight' => [30],
            'empty/weight' => [20, 10],
            'filled/measurements' => [30],
            'empty/measurements' => [20, 10],
            'filled/gallery_url' => [30],
            'empty/gallery_url' => [20, 10],
            'filled/custom1' => [30],
            'empty/custom1' => [20, 10],
            'filled/custom_file1' => [30],
            'empty/custom_file1' => [20, 10],
            'filled/group' => [30, 20],
            'empty/group' => [10],
            'filled/hair_id' => [30],
            'empty/hair_id' => [20, 10],
            'filled/eye_color_id' => [30],
            'empty/eye_color_id' => [20, 10],
            'filled/age' => [30, 10],
            'empty/age' => [20],
            'filled/model_viewed' => [30, 20, 10],
            'empty/model_viewed' => [],
            'filled/rating' => [30, 10],
            'empty/rating' => [20],
            'filled/tags' => [30],
            'empty/tags' => [20, 10],
            'filled/categories' => [30],
            'empty/categories' => [20, 10],
        ];

        foreach ($cases as $filter => $expectedIds) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'list',
                '--field-filter' => $filter,
                '--format' => 'json',
                '--fields' => 'model_id',
                '--limit' => 10,
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($expectedIds, array_map(static fn (array $row): int => (int) $row['model_id'], $rows), $filter);
        }
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

    public function testShowModelSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertSame('30', $rows[0]['model_id']);
        $this->assertSame('Test Model', $rows[0]['name']);
        $this->assertSame('2', $rows[0]['videos']);
        $this->assertSame('Main model profile', $rows[0]['description']);
        $this->assertStringNotContainsString('Model: Test Model', $output);
    }

    public function testShowModelRejectsNonIntegerIdBeforeQuery(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30abc',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid Model ID', $this->tester->getDisplay());
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
        $this->assertMatchesRegularExpression('/Inactive\W+1/', $output);
        $this->assertMatchesRegularExpression('/Models with Videos\W+3/', $output);
        $this->assertMatchesRegularExpression('/Total Video Relations\W+4/', $output);
    }

    public function testStatsSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'stats',
            '--format' => 'json',
            '--fields' => 'section,metric,value,label',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsByMetric = array_column($rows, null, 'metric');

        $this->assertEquals(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame('overall', $rowsByMetric['Total Models']['section'] ?? null);
        $this->assertSame(3, (int) ($rowsByMetric['Total Models']['value'] ?? 0));
        $this->assertSame(1, (int) ($rowsByMetric['Inactive']['value'] ?? 0));
        $this->assertArrayNotHasKey('Disabled', $rowsByMetric);
        $this->assertStringNotContainsString('Model Statistics', $this->tester->getDisplay());
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
            'screenshot1 TEXT, screenshot2 TEXT, ' .
            'country TEXT, gender_id INTEGER, hair_id INTEGER, eye_color_id INTEGER, birth_date TEXT, age INTEGER, ' .
            'measurements TEXT, height TEXT, weight TEXT, rank INTEGER, rating INTEGER, rating_amount INTEGER, ' .
            'description TEXT, alias TEXT, gallery_url TEXT, added_date TEXT, sort_id INTEGER, total_videos INTEGER, total_albums INTEGER, ' .
            'total_dvds INTEGER, total_dvd_groups INTEGER, subscribers_count INTEGER, city TEXT, state TEXT, ' .
            'death_date TEXT, model_group_id INTEGER, custom1 TEXT, custom2 TEXT, custom3 TEXT, custom4 TEXT, ' .
            'custom5 TEXT, custom6 TEXT, custom7 TEXT, custom8 TEXT, custom9 TEXT, custom10 TEXT, ' .
            'custom_file1 TEXT, custom_file2 TEXT, custom_file3 TEXT, custom_file4 TEXT, custom_file5 TEXT)'
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
            'CREATE TABLE ' . TestHelper::table('tags') . ' (' .
            'tag_id INTEGER, tag TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('tags_models') . ' (' .
            'id INTEGER, tag_id INTEGER, model_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories') . ' (' .
            'category_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('categories_models') . ' (' .
            'id INTEGER, category_id INTEGER, model_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('list_countries') . ' (' .
            'country_code TEXT, language_code TEXT, title TEXT)'
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('models') .
            ' (model_id, title, dir, status_id, model_viewed, access_level_id, screenshot1, screenshot2, ' .
            'country, gender_id, hair_id, eye_color_id, ' .
            'birth_date, age, measurements, height, weight, rank, rating, rating_amount, description, alias, gallery_url, ' .
            'added_date, sort_id, total_videos, total_albums, total_dvds, ' .
            'total_dvd_groups, subscribers_count, city, state, death_date, model_group_id, custom1, custom2, custom3, ' .
            'custom4, custom5, custom6, custom7, custom8, custom9, custom10, custom_file1, custom_file2, custom_file3, ' .
            'custom_file4, custom_file5) VALUES ' .
            "(30, 'Test Model', 'test-model', 1, 100, 2, 'model-1.jpg', 'model-2.jpg', " .
            "'CA', 1, 2, 3, '2000-01-01', 26, '34-24-34', '170 cm', " .
            "'55 kg', 7, 40, 10, 'Main model profile', 'Test Alias', 'https://example.test/model-gallery', " .
            "'2026-05-25 10:00:00', 11, 2, 1, 3, 4, 6, 'Montreal', 'Quebec', '2026-01-01', 3, " .
            "'custom one', '', '', '', '', '', '', '', '', '', 'model.pdf', '', '', '', ''), " .
            "(20, 'Disabled Model', 'disabled-model', 0, 8, 0, '', '', '', 0, 0, 0, '', 0, '', '', '', 0, 0, 1, '', '', '', " .
            "'2026-05-26 10:00:00', 12, 1, 1, 0, 0, 0, '', '', '0000-00-00', 4, " .
            "'', '', '', '', '', '', '', '', '', '', '', '', '', '', ''), " .
            "(10, 'Other Performer', 'other-performer', 1, 25, 0, '', '', 'US', 0, 0, 0, '1999-02-03', 27, '', '', '', 0, 20, 5, '', '', '', " .
            "'2026-05-27 10:00:00', 13, 1, 0, 0, 0, 1, '', '', '0000-00-00', 0, " .
            "'', '', '', '', '', '', '', '', '', '', '', '', '', '', '')"
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
            'INSERT INTO ' . TestHelper::table('tags') .
            " VALUES (1, 'featured'), (2, 'interview')"
        );
        $db->exec('INSERT INTO ' . TestHelper::table('tags_models') . ' VALUES (1, 2, 30), (2, 1, 30)');
        $db->exec(
            'INSERT INTO ' . TestHelper::table('categories') .
            " VALUES (1, 'Performers'), (2, 'Guests')"
        );
        $db->exec('INSERT INTO ' . TestHelper::table('categories_models') . ' VALUES (1, 2, 30), (2, 1, 30)');
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
