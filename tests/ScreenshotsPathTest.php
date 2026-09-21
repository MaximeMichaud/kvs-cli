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
        $sourceScreenshotsDir = $this->tempDir . '/contents/videos_sources/1000/1234/screenshots';
        $generatedScreenshotsDir = $this->tempDir . '/contents/videos_screenshots/1000/1234';
        mkdir($sourceScreenshotsDir, 0755, true);
        mkdir($generatedScreenshotsDir . '/320x180', 0755, true);
        file_put_contents($sourceScreenshotsDir . '/1.jpg', 'source');
        file_put_contents($generatedScreenshotsDir . '/preview.jpg', 'preview');
        file_put_contents($generatedScreenshotsDir . '/320x180/1.jpg', 'format');

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
        $this->assertSame('1.jpg', $rows[0]['filename'] ?? null);
        $this->assertStringContainsString('/contents/videos_screenshots/1000/1234/320x180/1.jpg', $rows[0]['path'] ?? '');
        $this->assertStringNotContainsString('preview.jpg', $output);
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

    public function testListFallbackIncludesAvifFiles(): void
    {
        $screenshotsDir = $this->tempDir . '/contents/videos_screenshots/1000/1234';
        mkdir($screenshotsDir, 0755, true);
        file_put_contents($screenshotsDir . '/preview.avif', 'preview');

        $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'list',
            'video_id' => '1234',
            '--fields' => 'filename',
            '--format' => 'json',
        ]);

        $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame([['filename' => 'preview.avif']], $rows);
    }

    public function testListRejectsPathTraversalVideoIdBeforeScanningFiles(): void
    {
        $outsideDir = $this->tempDir . '/static/images';
        mkdir($outsideDir, 0755, true);
        file_put_contents($outsideDir . '/logo.png', 'image');

        $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'list',
            'video_id' => '../../../static/images',
            '--format' => 'json',
        ]);

        $output = $tester->getDisplay();
        $this->assertSame(1, $tester->getStatusCode(), $output);
        $this->assertStringContainsString('Invalid video ID', $output);
        $this->assertStringNotContainsString('logo.png', $output);
    }

    public function testListFallsBackWhenConfiguredSourcesPathIsStale(): void
    {
        TestHelper::createMockSetupConfig($this->tempDir, [
            'content_path_videos_sources' => '/stale/videos_sources',
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
        $this->assertStringNotContainsString('/stale/videos_sources', $output);
    }

    public function testGenerateUsesConfiguredFfmpegAndFfprobePaths(): void
    {
        [$ffmpeg, $ffprobe] = $this->createMockVideoTools();

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
        putenv('PATH=' . dirname($ffmpeg) . '/empty');

        try {
            $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
            $tester = new CommandTester($command);
            $tester->execute([
                'action' => 'generate',
                'video_id' => '1234',
                '--count' => '1',
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertFileExists($sourcesPath . '/1000/1234/screenshots/1.jpg');
            $this->assertFileDoesNotExist($sourcesPath . '/1000/1234/screenshots/001.jpg');
            $this->assertFileDoesNotExist($screenshotsPath . '/1000/1234/1.jpg');
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testGenerateUsesKvsTmpSourceVideo(): void
    {
        [$tester, $sourcesPath] = $this->createGenerateFixture(['1234.tmp']);

        $tester->execute([
            'action' => 'generate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($sourcesPath . '/1000/1234/screenshots/1.jpg');
    }

    public function testGenerateFallsBackToKvsTmp2SourceVideo(): void
    {
        [$tester, $sourcesPath] = $this->createGenerateFixture(['1234.tmp2']);

        $tester->execute([
            'action' => 'generate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($sourcesPath . '/1000/1234/screenshots/1.jpg');
    }

    public function testGeneratePrefersKvsTmpSourceVideoOverTmp2(): void
    {
        $ffprobeScript = <<<'SH'
#!/bin/sh
last=''
for arg in "$@"; do
  last="$arg"
done
case "$last" in
  */1234.tmp)
    echo '12.0'
    exit 0
    ;;
esac
exit 1
SH;

        [$tester, $sourcesPath] = $this->createGenerateFixture(
            ['1234.tmp', '1234.tmp2'],
            $ffprobeScript
        );

        $tester->execute([
            'action' => 'generate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($sourcesPath . '/1000/1234/screenshots/1.jpg');
    }

    public function testGenerateRejectsZeroByteFfmpegOutput(): void
    {
        [$tester, $sourcesPath] = $this->createGenerateFixture(
            ['1234.tmp'],
            ffmpegScript: $this->createZeroByteFfmpegScript()
        );

        $tester->execute([
            'action' => 'generate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Failed to generate 1 screenshots', $tester->getDisplay());
        $this->assertFileDoesNotExist($sourcesPath . '/1000/1234/screenshots/1.jpg');
    }

    public function testGenerateRefusesExistingScreenshotSymlinkWithoutChangingItsTarget(): void
    {
        [$tester, $sourcesPath] = $this->createGenerateFixture(['1234.tmp']);

        $screenshotsPath = $sourcesPath . '/1000/1234/screenshots';
        mkdir($screenshotsPath, 0755, true);
        $outsideFile = $this->tempDir . '/outside-generate.jpg';
        file_put_contents($outsideFile, 'outside screenshot');
        $this->assertTrue(symlink($outsideFile, $screenshotsPath . '/1.jpg'));

        $tester->execute([
            'action' => 'generate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('symbolic link', $tester->getDisplay());
        $this->assertSame('outside screenshot', file_get_contents($outsideFile));
        $this->assertTrue(is_link($screenshotsPath . '/1.jpg'));
    }

    public function testGeneratePublishesMatchingImagesAndPreservesOtherFiles(): void
    {
        [$tester, $sourcesPath] = $this->createGenerateFixture(['1234.tmp']);
        $screenshotsPath = $sourcesPath . '/1000/1234/screenshots';
        mkdir($screenshotsPath, 0755, true);
        file_put_contents($screenshotsPath . '/1.jpg', 'old first image');
        file_put_contents($screenshotsPath . '/2.jpg', 'retained second image');
        file_put_contents($screenshotsPath . '/info.dat', 'retained metadata');

        $tester->execute(['action' => 'generate', 'video_id' => '1234', '--count' => '1']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('jpg', file_get_contents($screenshotsPath . '/1.jpg'));
        $this->assertSame('retained second image', file_get_contents($screenshotsPath . '/2.jpg'));
        $this->assertSame('retained metadata', file_get_contents($screenshotsPath . '/info.dat'));
        $this->assertSame([], glob($sourcesPath . '/1000/1234/.screenshots-*') ?: []);
    }

    public function testGenerateKeepsAllExistingImagesWhenLaterFrameFails(): void
    {
        $ffmpeg = <<<'SH'
#!/bin/sh
previous=''
for arg in "$@"; do
  if [ "$arg" = '-y' ]; then
    case "$previous" in
      */1.jpg) printf 'new image' > "$previous"; exit 0 ;;
      *) exit 1 ;;
    esac
  fi
  previous="$arg"
done
exit 1
SH;
        [$tester, $sourcesPath] = $this->createGenerateFixture(['1234.tmp'], ffmpegScript: $ffmpeg);
        $screenshotsPath = $sourcesPath . '/1000/1234/screenshots';
        mkdir($screenshotsPath, 0755, true);
        file_put_contents($screenshotsPath . '/1.jpg', 'old first image');
        file_put_contents($screenshotsPath . '/2.jpg', 'old second image');

        $tester->execute(['action' => 'generate', 'video_id' => '1234', '--count' => '2']);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('old first image', file_get_contents($screenshotsPath . '/1.jpg'));
        $this->assertSame('old second image', file_get_contents($screenshotsPath . '/2.jpg'));
        $this->assertSame([], glob($sourcesPath . '/1000/1234/.screenshots-*') ?: []);
    }

    public function testGenerateRefusesExistingScreenshotHardLinkWithoutChangingSharedContent(): void
    {
        [$tester, $sourcesPath] = $this->createGenerateFixture(['1234.tmp']);

        $screenshotsPath = $sourcesPath . '/1000/1234/screenshots';
        mkdir($screenshotsPath, 0755, true);
        $outsideFile = $this->tempDir . '/outside-generate-hardlink.jpg';
        file_put_contents($outsideFile, 'outside screenshot');
        $this->assertTrue(link($outsideFile, $screenshotsPath . '/1.jpg'));

        $tester->execute([
            'action' => 'generate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Refusing screenshots', $tester->getDisplay());
        $this->assertSame('outside screenshot', file_get_contents($outsideFile));
        $this->assertSame('outside screenshot', file_get_contents($screenshotsPath . '/1.jpg'));
        $this->assertSame(2, lstat($outsideFile)['nlink'] ?? null);
    }

    public function testGenerateRejectsSymbolicLinkCreatedByFfmpegWithoutChangingItsTarget(): void
    {
        $outsideFile = $this->tempDir . '/outside-generated-link.jpg';
        file_put_contents($outsideFile, 'outside screenshot');
        [$tester, $sourcesPath] = $this->createGenerateFixture(
            ['1234.tmp'],
            ffmpegScript: $this->createSymlinkFfmpegScript($outsideFile)
        );

        $tester->execute([
            'action' => 'generate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('non-regular output', $tester->getDisplay());
        $this->assertSame('outside screenshot', file_get_contents($outsideFile));
        $this->assertDirectoryDoesNotExist($sourcesPath . '/1000/1234/screenshots');
        $this->assertSame([], glob($sourcesPath . '/1000/1234/.screenshots-*') ?: []);
    }

    public function testGenerateRejectsFifoOutputWithoutBlocking(): void
    {
        $ffmpegScript = <<<'SH'
#!/bin/sh
previous=''
for arg in "$@"; do
  if [ "$arg" = '-y' ]; then
    mkfifo "$previous"
    exit 0
  fi
  previous="$arg"
done
exit 1
SH;
        [$tester, $sourcesPath] = $this->createGenerateFixture(
            ['1234.tmp'],
            ffmpegScript: $ffmpegScript
        );

        $tester->execute([
            'action' => 'generate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('non-regular output', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($sourcesPath . '/1000/1234/screenshots');
        $this->assertSame([], glob($sourcesPath . '/1000/1234/.screenshots-*') ?: []);
    }

    public function testRegenerateKeepsExistingScreenshotsWhenFfmpegCreatesZeroByteOutput(): void
    {
        [$tester, $sourcesPath] = $this->createGenerateFixture(
            ['1234.tmp'],
            ffmpegScript: $this->createZeroByteFfmpegScript()
        );

        $screenshotsPath = $sourcesPath . '/1000/1234/screenshots';
        mkdir($screenshotsPath, 0755, true);
        file_put_contents($screenshotsPath . '/2.jpg', 'old source screenshot');

        $tester->execute([
            'action' => 'regenerate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Existing screenshots were not changed.', $tester->getDisplay());
        $this->assertSame('old source screenshot', file_get_contents($screenshotsPath . '/2.jpg'));
        $this->assertFileDoesNotExist($screenshotsPath . '/1.jpg');
        $this->assertSame([], glob($sourcesPath . '/1000/1234/.screenshots-regenerate-*') ?: []);
    }

    public function testRegenerateRefusesScreenshotSymlinkWithoutDeletingItsTarget(): void
    {
        [$tester, $sourcesPath] = $this->createGenerateFixture(['1234.tmp']);

        $screenshotsPath = $sourcesPath . '/1000/1234/screenshots';
        mkdir($screenshotsPath, 0755, true);
        $outsideFile = $this->tempDir . '/outside-regenerate.jpg';
        file_put_contents($outsideFile, 'outside screenshot');
        $this->assertTrue(symlink($outsideFile, $screenshotsPath . '/1.jpg'));

        $tester->execute([
            'action' => 'regenerate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('symbolic link', $tester->getDisplay());
        $this->assertSame('outside screenshot', file_get_contents($outsideFile));
        $this->assertTrue(is_link($screenshotsPath . '/1.jpg'));
        $this->assertSame([], glob($sourcesPath . '/1000/1234/.screenshots-*') ?: []);
    }

    public function testRegenerateRefusesSymlinkedScreenshotsDirectoryWithoutChangingItsTarget(): void
    {
        [$tester, $sourcesPath] = $this->createGenerateFixture(['1234.tmp']);

        $outsideDirectory = $this->tempDir . '/outside-screenshots';
        mkdir($outsideDirectory, 0755, true);
        file_put_contents($outsideDirectory . '/1.jpg', 'outside screenshot');
        $this->assertTrue(symlink($outsideDirectory, $sourcesPath . '/1000/1234/screenshots'));

        $tester->execute([
            'action' => 'regenerate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('symbolic link', $tester->getDisplay());
        $this->assertSame('outside screenshot', file_get_contents($outsideDirectory . '/1.jpg'));
        $this->assertTrue(is_link($sourcesPath . '/1000/1234/screenshots'));
        $this->assertSame([], glob($sourcesPath . '/1000/1234/.screenshots-*') ?: []);
    }

    public function testRegenerateRejectsSymbolicLinkCreatedByFfmpegWithoutMovingItsTarget(): void
    {
        $outsideFile = $this->tempDir . '/outside-' . str_repeat('x', 160) . '.jpg';
        file_put_contents($outsideFile, 'outside screenshot');
        [$tester, $sourcesPath] = $this->createGenerateFixture(
            ['1234.tmp'],
            ffmpegScript: $this->createSymlinkFfmpegScript($outsideFile)
        );

        $tester->execute([
            'action' => 'regenerate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('non-regular output', $tester->getDisplay());
        $this->assertSame('outside screenshot', file_get_contents($outsideFile));
        $this->assertDirectoryDoesNotExist($sourcesPath . '/1000/1234/screenshots');
        $this->assertSame([], glob($sourcesPath . '/1000/1234/.screenshots-*') ?: []);
    }

    public function testRegenerateRestoresExistingScreenshotsWhenPublicationFails(): void
    {
        [$tester, $sourcesPath] = $this->createGenerateFixture(['1234.tmp']);

        $screenshotsPath = $sourcesPath . '/1000/1234/screenshots';
        mkdir($screenshotsPath . '/2.jpg', 0755, true);
        file_put_contents($screenshotsPath . '/2.jpg/keep.txt', 'keep');
        file_put_contents($screenshotsPath . '/3.jpg', 'old source screenshot');

        $tester->execute([
            'action' => 'regenerate',
            'video_id' => '1234',
            '--count' => '2',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Failed to replace screenshot', $tester->getDisplay());
        $this->assertSame('old source screenshot', file_get_contents($screenshotsPath . '/3.jpg'));
        $this->assertFileDoesNotExist($screenshotsPath . '/1.jpg');
        $this->assertDirectoryExists($screenshotsPath . '/2.jpg');
        $this->assertSame([], glob($sourcesPath . '/1000/1234/.screenshots-*') ?: []);
    }

    public function testRegenerateReplacesSourceScreenshotsWithoutDeletingGeneratedFormats(): void
    {
        [$ffmpeg, $ffprobe] = $this->createMockVideoTools();

        $sourcesPath = $this->tempDir . '/contents/videos_sources';
        $screenshotsPath = $this->tempDir . '/contents/videos_screenshots';
        mkdir($sourcesPath . '/1000/1234/screenshots', 0755, true);
        mkdir($screenshotsPath . '/1000/1234/320x180', 0755, true);
        file_put_contents($sourcesPath . '/1000/1234/source.mp4', 'video');
        file_put_contents($sourcesPath . '/1000/1234/screenshots/2.jpg', 'old source screenshot');
        file_put_contents($screenshotsPath . '/1000/1234/320x180/1.jpg', 'generated format');

        TestHelper::createMockSetupConfig($this->tempDir, [
            'content_path_videos_sources' => $sourcesPath,
            'content_path_videos_screenshots' => $screenshotsPath,
            'ffmpeg_path' => $ffmpeg,
            'ffprobe_path' => $ffprobe,
        ]);

        $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'regenerate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($sourcesPath . '/1000/1234/screenshots/1.jpg');
        $this->assertFileDoesNotExist($sourcesPath . '/1000/1234/screenshots/2.jpg');
        $this->assertFileExists($screenshotsPath . '/1000/1234/320x180/1.jpg');
        $this->assertFileDoesNotExist($screenshotsPath . '/1000/1234/1.jpg');
    }

    public function testRegenerateRemovesExistingAvifSourceScreenshots(): void
    {
        [$ffmpeg, $ffprobe] = $this->createMockVideoTools();

        $sourcesPath = $this->tempDir . '/contents/videos_sources';
        $screenshotsPath = $this->tempDir . '/contents/videos_screenshots';
        mkdir($sourcesPath . '/1000/1234/screenshots', 0755, true);
        file_put_contents($sourcesPath . '/1000/1234/source.mp4', 'video');
        file_put_contents($sourcesPath . '/1000/1234/screenshots/1.avif', 'old source screenshot');

        TestHelper::createMockSetupConfig($this->tempDir, [
            'content_path_videos_sources' => $sourcesPath,
            'content_path_videos_screenshots' => $screenshotsPath,
            'ffmpeg_path' => $ffmpeg,
            'ffprobe_path' => $ffprobe,
        ]);

        $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'regenerate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($sourcesPath . '/1000/1234/screenshots/1.jpg');
        $this->assertFileDoesNotExist($sourcesPath . '/1000/1234/screenshots/1.avif');
        $this->assertFileDoesNotExist($screenshotsPath . '/1000/1234/1.jpg');
    }

    public function testRegenerateKeepsExistingScreenshotsWhenDurationProbeFails(): void
    {
        [$ffmpeg, $ffprobe] = $this->createMockVideoTools(
            ffprobeScript: "#!/bin/sh\nexit 1\n"
        );

        $sourcesPath = $this->tempDir . '/contents/videos_sources';
        $screenshotsPath = $this->tempDir . '/contents/videos_screenshots';
        mkdir($sourcesPath . '/1000/1234/screenshots', 0755, true);
        file_put_contents($sourcesPath . '/1000/1234/source.mp4', 'video');
        file_put_contents($sourcesPath . '/1000/1234/screenshots/2.jpg', 'old source screenshot');

        TestHelper::createMockSetupConfig($this->tempDir, [
            'content_path_videos_sources' => $sourcesPath,
            'content_path_videos_screenshots' => $screenshotsPath,
            'ffmpeg_path' => $ffmpeg,
            'ffprobe_path' => $ffprobe,
        ]);

        $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'regenerate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($sourcesPath . '/1000/1234/screenshots/2.jpg');
        $this->assertFileDoesNotExist($sourcesPath . '/1000/1234/screenshots/1.jpg');
    }

    public function testRegenerateKeepsExistingScreenshotsWhenGenerationFails(): void
    {
        [$ffmpeg, $ffprobe] = $this->createMockVideoTools(
            ffmpegScript: "#!/bin/sh\nexit 1\n"
        );

        $sourcesPath = $this->tempDir . '/contents/videos_sources';
        $screenshotsPath = $this->tempDir . '/contents/videos_screenshots';
        mkdir($sourcesPath . '/1000/1234/screenshots', 0755, true);
        file_put_contents($sourcesPath . '/1000/1234/source.mp4', 'video');
        file_put_contents($sourcesPath . '/1000/1234/screenshots/2.jpg', 'old source screenshot');

        TestHelper::createMockSetupConfig($this->tempDir, [
            'content_path_videos_sources' => $sourcesPath,
            'content_path_videos_screenshots' => $screenshotsPath,
            'ffmpeg_path' => $ffmpeg,
            'ffprobe_path' => $ffprobe,
        ]);

        $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));
        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'regenerate',
            'video_id' => '1234',
            '--count' => '1',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Existing screenshots were not changed.', $tester->getDisplay());
        $this->assertFileExists($sourcesPath . '/1000/1234/screenshots/2.jpg');
        $this->assertFileDoesNotExist($sourcesPath . '/1000/1234/screenshots/1.jpg');
        $this->assertSame([], glob($sourcesPath . '/1000/1234/.screenshots-regenerate-*') ?: []);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createMockVideoTools(?string $ffmpegScript = null, ?string $ffprobeScript = null): array
    {
        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);

        $ffprobe = $toolsDir . '/ffprobe';
        file_put_contents($ffprobe, $ffprobeScript ?? "#!/bin/sh\necho '12.0'\n");
        chmod($ffprobe, 0755);

        $ffmpeg = $toolsDir . '/ffmpeg';
        file_put_contents(
            $ffmpeg,
            $ffmpegScript ?? <<<'SH'
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

        return [$ffmpeg, $ffprobe];
    }

    /**
     * @param list<string> $sourceFilenames
     * @return array{0: CommandTester, 1: string}
     */
    private function createGenerateFixture(
        array $sourceFilenames,
        ?string $ffprobeScript = null,
        ?string $ffmpegScript = null
    ): array {
        [$ffmpeg, $ffprobe] = $this->createMockVideoTools($ffmpegScript, $ffprobeScript);

        $sourcesPath = $this->tempDir . '/contents/videos_sources';
        $videoPath = $sourcesPath . '/1000/1234';
        mkdir($videoPath, 0755, true);
        foreach ($sourceFilenames as $sourceFilename) {
            file_put_contents($videoPath . '/' . $sourceFilename, 'video');
        }

        TestHelper::createMockSetupConfig($this->tempDir, [
            'content_path_videos_sources' => $sourcesPath,
            'content_path_videos_screenshots' => $this->tempDir . '/contents/videos_screenshots',
            'ffmpeg_path' => $ffmpeg,
            'ffprobe_path' => $ffprobe,
        ]);

        $command = new ScreenshotsCommand(new Configuration(['path' => $this->tempDir]));

        return [new CommandTester($command), $sourcesPath];
    }

    private function createZeroByteFfmpegScript(): string
    {
        return <<<'SH'
#!/bin/sh
previous=''
for arg in "$@"; do
  if [ "$arg" = '-y' ]; then
    : > "$previous"
    exit 0
  fi
  previous="$arg"
done
exit 1
SH;
    }

    private function createSymlinkFfmpegScript(string $target): string
    {
        return sprintf(
            <<<'SH'
#!/bin/sh
previous=''
for arg in "$@"; do
  if [ "$arg" = '-y' ]; then
    ln -s -- %s "$previous"
    exit 0
  fi
  previous="$arg"
done
exit 1
SH,
            escapeshellarg($target)
        );
    }
}
