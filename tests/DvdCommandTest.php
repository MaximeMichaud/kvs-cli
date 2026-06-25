<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\DvdCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(DvdCommand::class)]
class DvdCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private DvdCommand $command;
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

    public function testListDvdsDefault(): void
    {
        $this->tester->execute(['action' => 'list']);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Series', $this->tester->getDisplay());
        $this->assertStringContainsString('Disabled Series', $this->tester->getDisplay());
    }

    public function testListDvdsWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 5
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Other Channel', $this->tester->getDisplay());
    }

    public function testListDvdsActiveStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,status'
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(2, $rows);
        $this->assertSame([30, 10], array_map(static fn (array $row): int => (int) $row['dvd_id'], $rows));
        $this->assertSame('Active', $rows[0]['status']);
    }

    public function testListDvdsCountFormatIgnoresPaginationButAppliesFilters(): void
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

    public function testListDvdsAggregatesRelationCountsWithoutNativeTotals(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,videos,total_videos_duration,duration',
            '--limit' => '1',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertCount(1, $rows);
        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('Test Series', $rows[0]['title']);
        $this->assertSame(2, (int) $rows[0]['videos']);
        $this->assertSame(3690, (int) $rows[0]['total_videos_duration']);
        $this->assertSame('1:01:30', $rows[0]['duration']);
    }

    public function testListDvdsExposesKvsAdminVideoAmountAndDurationFields(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,videos_amount,total_duration',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('Test Series', $rows[0]['title']);
        $this->assertSame(2, (int) $rows[0]['videos_amount']);
        $this->assertSame('1:01:30', $rows[0]['total_duration']);
    }

    public function testListDvdsExposesKvsAdminSubscribersAmountField(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,subscribers_amount',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('Test Series', $rows[0]['title']);
        $this->assertSame(5, (int) $rows[0]['subscribers_amount']);
    }

    public function testListDvdsExposesKvsAdminCommentsAmountField(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => 'json',
            '--fields' => 'dvd_id,title,comments_amount',
            '--limit' => '1',
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(30, (int) $rows[0]['dvd_id']);
        $this->assertSame('Test Series', $rows[0]['title']);
        $this->assertSame(2, (int) $rows[0]['comments_amount']);
    }

    public function testListDvdsDisabledStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'disabled'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Disabled Series', $output);
        $this->assertStringNotContainsString('Test Series', $output);
    }

    public function testListDvdsSearch(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'test'
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Series', $output);
        $this->assertStringNotContainsString('Disabled Series', $output);
    }

    #[DataProvider('provideOutputFormats')]
    public function testListDvdsFormats(string $format): void
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
            $this->assertSame(30, (int) $rows[0]['dvd_id']);
            $this->assertSame('Test Series', $rows[0]['title']);
            return;
        }

        $this->assertStringContainsString('Test Series', $this->tester->getDisplay());
    }

    public static function provideOutputFormats(): array
    {
        return [
            'table format' => ['table'],
            'json format' => ['json'],
        ];
    }

    public function testShowDvd(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '30'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD: Test Series', $output);
        $this->assertStringContainsString('DVD ID', $output);
        $this->assertMatchesRegularExpression('/Videos\W+2/', $output);
        $this->assertMatchesRegularExpression('/Total Duration\W+1:01:30/', $output);
        $this->assertStringContainsString('4.0/5 (10 votes)', $output);
        $this->assertStringContainsString('Long running series', $output);
    }

    public function testShowDvdNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD not found: 999999999', $output);
    }

    public function testShowDvdMissingId(): void
    {
        $this->tester->execute([
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD ID is required', $output);
    }

    public function testStats(): void
    {
        $this->tester->execute(['action' => 'stats']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD Statistics', $output);
        $this->assertMatchesRegularExpression('/Total DVDs\W+3/', $output);
        $this->assertMatchesRegularExpression('/Active\W+2/', $output);
        $this->assertMatchesRegularExpression('/Disabled\W+1/', $output);
    }

    public function testDefaultActionIsList(): void
    {
        $this->tester->execute([]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Test Series', $this->tester->getDisplay());
    }

    public function testInvalidActionReturnsFailure(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown DVD action "invalid"', $this->tester->getDisplay());
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('dvds') . ' (' .
            'dvd_id INTEGER, title TEXT, status_id INTEGER, release_year INTEGER, dvd_viewed INTEGER, ' .
            'subscribers_count INTEGER, rating INTEGER, rating_amount INTEGER, description TEXT, ' .
            'total_videos INTEGER, total_videos_duration INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('videos') . ' (' .
            'dvd_id INTEGER, duration INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('comments') . ' (' .
            'object_type_id INTEGER, object_id INTEGER)'
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('dvds') .
            ' (dvd_id, title, status_id, release_year, dvd_viewed, subscribers_count, rating, rating_amount, ' .
            "description, total_videos, total_videos_duration) VALUES " .
            "(30, 'Test Series', 1, 2026, 100, 5, 40, 10, 'Long running series', 99, 99), " .
            "(20, 'Disabled Series', 0, 2025, 10, 0, 0, 0, '', 99, 99), " .
            "(10, 'Other Channel', 1, 2024, 50, 2, 12, 3, '', 99, 99)"
        );
        $db->exec('INSERT INTO ' . TestHelper::table('videos') . ' VALUES (30, 3600), (30, 90), (20, 120), (10, 300)');
        $db->exec(
            'INSERT INTO ' . TestHelper::table('comments') .
            ' VALUES (5, 30), (5, 30), (5, 20), (1, 30)'
        );

        return $db;
    }

    private function createCommand(PDO $db): DvdCommand
    {
        return new class ($this->config, $db) extends DvdCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:dvd');
                $this->setDescription('Manage KVS DVDs (channels/series)');
                $this->setAliases(['dvd', 'dvds', 'channel', 'channels']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
