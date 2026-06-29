<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Video\ScreenshotsCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ScreenshotsCommand::class)]
class ScreenshotsCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private ScreenshotsCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();

        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->command = new ScreenshotsCommand($this->config);
        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testListScreenshotsRequiresVideoId(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required', $output);
    }

    public function testListScreenshotsWithInvalidVideoId(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Screenshots directory not found', $output);
        $this->assertStringContainsString('999999999', $output);
    }

    public function testListScreenshotsMissingDirectoryJsonReturnsEmptyList(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '999999999',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertSame([], $rows);
        $this->assertStringNotContainsString('Screenshots directory not found', $output);
    }

    public function testListScreenshotsRejectsInvalidFormatBeforeMissingDirectory(): void
    {
        $this->tester->execute([
            'action' => 'list',
            'video_id' => '999999999',
            '--format' => 'xml',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Invalid value for --format "xml"', $output);
        $this->assertStringNotContainsString('Screenshots directory not found', $output);
    }

    public function testListScreenshotsWithExistingVideo(): void
    {
        $this->createScreenshotFixture('1234');

        $this->tester->execute([
            'action' => 'list',
            'video_id' => '1234'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('1.jpg', $output);
        $this->assertStringContainsString('1', $output);
        $this->assertStringNotContainsString('source-only.jpg', $output);
        $this->assertStringNotContainsString('preview.png', $output);
        $this->assertStringContainsString('1x1', $output);
    }

    public function testGenerateScreenshotsRequiresVideoId(): void
    {
        $this->tester->execute(['action' => 'generate']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required', $output);
    }

    public function testRegenerateScreenshotsRequiresVideoId(): void
    {
        $this->tester->execute(['action' => 'regenerate']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Video ID is required', $output);
    }

    public function testGenerateScreenshotsFailsWhenFfmpegIsMissing(): void
    {
        TestHelper::createMockSetupConfig($this->kvsPath, [
            'project_path' => $this->kvsPath,
            'tables_prefix' => TestHelper::getTablePrefix(),
            'tables_prefix_multi' => TestHelper::getTablePrefix(),
            'content_path_videos_screenshots' => $this->kvsPath . '/contents/videos_screenshots',
            'content_path_videos_sources' => $this->kvsPath . '/contents/videos_sources',
            'ffmpeg_path' => $this->kvsPath . '/missing-ffmpeg',
            'ffprobe_path' => $this->kvsPath . '/missing-ffprobe',
        ]);
        $this->reloadCommand();

        $this->tester->execute([
            'action' => 'generate',
            'video_id' => '1234'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('ffmpeg is not installed or not accessible', $output);
        $this->assertDirectoryDoesNotExist($this->kvsPath . '/contents/videos_screenshots/1000/1234');
    }

    #[DataProvider('provideOutputFormats')]
    public function testListScreenshotsOutputFormats(string $format): void
    {
        $this->createScreenshotFixture('1234');

        $this->tester->execute([
            'action' => 'list',
            'video_id' => '1234',
            '--format' => $format
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());

        if ($format === 'json') {
            $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(1, $rows);
            $this->assertSame(['1.jpg'], array_column($rows, 'filename'));
            $this->assertSame(1, (int) $rows[0]['index']);
            return;
        }

        if ($format === 'count') {
            $this->assertSame('1', trim($output));
            return;
        }

        $this->assertStringContainsString('1.jpg', $output);
    }

    public static function provideOutputFormats(): array
    {
        return [
            'table format' => ['table'],
            'json format' => ['json'],
            'count format' => ['count'],
        ];
    }

    public function testDefaultActionIsList(): void
    {
        $this->createScreenshotFixture('1234');

        $this->tester->execute([
            'video_id' => '1234'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('1.jpg', $this->tester->getDisplay());
    }

    public function testListScreenshotsIgnoresDerivedPreviewsAndTimelinesInCount(): void
    {
        $this->createScreenshotFixture('1234');
        $generatedScreenshotsDir = $this->kvsPath . '/contents/videos_screenshots/' . $this->getBucket('1234') . '/1234';
        mkdir($generatedScreenshotsDir . '/timelines/default/320x180', 0755, true);

        $image = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
            true
        );
        $this->assertIsString($image);
        file_put_contents($generatedScreenshotsDir . '/preview.jpg', $image);
        file_put_contents($generatedScreenshotsDir . '/preview_720p.mp4.jpg', $image);
        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($generatedScreenshotsDir . "/timelines/default/320x180/$i.jpg", $image);
        }

        $this->tester->execute([
            'action' => 'list',
            'video_id' => '1234',
            '--format' => 'count',
        ]);

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertSame('1', trim($this->tester->getDisplay()));
    }

    public function testUnknownActionFailsEvenWithVideoId(): void
    {
        $this->createScreenshotFixture('1234');

        $this->tester->execute([
            'action' => 'unknown_action',
            'video_id' => '1234',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown screenshots action "unknown_action"', $output);
        $this->assertStringNotContainsString('preview.png', $output);
    }

    public function testCommandHasExpectedOptions(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('count'));
        $this->assertTrue($definition->hasOption('format'));
        $this->assertFalse($definition->hasOption('type'));
    }

    public function testCommandHasExpectedAliases(): void
    {
        $aliases = $this->command->getAliases();

        $this->assertContains('screenshots', $aliases);
    }

    private function reloadCommand(): void
    {
        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->command = new ScreenshotsCommand($this->config);
        $this->tester = new CommandTester($this->command);
    }

    private function createScreenshotFixture(string $videoId): void
    {
        $sourceScreenshotsDir = $this->kvsPath . '/contents/videos_sources/' . $this->getBucket($videoId) . '/' . $videoId . '/screenshots';
        $generatedScreenshotsDir = $this->kvsPath . '/contents/videos_screenshots/' . $this->getBucket($videoId) . '/' . $videoId;
        mkdir($sourceScreenshotsDir, 0755, true);
        mkdir($generatedScreenshotsDir . '/320x180', 0755, true);

        $image = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
            true
        );
        $this->assertIsString($image);

        file_put_contents($sourceScreenshotsDir . '/source-only.jpg', $image);
        file_put_contents($sourceScreenshotsDir . '/info.dat', serialize([1 => ['type' => 'uploaded']]));
        file_put_contents($generatedScreenshotsDir . '/preview.png', $image);
        file_put_contents($generatedScreenshotsDir . '/320x180/001.jpg', $image);
    }

    private function getBucket(string $videoId): int
    {
        return (int) floor((int) $videoId / 1000) * 1000;
    }
}
