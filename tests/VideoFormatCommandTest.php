<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Settings\VideoFormatCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(VideoFormatCommand::class)]
class VideoFormatCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private VideoFormatCommand $command;
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

    public function testListExposesKvsAdminAppendAndErrorFields(): void
    {
        $progressDir = $this->kvsPath . '/admin/data/engine/tasks';
        self::assertTrue(is_dir($progressDir) || mkdir($progressDir, 0777, true));
        file_put_contents($progressDir . '/99.dat', '37');

        $this->tester->execute([
            '--force' => true,
            'action' => 'list',
            '--format' => 'json',
            '--fields' => implode(',', [
                'format_video_id',
                'status_id',
                'pc_complete',
                'is_error',
                'source_text',
                'watermark_position_offset',
                'watermark_position_scrolling',
                'watermark2_position_offset',
                'watermark2_position_scrolling',
            ]),
        ]);

        $rows = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $rowsById = array_column($rows, null, 'format_video_id');

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(4, (int) $rowsById[1]['status_id']);
        $this->assertSame(1, (int) $rowsById[1]['is_error']);
        $this->assertSame('', $rowsById[1]['pc_complete']);

        $this->assertSame(3, (int) $rowsById[2]['status_id']);
        $this->assertSame(0, (int) $rowsById[2]['is_error']);
        $this->assertSame('37%', $rowsById[2]['pc_complete']);

        $this->assertSame(4, (int) $rowsById[3]['status_id']);
        $this->assertSame(1, (int) $rowsById[3]['is_error']);

        $this->assertSame('(Use as source)', $rowsById[4]['source_text']);
        $this->assertSame('2 x 5s ±3', $rowsById[4]['watermark_position_scrolling']);
        $this->assertSame('±4', $rowsById[4]['watermark2_position_offset']);
        $this->assertSame('', $rowsById[4]['watermark_position_offset']);
        $this->assertSame('', $rowsById[4]['watermark2_position_scrolling']);
    }

    public function testListFiltersByKvsAdminSearchText(): void
    {
        $cases = [
            'title' => ['Format 4', [4]],
            'postfix' => ['_stale', [1]],
            'ffmpeg options' => ['scale=1280', [3]],
            'missing' => ['missing-format-term', []],
        ];

        foreach ($cases as $label => [$search, $expectedIds]) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => 'list',
                '--search' => $search,
                '--format' => 'json',
                '--fields' => 'format_video_id',
            ]);

            $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $tester->getStatusCode(), "{$label}: {$tester->getDisplay()}");
            $this->assertSame(
                $expectedIds,
                array_map(static fn (array $row): int => (int) $row['format_video_id'], $rows),
                $label
            );
        }
    }

    public function testShowRejectsListOnlyFilters(): void
    {
        foreach (['status' => 'required', 'group' => '1', 'search' => 'mp4'] as $option => $value) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => 'show',
                'id' => '1',
                '--' . $option => $value,
            ]);

            $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString("show action does not support --{$option}", $tester->getDisplay());
        }
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        $db->exec(
            'CREATE TABLE ' . TestHelper::table('formats_videos_groups') . ' (' .
            'format_video_group_id INTEGER, title TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('formats_videos') . ' (' .
            'format_video_id INTEGER, format_video_group_id INTEGER, title TEXT, postfix TEXT, ' .
            'status_id INTEGER, is_conditional INTEGER, is_hotlink_protection_disabled INTEGER, ' .
            'access_level_id INTEGER, is_download_enabled INTEGER, is_timeline_enabled INTEGER, ' .
            'size TEXT, resize_option2 INTEGER, limit_total_duration INTEGER, limit_total_duration_unit_id INTEGER, ' .
            'limit_total_min_duration_sec INTEGER, limit_total_max_duration_sec INTEGER, limit_number_parts INTEGER, ' .
            'customize_duration_id INTEGER, customize_offset_start_id INTEGER, customize_offset_end_id INTEGER, ' .
            'limit_offset_start INTEGER, limit_offset_start_unit_id INTEGER, limit_offset_end INTEGER, ' .
            'limit_offset_end_unit_id INTEGER, limit_speed_option INTEGER, limit_speed_value INTEGER, ' .
            'limit_speed_guests_option INTEGER, limit_speed_guests_value INTEGER, ' .
            'limit_speed_standard_option INTEGER, limit_speed_standard_value INTEGER, ' .
            'limit_speed_premium_option INTEGER, limit_speed_premium_value INTEGER, ' .
            'limit_speed_embed_option INTEGER, limit_speed_embed_value INTEGER, ' .
            'limit_speed_countries_option INTEGER, limit_speed_countries_value INTEGER, ' .
            'timeline_option INTEGER, timeline_amount INTEGER, timeline_interval INTEGER, is_use_as_source INTEGER, ' .
            'watermark_position_id INTEGER, watermark_offset_random TEXT, watermark_scrolling_times INTEGER, ' .
            'watermark_scrolling_duration INTEGER, watermark2_position_id INTEGER, watermark2_offset_random TEXT, ' .
            'watermark2_scrolling_times INTEGER, watermark2_scrolling_duration INTEGER, ffmpeg_options TEXT)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('videos') . ' (' .
            'file_formats TEXT, load_type_id INTEGER, status_id INTEGER)'
        );
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('background_tasks') . ' (' .
            'task_id INTEGER, type_id INTEGER, data TEXT)'
        );

        $db->exec("INSERT INTO " . TestHelper::table('formats_videos_groups') . " VALUES (1, 'Main')");
        $this->insertFormat($db, 1, '_stale.mp4', 3, 0, 0, '', 0, 0, 0, '', 0);
        $this->insertFormat($db, 2, '_deleting.mp4', 3, 0, 0, '', 0, 0, 0, '', 0);
        $this->insertFormat($db, 3, '_error.mp4', 4, 0, 0, '', 0, 0, 0, '', 0, 'scale=1280:-2');
        $this->insertFormat($db, 4, '_source.mp4', 1, 1, 5, '3', 2, 5, 0, '4', 0);
        $db->exec(
            'INSERT INTO ' . TestHelper::table('background_tasks') .
            " VALUES (99, 6, '" . serialize(['format_postfix' => '_deleting.mp4']) . "')"
        );

        return $db;
    }

    private function insertFormat(
        PDO $db,
        int $formatId,
        string $postfix,
        int $statusId,
        int $isUseAsSource,
        int $watermarkPositionId,
        string $watermarkRandom,
        int $watermarkTimes,
        int $watermarkDuration,
        int $watermark2PositionId,
        string $watermark2Random,
        int $watermark2Times,
        string $ffmpegOptions = ''
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO ' . TestHelper::table('formats_videos') .
            ' VALUES (:id, 1, :title, :postfix, :status_id, 0, 0, 0, 1, 0, ' .
            "'720x400', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, " .
            '0, 0, 0, 0, 0, :is_use_as_source, :watermark_position_id, :watermark_random, ' .
            ':watermark_times, :watermark_duration, :watermark2_position_id, :watermark2_random, ' .
            ':watermark2_times, 0, :ffmpeg_options)'
        );
        $stmt->execute([
            'id' => $formatId,
            'title' => "Format {$formatId}",
            'postfix' => $postfix,
            'status_id' => $statusId,
            'is_use_as_source' => $isUseAsSource,
            'watermark_position_id' => $watermarkPositionId,
            'watermark_random' => $watermarkRandom,
            'watermark_times' => $watermarkTimes,
            'watermark_duration' => $watermarkDuration,
            'watermark2_position_id' => $watermark2PositionId,
            'watermark2_random' => $watermark2Random,
            'watermark2_times' => $watermark2Times,
            'ffmpeg_options' => $ffmpegOptions,
        ]);
    }

    private function createCommand(PDO $db): VideoFormatCommand
    {
        return new class ($this->config, $db) extends VideoFormatCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('settings:video-format');
                $this->setDescription('[EXPERIMENTAL] Manage KVS video formats');
                $this->setAliases(['video-format', 'vformat']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
