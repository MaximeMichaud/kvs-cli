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
        $this->tempDir = sys_get_temp_dir() . '/kvs-screenshots-path-test-' . uniqid();
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
}
