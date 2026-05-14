<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\StatsCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class StatsCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        file_put_contents(
            $this->tempDir . '/admin/include/setup_db.php',
            "<?php\n"
            . "define('DB_HOST', 'localhost');\n"
            . "define('DB_LOGIN', 'user');\n"
            . "define('DB_PASS', 'pass');\n"
            . "define('DB_DEVICE', 'kvs');\n"
        );
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            "<?php\n\$config = ['tables_prefix' => 'ktvs_'];\n"
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testVideoStatsUseKvsRatingPercent(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_videos (' .
            'video_id INTEGER, title TEXT, dir TEXT, status_id INTEGER, has_errors INTEGER, ' .
            'video_viewed INTEGER, video_viewed_unique INTEGER, rating REAL, rating_amount INTEGER, ' .
            'comments_count INTEGER, favourites_count INTEGER, duration INTEGER)'
        );
        $db->exec(
            "INSERT INTO ktvs_videos VALUES " .
            "(1, 'Many Votes Lower Average', 'many', 1, 0, 100, 10, 80, 20, 0, 0, 60), " .
            "(2, 'Better Average', 'better', 1, 0, 200, 20, 45, 5, 0, 0, 120)"
        );

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--videos' => true, '--top' => '2']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Avg Rating %', $output);
        $this->assertStringContainsString('130.00%', $output);
        $this->assertStringContainsString('180.0% (5)', $output);
        $this->assertStringContainsString('80.0% (20)', $output);

        $ratingSectionStart = strpos($output, 'Top by Rating %:');
        $this->assertIsInt($ratingSectionStart);
        $ratingSection = substr($output, $ratingSectionStart);
        $betterAveragePosition = strpos($ratingSection, 'Better Average');
        $manyVotesPosition = strpos($ratingSection, 'Many Votes Lower Average');
        $this->assertIsInt($betterAveragePosition);
        $this->assertIsInt($manyVotesPosition);
        $this->assertLessThan($manyVotesPosition, $betterAveragePosition);
    }

    public function testAlbumStatsUseKvsRatingPercent(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_albums (' .
            'album_id INTEGER, title TEXT, status_id INTEGER, album_viewed INTEGER, rating REAL, ' .
            'rating_amount INTEGER, photos_amount INTEGER)'
        );
        $db->exec(
            "INSERT INTO ktvs_albums VALUES " .
            "(1, 'High Rated Album', 1, 300, 90, 10, 8), " .
            "(2, 'Lower Rated Album', 1, 100, 25, 5, 4)"
        );

        $tester = new CommandTester($this->createStatsCommand($db));
        $tester->execute(['--albums' => true, '--top' => '2']);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Avg Rating %', $output);
        $this->assertStringContainsString('140.00%', $output);
        $this->assertStringContainsString('180.0% (10)', $output);
        $this->assertStringContainsString('100.0% (5)', $output);
    }

    public function testModelAndDvdStatsUseKvsRatingPercent(): void
    {
        $db = $this->createSqliteConnection();
        $db->exec(
            'CREATE TABLE ktvs_models (' .
            'model_id INTEGER, title TEXT, status_id INTEGER, total_videos INTEGER, total_albums INTEGER, ' .
            'model_viewed INTEGER, rating REAL, rating_amount INTEGER)'
        );
        $db->exec(
            "INSERT INTO ktvs_models VALUES " .
            "(1, 'Model One', 1, 5, 2, 100, 80, 10)"
        );
        $db->exec(
            'CREATE TABLE ktvs_dvds (' .
            'dvd_id INTEGER, title TEXT, status_id INTEGER, total_videos INTEGER, dvd_viewed INTEGER, ' .
            'rating REAL, rating_amount INTEGER)'
        );
        $db->exec(
            "INSERT INTO ktvs_dvds VALUES " .
            "(1, 'DVD One', 1, 4, 90, 45, 5)"
        );

        $modelTester = new CommandTester($this->createStatsCommand($db));
        $modelTester->execute(['--models' => true, '--top' => '1']);

        $dvdTester = new CommandTester($this->createStatsCommand($db));
        $dvdTester->execute(['--dvds' => true, '--top' => '1']);

        $this->assertSame(0, $modelTester->getStatusCode());
        $this->assertStringContainsString('160.0% (10)', $modelTester->getDisplay());
        $this->assertStringNotContainsString('8.0 (10)', $modelTester->getDisplay());

        $this->assertSame(0, $dvdTester->getStatusCode());
        $this->assertStringContainsString('180.0% (5)', $dvdTester->getDisplay());
        $this->assertStringNotContainsString('9.0 (5)', $dvdTester->getDisplay());
    }

    private function createSqliteConnection(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $db;
    }

    private function createStatsCommand(\PDO $db): StatsCommand
    {
        return new class ($this->createConfig(), $db) extends StatsCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:stats');
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
}
