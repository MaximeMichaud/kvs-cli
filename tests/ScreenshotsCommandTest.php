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

    public function testListScreenshotsWithExistingVideo(): void
    {
        $this->createScreenshotFixture('1234');

        $this->tester->execute([
            'action' => 'list',
            'video_id' => '1234'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('preview.png', $output);
        $this->assertStringContainsString('320x180/001.jpg', $output);
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
            $this->assertCount(2, $rows);
            $this->assertSame(['320x180/001.jpg', 'preview.png'], array_column($rows, 'filename'));
            return;
        }

        if ($format === 'count') {
            $this->assertSame('2', trim($output));
            return;
        }

        $this->assertStringContainsString('preview.png', $output);
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
        $this->assertStringContainsString('preview.png', $this->tester->getDisplay());
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
        $screenshotsDir = $this->kvsPath . '/contents/videos_screenshots/' . $this->getBucket($videoId) . '/' . $videoId;
        mkdir($screenshotsDir . '/320x180', 0755, true);

        $image = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
            true
        );
        $this->assertIsString($image);

        file_put_contents($screenshotsDir . '/preview.png', $image);
        file_put_contents($screenshotsDir . '/320x180/001.jpg', $image);
        file_put_contents($screenshotsDir . '/ignore.txt', 'not an image');
    }

    private function getBucket(string $videoId): int
    {
        return (int) floor((int) $videoId / 1000) * 1000;
    }
}
