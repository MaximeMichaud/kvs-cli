<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Video\FormatsCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(FormatsCommand::class)]
class FormatsCommandTest extends TestCase
{
    private string $kvsPath;
    private string $storagePath;
    private Configuration $config;
    private FormatsCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->storagePath = $this->kvsPath . '/storage/videos';
        $this->createVideoStorage();
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

    public function testListFormatsRequiresVideoId(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required', $output);
    }

    public function testListFormatsWithInvalidVideoId(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video directory not found', $output);
    }

    public function testListFormatsMissingDirectoryJsonReturnsStructuredFailure(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '999999999',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertSame('999999999', $rows[0]['video_id']);
        $this->assertFalse($rows[0]['exists']);
        $this->assertSame('Video directory not found', $rows[0]['message']);
        $this->assertStringNotContainsString('[ERROR]', $output);
    }

    public function testListFormatsRejectsInvalidFormatBeforeMissingDirectory(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '999999999',
            '--format' => 'xml',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Invalid value for --format "xml"', $output);
        $this->assertStringNotContainsString('Video directory not found', $output);
    }

    public function testListFormatsWithExistingVideo(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '10'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('720p MP4', $output);
        $this->assertStringContainsString('10_720p.mp4', $output);
        $this->assertStringContainsString('1.00 KB', $output);
        $this->assertStringContainsString('Unknown', $output);
    }

