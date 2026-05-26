<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Video\ScreenshotsCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ScreenshotsPathTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-screenshots-path-test-');
        TestHelper::createMockKvsInstallation($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            TestHelper::removeDir($this->tempDir);
        }
    }

    public function testListUsesKvsDirectoryBucket(): void
    {
        $screenshotsDir = $this->tempDir . '/contents/videos_screenshots/1000/1234';
        mkdir($screenshotsDir . '/320x180', 0755, true);
        file_put_contents($screenshotsDir . '/preview.jpg', 'preview');
        file_put_contents($screenshotsDir . '/320x180/0.jpg', 'format');

        $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'list',
            'video_id' => '1234',
        ]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('preview.jpg', $output);
        $this->assertStringContainsString('320x180/0.jpg', $output);
    }

    public function testNumericFirstArgumentListsScreenshots(): void
    {
        $screenshotsDir = $this->tempDir . '/contents/videos_screenshots/1000/1234';
        mkdir($screenshotsDir, 0755, true);
        file_put_contents($screenshotsDir . '/preview.jpg', 'preview');

        $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);
        $tester->execute([
            'action' => '1234',
        ]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('preview.jpg', $output);
    }

    public function testListFallsBackWhenConfiguredScreenshotsPathIsStale(): void
    {
        TestHelper::createMockSetupConfig($this->tempDir, [
            'content_path_videos_screenshots' => '/stale/videos_screenshots',
        ]);

        $screenshotsDir = $this->tempDir . '/contents/videos_screenshots/1000/1234';
        mkdir($screenshotsDir, 0755, true);
        file_put_contents($screenshotsDir . '/preview.jpg', 'preview');

        $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'list',
            'video_id' => '1234',
            '--fields' => 'filename,path',
            '--format' => 'json',
        ]);

        $output = $tester->getDisplay();
        $rows = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('preview.jpg', $output);
        $this->assertSame(
            $this->tempDir . '/contents/videos_screenshots/1000/1234/preview.jpg',
            $rows[0]['path'] ?? null
        );
        $this->assertStringNotContainsString('/stale/videos_screenshots', $output);
    }

    public function testGenerateUsesConfiguredFfmpegAndFfprobePaths(): void
    {
        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);

        $ffprobe = $toolsDir . '/ffprobe';
        file_put_contents($ffprobe, "#!/bin/sh\necho '12.0'\n");
        chmod($ffprobe, 0755);

        $ffmpeg = $toolsDir . '/ffmpeg';
        file_put_contents(
            $ffmpeg,
            <<<'SH'
#!/bin/sh
previous=''
for arg in "$@"; do
  if [ "$arg" = '-y' ]; then
    printf 'jpg' > "$previous"
    exit 0
  fi
  previous="$arg"
done
exit 1
SH
        );
        chmod($ffmpeg, 0755);

        $sourcesPath = $this->tempDir . '/contents/videos_sources';
        $screenshotsPath = $this->tempDir . '/contents/videos_screenshots';
        mkdir($sourcesPath . '/1000/1234', 0755, true);
        file_put_contents($sourcesPath . '/1000/1234/source.mp4', 'video');

        TestHelper::createMockSetupConfig($this->tempDir, [
            'content_path_videos_sources' => $sourcesPath,
            'content_path_videos_screenshots' => $screenshotsPath,
            'ffmpeg_path' => $ffmpeg,
            'ffprobe_path' => $ffprobe,
        ]);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . '/empty');

        try {
            $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
            $tester = new CommandTester($command);
            $tester->execute([
                'action' => 'generate',
                'video_id' => '1234',
                '--count' => '1',
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertFileExists($screenshotsPath . '/1000/1234/001.jpg');
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }
}
