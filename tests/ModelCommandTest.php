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
            'model_id INTEGER, title TEXT, status_id INTEGER, model_viewed INTEGER, country TEXT, ' .
            'birth_date TEXT, age INTEGER, measurements TEXT, height TEXT, weight TEXT, rank INTEGER, ' .
            'rating INTEGER, rating_amount INTEGER, description TEXT)'
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
            'CREATE TABLE ' . TestHelper::table('list_countries') . ' (' .
            'country_code TEXT, language_code TEXT, title TEXT)'
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('models') .
            ' (model_id, title, status_id, model_viewed, country, birth_date, age, measurements, height, ' .
            'weight, rank, rating, rating_amount, description) VALUES ' .
            "(30, 'Test Model', 1, 100, 'CA', '2000-01-01', 26, '34-24-34', '170 cm', '55 kg', 7, 40, 10, 'Main model profile'), " .
            "(20, 'Disabled Model', 0, 8, '', '', 0, '', '', '', 0, 0, 0, ''), " .
            "(10, 'Other Performer', 1, 25, 'US', '1999-02-03', 27, '', '', '', 0, 20, 5, '')"
        );
        $db->exec(
            'INSERT INTO ' . TestHelper::table('models_videos') .
            ' VALUES (30, 1), (30, 2), (20, 3), (10, 4)'
        );
        $db->exec('INSERT INTO ' . TestHelper::table('models_albums') . ' VALUES (30, 1), (20, 2)');
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