    public function testCheckFormatsRequiresVideoId(): void
    {
        $this->tester->execute(['action' => 'check']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required', $output);
    }

    public function testCheckFormatsWithInvalidVideoId(): void
    {
        $this->tester->execute([
            'action' => 'check',
            'video_id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video directory not found for video ID: 999999999', $output);
    }

    public function testCheckFormatsMissingDirectoryJsonReturnsStructuredFailure(): void
    {
        $this->tester->execute([
            'action' => 'check',
            'video_id' => '999999999',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertSame('999999999', $rows[0]['video_id']);
        $this->assertFalse($rows[0]['exists']);
        $this->assertSame('Video directory not found', $rows[0]['message']);
        $this->assertStringNotContainsString('[ERROR]', $output);
    }

    public function testCheckFormatsRejectsInvalidFormatBeforeMissingDirectory(): void
    {
        $this->tester->execute([
            'action' => 'check',
            'video_id' => '999999999',
            '--format' => 'xml',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Invalid value for --format "xml"', $output);
        $this->assertStringNotContainsString('Video directory not found', $output);
    }

    public function testCheckFormatsWithExistingVideo(): void
    {
        $this->tester->execute([
            'action' => 'check',
            'video_id' => '10'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('720p MP4', $output);
        $this->assertStringContainsString('1080p MP4', $output);
        $this->assertStringContainsString('available', strtolower($output));
        $this->assertStringContainsString('missing', strtolower($output));
    }

    public function testCheckFormatsJsonSucceedsWhenOnlyOptionalFormatsAreMissing(): void
    {
        $this->tester->execute([
            'action' => 'check',
            'video_id' => '10',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $statuses = array_column($rows, 'status');

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertContains('available', $statuses);
        $this->assertContains('missing', $statuses);
    }

    public function testCheckFormatsJsonFailsWhenRequiredFormatIsMissing(): void
    {
        $videoDir = $this->storagePath . '/0/13';
        mkdir($videoDir, 0775, true);
        file_put_contents($videoDir . '/13_1080p.mp4', str_repeat('x', 1024));
        $this->db->exec(
            'INSERT INTO ' . TestHelper::table('videos') .
            " (video_id, server_group_id, load_type_id, status_id, file_formats) VALUES (13, 1, 1, 1, '||_1080p.mp4|')"
        );

        $this->tester->execute([
            'action' => 'check',
            'video_id' => '13',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsByPostfix = array_column($rows, null, 'postfix');

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertSame('missing', $rowsByPostfix['_720p.mp4']['status'] ?? null);
    }

    public function testShowAvailableFormats(): void
    {
        $this->tester->execute(['action' => 'available']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Available Format Configurations', $output);
        $this->assertStringContainsString('720p MP4', $output);
        $this->assertStringContainsString('Required', $output);
        $this->assertStringContainsString('Access', $output);
        $this->assertStringContainsString('Premium', $output);
    }

    public function testAvailableFormatsRejectsInvalidFormat(): void
    {
        $this->tester->execute([
            'action' => 'available',
            '--format' => 'xml',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Invalid value for --format "xml"', $output);
        $this->assertStringNotContainsString('Available Format Configurations', $output);
    }

    public function testAvailableFormatsShowsConditionalStatusLikeKvsAdmin(): void
    {
        $this->tester->execute([
            'action' => 'available',
            '--fields' => 'format_id,title,status',
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $statusesByTitle = array_column($rows, 'status', 'title');

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertSame('Conditional', $statusesByTitle['Conditional MP4'] ?? null);
    }

    public function testAvailableFormatsExposeKvsAdminFields(): void
    {
        $this->tester->execute([
            'action' => 'available',
            '--fields' => implode(',', [
                'format_video_id',
                'title',
                'postfix',
                'status_id',
                'format_video_group_id',
                'access_level_id',
                'is_download_enabled',
                'is_timeline_enabled',
                'is_hotlink_protection_enabled',
                'size',
                'limit_total_duration',
                'limit_speed_value',
                'videos_count',
            ]),
            '--format' => 'json',
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'format_video_id');

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertSame('720p MP4', $rowsById[1]['title']);
        $this->assertSame('_720p.mp4', $rowsById[1]['postfix']);
        $this->assertSame(1, (int) $rowsById[1]['status_id']);
        $this->assertSame(1, (int) $rowsById[1]['format_video_group_id']);
        $this->assertSame(0, (int) $rowsById[1]['access_level_id']);
        $this->assertSame(1, (int) $rowsById[1]['is_download_enabled']);
        $this->assertSame('10s', $rowsById[1]['is_timeline_enabled']);
        $this->assertSame(1, (int) $rowsById[1]['is_hotlink_protection_enabled']);
        $this->assertSame('1280x720 (dynamic width)', $rowsById[1]['size']);
        $this->assertSame('As source', $rowsById[1]['limit_total_duration']);
        $this->assertSame('2048 kbit/s', $rowsById[1]['limit_speed_value']);
        $this->assertSame(1, (int) $rowsById[1]['videos_count']);

        $this->assertSame(0, (int) $rowsById[2]['is_hotlink_protection_enabled']);
        $this->assertSame(0, (int) $rowsById[3]['videos_count']);
        $this->assertSame(9, (int) $rowsById[3]['status_id']);
    }

    #[DataProvider('provideOutputFormats')]
    public function testListFormatsOutputFormats(string $format): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '10',
            '--format' => $format
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        if ($format === 'json') {
            $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(1, $rows);
            $this->assertSame('720p MP4', $rows[0]['format']);
            $this->assertSame('10_720p.mp4', $rows[0]['file']);
            return;
        }

        $this->assertStringContainsString('720p MP4', $this->tester->getDisplay());
    }

    public static function provideOutputFormats(): array
    {
        return [
            'table format' => ['table'],
            'json format' => ['json'],
        ];
    }

    public function testDefaultActionShowsAvailableFormats(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringNotContainsString('Video ID is required', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('720p MP4', $output);
    }

    public function testRejectsUnknownAction(): void
    {
        $this->tester->execute([
            'action' => 'unknown_action',
            'video_id' => '10',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown formats action "unknown_action"', $this->tester->getDisplay());
    }

    public function testCommandHasExpectedAliases(): void
    {
        $aliases = $this->command->getAliases();

        $this->assertContains('formats', $aliases);
    }

    public function testHelpDistinguishesDiskFilesFromConfiguredFormats(): void
    {
        $output = $this->command->getHelp();

        $this->assertStringContainsString('list <video_id>     List actual video files found on disk', $output);
        $this->assertStringContainsString('check <video_id>    Compare disk files against configured formats', $output);
        $this->assertStringContainsString('available           Show all configured format options from KVS', $output);
    }

    private function createVideoStorage(): void
    {
        $videoDir = $this->storagePath . '/0/10';
        mkdir($videoDir, 0775, true);
        file_put_contents($videoDir . '/10_720p.mp4', str_repeat('x', 1024));
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('videos') . ' (' .
            'video_id INTEGER, server_group_id INTEGER, load_type_id INTEGER, status_id INTEGER, file_formats TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('admin_servers') . ' (' .
            'server_id INTEGER, path TEXT, content_type_id INTEGER, status_id INTEGER, is_remote INTEGER, group_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('formats_videos') . ' (' .
            'format_video_id INTEGER, title TEXT, postfix TEXT, status_id INTEGER, is_conditional INTEGER, ' .
            'format_video_group_id INTEGER, access_level_id INTEGER, is_download_enabled INTEGER, ' .
            'is_timeline_enabled INTEGER, is_hotlink_protection_disabled INTEGER, size TEXT, resize_option2 INTEGER, ' .
            'limit_total_duration INTEGER, limit_total_duration_unit_id INTEGER, limit_total_min_duration_sec INTEGER, ' .
            'limit_total_max_duration_sec INTEGER, limit_number_parts INTEGER, limit_offset_start INTEGER, ' .
            'limit_offset_start_unit_id INTEGER, limit_offset_end INTEGER, limit_offset_end_unit_id INTEGER, ' .
            'timeline_option INTEGER, timeline_amount INTEGER, timeline_interval INTEGER, limit_speed_option INTEGER, ' .
            'limit_speed_value INTEGER, limit_speed_guests_option INTEGER, limit_speed_guests_value INTEGER, ' .
            'limit_speed_standard_option INTEGER, limit_speed_standard_value INTEGER, limit_speed_premium_option INTEGER, ' .
            'limit_speed_premium_value INTEGER, limit_speed_embed_option INTEGER, limit_speed_embed_value INTEGER, ' .
            'limit_speed_countries_option INTEGER, limit_speed_countries_value INTEGER)'
        );

        $db->exec(
            'INSERT INTO ' . TestHelper::table('videos') .
            ' (video_id, server_group_id, load_type_id, status_id, file_formats) VALUES ' .
            "(10, 1, 1, 1, '||_720p.mp4|'), " .
            "(11, 1, 1, 2, '||_720p.mp4|'), " .
            "(12, 1, 1, 1, '||_1080p.mp4|')"
        );
        $serverPath = $db->quote($this->storagePath);
        $db->exec(
            'INSERT INTO ' . TestHelper::table('admin_servers') .
            " VALUES (1, {$serverPath}, 1, 1, 0, 1)"
        );
        $formatRows = [
            [
                1, "'720p MP4'", "'_720p.mp4'", 1, 0, 1, 0, 1, 1, 0, "'1280x720'", 2,
                0, 0, 0, 0, 1, 0, 0, 0, 0, 2, 0, 10, 1, 2048, 1, 2048, 1, 2048, 1, 2048, 1, 2048, 0, 0,
            ],
            [
                2, "'1080p MP4'", "'_1080p.mp4'", 2, 0, 1, 2, 0, 0, 1, "'1920x1080'", 2,
                0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            ],
            [
                3, "'Conditional MP4'", "'_cond.mp4'", 2, 1, 1, 0, 0, 0, 0, "'3840x2160'", 2,
                0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            ],
        ];
        $formatValuesSql = implode(', ', array_map(
            static fn (array $row): string => '(' . implode(', ', array_map('strval', $row)) . ')',
            $formatRows
        ));

        $db->exec(
            'INSERT INTO ' . TestHelper::table('formats_videos') .
            " (format_video_id, title, postfix, status_id, is_conditional, format_video_group_id, access_level_id, " .
            "is_download_enabled, is_timeline_enabled, is_hotlink_protection_disabled, size, resize_option2, " .
            "limit_total_duration, limit_total_duration_unit_id, limit_total_min_duration_sec, " .
            "limit_total_max_duration_sec, limit_number_parts, limit_offset_start, limit_offset_start_unit_id, " .
            "limit_offset_end, limit_offset_end_unit_id, timeline_option, timeline_amount, timeline_interval, " .
            "limit_speed_option, limit_speed_value, limit_speed_guests_option, limit_speed_guests_value, " .
            "limit_speed_standard_option, limit_speed_standard_value, limit_speed_premium_option, " .
            "limit_speed_premium_value, limit_speed_embed_option, limit_speed_embed_value, " .
            "limit_speed_countries_option, limit_speed_countries_value) VALUES {$formatValuesSql}"
        );

        return $db;
    }

    private function createCommand(PDO $db): FormatsCommand
    {
        return new class ($this->config, $db) extends FormatsCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('video:formats');
                $this->setDescription('Manage video formats');
                $this->setAliases(['formats']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
