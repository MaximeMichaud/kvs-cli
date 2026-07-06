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
            '(dev-debug.md)',
            '(dev-log.md)',
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

    public function testCheckDocumentationMatchesJsonSurface(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $checkDoc = file_get_contents($docsRoot . '/commands/system_check.md');
        $this->assertIsString($checkDoc);

        $this->assertStringContainsString('| `--format=FORMAT` | Output format: `table`, `json` |', $checkDoc);
        $this->assertStringContainsString('| `--skip-online-checks` | Skip outbound network checks |', $checkDoc);
        $this->assertStringContainsString('"results": {', $checkDoc);
        $this->assertStringContainsString('"summary": {', $checkDoc);
        $this->assertStringContainsString('kvs check --format=json --skip-online-checks', $checkDoc);
        $this->assertStringContainsString('(dev_debug.md)', $checkDoc);

        $this->assertStringNotContainsString('"checks": [', $checkDoc);
        $this->assertStringNotContainsString('"passed": 11', $checkDoc);
        $this->assertStringNotContainsString('(dev-debug.md)', $checkDoc);

        $devDebugDoc = file_get_contents($docsRoot . '/commands/dev_debug.md');
        $this->assertIsString($devDebugDoc);
        $this->assertStringContainsString('(dev_log.md)', $devDebugDoc);
        $this->assertStringNotContainsString('(dev-log.md)', $devDebugDoc);

        $devLogDoc = file_get_contents($docsRoot . '/commands/dev_log.md');
        $this->assertIsString($devLogDoc);
        $this->assertStringContainsString('(dev_debug.md)', $devLogDoc);
        $this->assertStringNotContainsString('(dev-debug.md)', $devLogDoc);
    }

    public function testPluginDocumentationUsesCurrentFieldsActionsAndAliases(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $pluginDoc = file_get_contents($docsRoot . '/commands/plugin.md');
        $this->assertIsString($pluginDoc);

        $this->assertStringContainsString('kvs plugin [<action>] [<id>] [options]', $pluginDoc);
        $this->assertStringContainsString('### show', $pluginDoc);
        $this->assertStringContainsString('### path', $pluginDoc);
        $this->assertStringContainsString('### status', $pluginDoc);
        $this->assertStringContainsString('| `--status=<status>` | all | Filter by status: `active`, `inactive`, `all` |', $pluginDoc);
        $this->assertStringContainsString('| `--type=<type>` | - | Filter by type: `manual`, `cron`, `api`, `process_object` |', $pluginDoc);
        $this->assertStringContainsString('| `--field=<field>` | - | Display a single field value |', $pluginDoc);
        $this->assertStringContainsString('- `id` - Plugin ID', $pluginDoc);
        $this->assertStringContainsString('- `enabled`', $pluginDoc);
        $this->assertStringContainsString('- `path`', $pluginDoc);
        $this->assertStringContainsString('kvs plugin list --fields=id,name,version,status', $pluginDoc);
        $this->assertStringContainsString('kvs plugin list --field=path', $pluginDoc);
        $this->assertStringContainsString('kvs plugin show backup', $pluginDoc);
        $this->assertStringContainsString('kvs plugin path backup', $pluginDoc);
        $this->assertStringContainsString('kvs plugin status', $pluginDoc);
        $this->assertStringContainsString('- `kvs plug`', $pluginDoc);

        $this->assertStringNotContainsString('- `plugin_id` - Plugin ID', $pluginDoc);
        $this->assertStringNotContainsString('kvs plugin list --fields=name,version', $pluginDoc);
        $this->assertStringNotContainsString('kvs plugin list --field=path --format=ids', $pluginDoc);
    }

    public function testVideoFormatsDocumentationUsesCurrentFields(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $formatsDoc = file_get_contents($docsRoot . '/commands/video_formats.md');
        $this->assertIsString($formatsDoc);

        $this->assertStringContainsString('Inspect video format files and configured KVS video formats.', $formatsDoc);
        $this->assertStringContainsString('### list', $formatsDoc);
        $this->assertStringContainsString('- `format`', $formatsDoc);
        $this->assertStringContainsString('- `postfix`', $formatsDoc);
        $this->assertStringContainsString('- `file`', $formatsDoc);
        $this->assertStringContainsString('- `size`', $formatsDoc);
        $this->assertStringContainsString('- `dimensions`', $formatsDoc);
        $this->assertStringContainsString('- `path`', $formatsDoc);
        $this->assertStringContainsString('- `status`', $formatsDoc);
        $this->assertStringContainsString('- `format_id`', $formatsDoc);
        $this->assertStringContainsString('- `group_id`', $formatsDoc);
        $this->assertStringContainsString('- `access`', $formatsDoc);
        $this->assertStringContainsString(
            'kvs video:formats list 123 --fields=format,postfix,file,size,dimensions --format=json',
            $formatsDoc
        );
        $this->assertStringContainsString(
            'kvs video:formats check 123 --fields=format,postfix,status,file,size,dimensions --format=json',
            $formatsDoc
        );
        $this->assertStringContainsString(
            'kvs video:formats available --fields=format_id,title,postfix,status,group_id,access --format=json',
            $formatsDoc
        );

        $this->assertStringNotContainsString('Resolution', $formatsDoc);
        $this->assertStringNotContainsString('Bitrate', $formatsDoc);
        $this->assertStringNotContainsString('Expected', $formatsDoc);
        $this->assertStringNotContainsString('Actual', $formatsDoc);
    }

    public function testPlaylistDocumentationUsesCurrentActionsAndFilters(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $playlistDoc = file_get_contents($docsRoot . '/commands/playlist.md');
        $this->assertIsString($playlistDoc);

        $this->assertStringContainsString('`list`, `show`, `create`, `add`, `remove`, `delete`', $playlistDoc);
        $this->assertStringContainsString('The `create`, `add`, `remove`, and `delete` actions modify playlist data.', $playlistDoc);
        $this->assertStringContainsString('| `--category=CATEGORY` | - | Filter by category ID or title |', $playlistDoc);
        $this->assertStringContainsString('| `--tag=TAG` | - | Filter by tag ID or name |', $playlistDoc);
        $this->assertStringContainsString('| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/videos` |', $playlistDoc);
        $this->assertStringContainsString('| `--flag=FLAG` | - | Filter by flag ID |', $playlistDoc);
        $this->assertStringContainsString('| `--flag-votes=VOTES` | 1 | Minimum flag votes for `--flag` |', $playlistDoc);
        $this->assertStringContainsString('| `--review-needed` | - | Show only playlists that need review |', $playlistDoc);
        $this->assertStringContainsString('| `--not-review-needed` | - | Show only playlists that do not need review |', $playlistDoc);
        $this->assertStringContainsString('| `--locked` | - | Show only locked playlists |', $playlistDoc);
        $this->assertStringContainsString('| `--unlocked` | - | Show only unlocked playlists |', $playlistDoc);
        $this->assertStringContainsString('| `--title=TITLE` | - | Playlist title for `create` |', $playlistDoc);
        $this->assertStringContainsString('| `--description=DESCRIPTION` | - | Playlist description for `create` |', $playlistDoc);
        $this->assertStringContainsString('| `--dir=DIR` | - | Playlist directory slug for `create` |', $playlistDoc);
        $this->assertStringContainsString('| `--field=FIELD` | - | Display a single field value |', $playlistDoc);
        $this->assertStringContainsString('| `--video=VIDEO` | - | Video ID, required for `add` and `remove` |', $playlistDoc);
        $this->assertStringContainsString('kvs playlist create "Favorites" --user=1 --private', $playlistDoc);
        $this->assertStringContainsString('kvs playlist add 1 --video=42', $playlistDoc);
        $this->assertStringContainsString('kvs playlist remove 1 --video=42', $playlistDoc);
        $this->assertStringContainsString('kvs playlist list --fields=playlist_id,title,videos_amount,playlist_viewed --format=json', $playlistDoc);
        $this->assertStringContainsString('- `filled/videos`', $playlistDoc);

        $this->assertStringNotContainsString('list, view, and delete user playlists', $playlistDoc);
        $this->assertStringNotContainsString('Action: `list`, `show`, `delete`', $playlistDoc);
        $this->assertStringNotContainsString('Search in titles and descriptions', $playlistDoc);
    }

    public function testModelAndDvdDocumentationUsesCurrentActionsAliasesAndFilters(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';

        $modelDoc = file_get_contents($docsRoot . '/commands/model.md');
        $this->assertIsString($modelDoc);
        $this->assertStringContainsString('Action: `list`, `show`, `stats` (default: `list`)', $modelDoc);
        $this->assertStringContainsString('| `--group=GROUP` | - | Filter by model group ID or title |', $modelDoc);
        $this->assertStringContainsString('| `--model-group=GROUP` | - | Filter by model group ID or title |', $modelDoc);
        $this->assertStringContainsString('| `--tag=TAG` | - | Filter by tag ID or name |', $modelDoc);
        $this->assertStringContainsString('| `--category=CATEGORY` | - | Filter by category ID or title |', $modelDoc);
        $this->assertStringContainsString('| `--usage=USAGE` | - | KVS admin usage filter, such as `used/videos` |', $modelDoc);
        $this->assertStringContainsString('| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/description` |', $modelDoc);
        $this->assertStringContainsString('| `--field=FIELD` | - | Display a single field value |', $modelDoc);
        $this->assertStringContainsString('kvs model stats', $modelDoc);
        $this->assertStringContainsString('kvs model list --fields=model_id,title,videos_amount,albums_amount --format=json', $modelDoc);
        $this->assertStringContainsString('- `kvs performer`', $modelDoc);
        $this->assertStringContainsString('- `kvs performers`', $modelDoc);
        $this->assertStringContainsString('- `used/all`', $modelDoc);
        $this->assertStringContainsString('`filled/tags`', $modelDoc);
        $this->assertStringNotContainsString('The `model` command allows you to list and view model/performer profiles.', $modelDoc);
        $this->assertStringNotContainsString('| `total_videos` | Number of videos |', $modelDoc);

        $dvdDoc = file_get_contents($docsRoot . '/commands/dvd.md');
        $this->assertIsString($dvdDoc);
        $this->assertStringContainsString('Action: `list`, `show`, `stats` (default: `list`)', $dvdDoc);
        $this->assertStringContainsString('| `--group=GROUP` | - | Filter by DVD group ID or title |', $dvdDoc);
        $this->assertStringContainsString('| `--dvd-group=GROUP` | - | Filter by DVD group ID or title |', $dvdDoc);
        $this->assertStringContainsString('| `--model=MODEL` | - | Filter by model ID or title |', $dvdDoc);
        $this->assertStringContainsString('| `--usage=USAGE` | - | KVS admin usage filter (`used/videos`, `notused/videos`) |', $dvdDoc);
        $this->assertStringContainsString('| `--review-needed` | - | Show only DVDs that need review |', $dvdDoc);
        $this->assertStringContainsString('| `--flag=FLAG` | - | Filter by user flag ID |', $dvdDoc);
        $this->assertStringContainsString('| `--field=FIELD` | - | Display a single field value |', $dvdDoc);
        $this->assertStringContainsString('kvs dvd stats', $dvdDoc);
        $this->assertStringContainsString('kvs dvd list --fields=dvd_id,title,total_videos,dvd_viewed --format=json', $dvdDoc);
        $this->assertStringContainsString('- `kvs channel`', $dvdDoc);
        $this->assertStringContainsString('- `kvs channels`', $dvdDoc);
        $this->assertStringContainsString('- `notused/videos`', $dvdDoc);
        $this->assertStringContainsString('`filled/tags`', $dvdDoc);
        $this->assertStringNotContainsString('The `dvd` command allows you to list and view DVD or channel content.', $dvdDoc);
        $this->assertStringNotContainsString('| `total_videos` | Number of videos |', $dvdDoc);
    }
}
