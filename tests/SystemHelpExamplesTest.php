<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\BackupCommand;
use KVS\CLI\Command\System\CacheCommand;
use KVS\CLI\Command\System\CheckCommand;
use KVS\CLI\Command\System\CronCommand;
use KVS\CLI\Command\System\QueueCommand;
use KVS\CLI\Command\System\StatusCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;

class SystemHelpExamplesTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTestKvsInstallation();
        $this->config = TestHelper::createTestConfiguration($this->tempDir);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->tempDir);
    }

    public function testCoreSystemCommandsDocumentExamples(): void
    {
        $commands = [
            new BackupCommand($this->config),
            new CacheCommand($this->config),
            new CheckCommand($this->config),
            new CronCommand($this->config),
            new QueueCommand($this->config),
            new StatusCommand($this->config),
        ];

        foreach ($commands as $command) {
            $this->assertStringContainsString('EXAMPLES', $command->getHelp(), (string) $command->getName());
        }
    }

    public function testBackupDocumentationDoesNotAdvertiseRestore(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $files = [
            $docsRoot . '/commands/system_backup.md',
            $docsRoot . '/commands/README.md',
            $docsRoot . '/commands/db_import.md',
            $docsRoot . '/README.md',
            dirname(__DIR__) . '/README.md',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents, $file);
            $this->assertStringNotContainsString('--restore', $contents, $file);
            $this->assertStringNotContainsString('Create/restore backups', $contents, $file);
            $this->assertStringNotContainsString('Create and restore KVS backups', $contents, $file);
        }
    }

    public function testCommandDocumentationUsesExistingMarkdownLinkNames(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $files = glob($docsRoot . '/commands/*.md');
        $this->assertIsArray($files);

        $brokenLinks = [
            '(system-backup.md)',
            '(system-cache.md)',
            '(system-check.md)',
            '(system-status.md)',
            '(db-export.md)',
            '(db-import.md)',
            '(video-formats.md)',
            '(video-screenshots.md)',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents, $file);

            foreach ($brokenLinks as $brokenLink) {
                $this->assertStringNotContainsString($brokenLink, $contents, $file);
            }
        }
    }

    public function testContentDocumentationDoesNotAdvertiseUnsupportedOffsetOption(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $files = [
            $docsRoot . '/commands/video.md',
            $docsRoot . '/commands/album.md',
            $docsRoot . '/commands/user.md',
            $docsRoot . '/commands/category.md',
            $docsRoot . '/commands/tag.md',
            $docsRoot . '/commands/comment.md',
            $docsRoot . '/commands/model.md',
            $docsRoot . '/commands/dvd.md',
            $docsRoot . '/commands/README.md',
            $docsRoot . '/README.md',
            $docsRoot . '/configuration.md',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents, $file);
            $this->assertStringNotContainsString('--offset', $contents, $file);
        }
    }

    public function testContentDocumentationUsesRealTagAndUserFieldNames(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';

        $tagDoc = file_get_contents($docsRoot . '/commands/tag.md');
        $this->assertIsString($tagDoc);
        $this->assertStringNotContainsString('`dir`', $tagDoc);
        $this->assertStringNotContainsString('`total_videos`', $tagDoc);
        $this->assertStringNotContainsString('`total_albums`', $tagDoc);
        $this->assertStringContainsString('`tag_dir`', $tagDoc);
        $this->assertStringContainsString('`videos_amount`', $tagDoc);
        $this->assertStringContainsString('`albums_amount`', $tagDoc);

        $userDoc = file_get_contents($docsRoot . '/commands/user.md');
        $this->assertIsString($userDoc);
        $this->assertStringNotContainsString('`total_videos`', $userDoc);
        $this->assertStringNotContainsString('`total_albums`', $userDoc);
        $this->assertStringContainsString('`videos_count`', $userDoc);
        $this->assertStringContainsString('`albums_count`', $userDoc);
    }

    public function testQueueDocumentationSeparatesActiveAndHistoryStatusValues(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $queueDoc = file_get_contents($docsRoot . '/commands/queue.md');
        $this->assertIsString($queueDoc);

        $this->assertStringContainsString('## Active Queue Status Values', $queueDoc);
        $this->assertStringContainsString('## History Status Values', $queueDoc);
        $this->assertStringContainsString('`completed`', $queueDoc);
        $this->assertStringContainsString('`cancelled`', $queueDoc);
        $this->assertStringContainsString('`canceled`', $queueDoc);
        $this->assertStringContainsString('`deleted`', $queueDoc);
        $this->assertStringContainsString('kvs queue history --status=completed', $queueDoc);
        $this->assertStringNotContainsString('Show completed/deleted tasks history', $queueDoc);
    }

    public function testServerAndConversionDocumentationIncludesActionAliases(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';

        $serverDoc = file_get_contents($docsRoot . '/commands/system_server.md');
        $this->assertIsString($serverDoc);
        $this->assertStringContainsString('`activate`', $serverDoc);
        $this->assertStringContainsString('`deactivate`', $serverDoc);
        $this->assertStringContainsString('kvs server activate 1', $serverDoc);
        $this->assertStringContainsString('kvs server deactivate 1', $serverDoc);

        $conversionDoc = file_get_contents($docsRoot . '/commands/system_conversion.md');
        $this->assertIsString($conversionDoc);
        $this->assertStringContainsString('`activate`', $conversionDoc);
        $this->assertStringContainsString('`deactivate`', $conversionDoc);
        $this->assertStringContainsString('kvs conversion activate 1', $conversionDoc);
        $this->assertStringContainsString('kvs conversion deactivate 1', $conversionDoc);
    }

    public function testVideoScreenshotsDocumentationMatchesCurrentCliSurface(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $screenshotsDoc = file_get_contents($docsRoot . '/commands/video_screenshots.md');
        $this->assertIsString($screenshotsDoc);

        $this->assertStringContainsString('overview screenshots', $screenshotsDoc);
        $this->assertStringContainsString('`videos.screen_amount`', $screenshotsDoc);
        $this->assertStringContainsString('`videos.screen_main`', $screenshotsDoc);
        $this->assertStringContainsString('`--fields=<fields>`', $screenshotsDoc);
        $this->assertStringContainsString('`index`', $screenshotsDoc);
        $this->assertStringContainsString('`filename`', $screenshotsDoc);
        $this->assertStringContainsString('`formats`', $screenshotsDoc);
        $this->assertStringContainsString('`is_main`', $screenshotsDoc);
        $this->assertStringContainsString('kvs video:screenshots list 123 --fields=index,filename,formats,is_main --format=json', $screenshotsDoc);
        $this->assertStringContainsString('(video_formats.md)', $screenshotsDoc);

        $this->assertStringNotContainsString('`--type=<type>`', $screenshotsDoc);
        $this->assertStringNotContainsString('Screenshot type: timeline, poster', $screenshotsDoc);
        $this->assertStringNotContainsString('#   Time      File', $screenshotsDoc);
        $this->assertStringNotContainsString('123_1_timeline.jpg', $screenshotsDoc);
        $this->assertStringNotContainsString('(video-formats.md)', $screenshotsDoc);
    }

    public function testStatsSettingsDocumentationDescribesPlayerReportingEnum(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $statsSettingsDoc = file_get_contents($docsRoot . '/commands/system_stats_settings.md');
        $this->assertIsString($statsSettingsDoc);

        $this->assertStringContainsString(
            '| `--player-reporting` | Player reporting target (0=KVS, 1=Google Analytics, 2=both) |',
            $statsSettingsDoc
        );
        $this->assertStringContainsString('kvs stats-settings set --player-reporting=0', $statsSettingsDoc);
        $this->assertStringContainsString('kvs stats-settings set --player-reporting=1', $statsSettingsDoc);
        $this->assertStringContainsString('kvs stats-settings set --player=1 --player-reporting=2', $statsSettingsDoc);
        $this->assertStringContainsString('Reporting         KVS', $statsSettingsDoc);
        $this->assertStringNotContainsString('| `--player-reporting` | Enable player reporting (0\\|1) |', $statsSettingsDoc);
        $this->assertStringNotContainsString('Reporting         Yes', $statsSettingsDoc);
    }
}
