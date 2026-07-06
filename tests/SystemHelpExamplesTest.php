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

    public function testCommandReferenceUsesCurrentAliasesAndNamedStatuses(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $commandsReadme = file_get_contents($docsRoot . '/commands/README.md');
        $this->assertIsString($commandsReadme);

        $this->assertStringContainsString('| [`album`](album.md) | `albums`, `gallery`, `content:album` |', $commandsReadme);
        $this->assertStringContainsString('| [`user`](user.md) | `users`, `member`, `members`, `content:user` |', $commandsReadme);
        $this->assertStringContainsString('| [`category`](category.md) | `categories`, `cat`, `content:category` |', $commandsReadme);
        $this->assertStringContainsString(
            '| [`model`](model.md) | `models`, `performer`, `performers`, `content:model` |',
            $commandsReadme
        );
        $this->assertStringContainsString('| [`dvd`](dvd.md) | `dvds`, `channel`, `channels`, `content:dvd` |', $commandsReadme);
        $this->assertStringContainsString('| [`plugin`](plugin.md) | `plugins`, `plug` |', $commandsReadme);
        $this->assertStringContainsString(
            '--status=STATUS       Filter by status name or ID, when the command supports it',
            $commandsReadme
        );
        $this->assertStringContainsString('- `processing` / `3` - Processing', $commandsReadme);
        $this->assertStringContainsString('- `premium` / `3` - Premium', $commandsReadme);
        $this->assertStringContainsString('- `disabled`, `inactive` / `0` - Disabled', $commandsReadme);

        $this->assertStringNotContainsString('| [`album`](album.md) | `albums`, `content:album` |', $commandsReadme);
        $this->assertStringNotContainsString('| [`plugin`](plugin.md) | `plugins` |', $commandsReadme);
        $this->assertStringNotContainsString('--status=ID           Filter by status ID', $commandsReadme);
        $this->assertStringNotContainsString('- `2` - Error (red)', $commandsReadme);
    }

    public function testTopLevelReadmesUseCurrentCommandSurface(): void
    {
        $rootReadme = file_get_contents(dirname(__DIR__) . '/README.md');
        $this->assertIsString($rootReadme);
        $this->assertStringContainsString('kvs video stats', $rootReadme);
        $this->assertStringContainsString('kvs user stats', $rootReadme);
        $this->assertStringContainsString('kvs model stats', $rootReadme);
        $this->assertStringContainsString('kvs dvd stats', $rootReadme);
        $this->assertStringContainsString('kvs playlist list', $rootReadme);
        $this->assertStringContainsString('Content list commands and many read-only list commands', $rootReadme);
        $this->assertStringContainsString('Check `kvs <command> --help` for the exact formats accepted', $rootReadme);
        $this->assertStringNotContainsString('All list commands support multiple output formats:', $rootReadme);

        $docsReadme = file_get_contents(dirname(__DIR__) . '/docs/README.md');
        $this->assertIsString($docsReadme);
        $this->assertStringContainsString('| [`video`](commands/video.md) | Manage videos |', $docsReadme);
        $this->assertStringContainsString('| [`user`](commands/user.md) | Manage users and user statistics |', $docsReadme);
        $this->assertStringContainsString('| [`user:purge`](commands/user_purge.md) | Bulk delete users |', $docsReadme);
        $this->assertStringContainsString('| [`playlist`](commands/playlist.md) | Manage playlists |', $docsReadme);
        $this->assertStringContainsString('| [`system:queue`](commands/queue.md) | Manage background task queue |', $docsReadme);
        $this->assertStringContainsString('| [`plugin`](commands/plugin.md) | Inspect plugins |', $docsReadme);
        $this->assertStringContainsString('Some content list commands also support `--format=ids`.', $docsReadme);
        $this->assertStringNotContainsString('| [`video`](commands/video.md) | Manage videos (list, show) |', $docsReadme);
    }

    public function testMigrateToDockerDocumentationUsesCompleteNonInteractiveExamples(): void
    {
        $doc = file_get_contents(dirname(__DIR__) . '/docs/commands/migrate_to_docker.md');
        $this->assertIsString($doc);

        $this->assertStringContainsString('--email=admin@example.com', $doc);
        $this->assertStringContainsString('-e admin@example.com', $doc);
        $this->assertStringContainsString('--dry-run', $doc);
        $this->assertStringNotContainsString('kvs migrate:to-docker --domain=example.com --dry-run', $doc);
        $this->assertStringNotContainsString('kvs migrate:to-docker --ssl=3', $doc);
        $this->assertStringNotContainsString('kvs migrate:to-docker --no-content', $doc);
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

    public function testVideoDocumentationUsesCurrentActionsAliasesAndFilters(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $videoDoc = file_get_contents($docsRoot . '/commands/video.md');
        $this->assertIsString($videoDoc);

        $this->assertStringContainsString('Action: `list`, `show`, `delete`, `stats` (default: `list`)', $videoDoc);
        $this->assertStringContainsString('The `delete` action modifies video data and uses KVS native cleanup.', $videoDoc);
        $this->assertStringContainsString(
            '| `--status=STATUS` | - | Filter by status (`active`, `disabled`, `error`, `processing`, `deleting`, `deleted`) |',
            $videoDoc
        );
        $this->assertStringContainsString('| `--content-source=SOURCE` | - | Filter by content source ID or title |', $videoDoc);
        $this->assertStringContainsString('| `--content-source-group=GROUP` | - | Filter by content source group ID or title |', $videoDoc);
        $this->assertStringContainsString('| `--dvd=DVD` | - | Filter by DVD ID or title |', $videoDoc);
        $this->assertStringContainsString('| `--dvd-group=GROUP` | - | Filter by DVD group ID or title |', $videoDoc);
        $this->assertStringContainsString('| `--playlist=PLAYLIST` | - | Filter by playlist ID or title |', $videoDoc);
        $this->assertStringContainsString('| `--admin-user=ADMIN` | - | Filter by admin user ID or login |', $videoDoc);
        $this->assertStringContainsString('| `--server-group=GROUP` | - | Filter by storage server group ID or title |', $videoDoc);
        $this->assertStringContainsString('| `--format-video-group=GROUP` | - | Filter by video format group ID or title |', $videoDoc);
        $this->assertStringContainsString('| `--review-needed` | - | Show only videos that need review |', $videoDoc);
        $this->assertStringContainsString(
            '| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/tags` |',
            $videoDoc
        );
        $this->assertStringContainsString('| `--duration-from=SECONDS` | - | Filter by minimum duration in seconds |', $videoDoc);
        $this->assertStringContainsString('| `--field=FIELD` | - | Display a single field value |', $videoDoc);
        $this->assertStringContainsString('kvs video stats', $videoDoc);
        $this->assertStringContainsString('kvs video delete 123', $videoDoc);
        $this->assertStringContainsString(
            'kvs video list --fields=video_id,title,duration,video_viewed,status_id --format=json',
            $videoDoc
        );
        $this->assertStringContainsString('- `kvs videos`', $videoDoc);
        $this->assertStringContainsString('- `kvs content:video`', $videoDoc);
        $this->assertStringContainsString('`filled/tags`', $videoDoc);
        $this->assertStringContainsString('`score_processing`', $videoDoc);
        $this->assertStringContainsString('`copyright_applied`', $videoDoc);
        $this->assertStringContainsString('`wf/<postfix>`', $videoDoc);

        $this->assertStringNotContainsString('The `video` command allows you to list and view video content', $videoDoc);
        $this->assertStringNotContainsString('| `--status=<id>` | - | Filter by status (0, 1, 2) |', $videoDoc);
        $this->assertStringNotContainsString('# Videos with errors', $videoDoc);
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
        $this->assertStringContainsString('"php_cli_version": "8.2.15"', $checkDoc);
        $this->assertStringContainsString('"php_web_version": "8.2.15"', $checkDoc);
        $this->assertStringContainsString('"kvs_version": "7.0.0"', $checkDoc);
        $this->assertStringContainsString('"required_php_min": "8.1"', $checkDoc);
        $this->assertStringContainsString('"required_php_max": "8.4.99"', $checkDoc);
        $this->assertStringContainsString('Result: no errors, 2 warning(s)', $checkDoc);
        $this->assertStringContainsString('kvs check --format=json --skip-online-checks', $checkDoc);
        $this->assertStringContainsString(".results | to_entries[] | select(.value.status == \"error\")", $checkDoc);
        $this->assertStringContainsString('(dev_debug.md)', $checkDoc);

        $this->assertStringNotContainsString('"checks": [', $checkDoc);
        $this->assertStringNotContainsString(".checks[]", $checkDoc);
        $this->assertStringNotContainsString('"current": "8.2.15"', $checkDoc);
        $this->assertStringNotContainsString('"required_min": "8.1"', $checkDoc);
        $this->assertStringNotContainsString('"message": "PHP 8.2.15"', $checkDoc);
        $this->assertStringNotContainsString('"passed": 11', $checkDoc);
        $this->assertStringNotContainsString('Summary: 11 passed', $checkDoc);
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

    public function testEmailDocumentationDescribesCliTestLimitationsAndJsonMetadata(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $emailDoc = file_get_contents($docsRoot . '/commands/system_email.md');
        $this->assertIsString($emailDoc);

        $this->assertStringContainsString('Send a test email through PHP `mail()`.', $emailDoc);
        $this->assertStringContainsString('The CLI test action does not send SMTP test emails.', $emailDoc);
        $this->assertStringContainsString('Use the KVS admin panel to', $emailDoc);
        $this->assertStringContainsString('test SMTP delivery.', $emailDoc);
        $this->assertStringContainsString(
            '`test_email`, `test_subject`, and `test_body`. `kvs email set` does not write',
            $emailDoc
        );
        $this->assertStringContainsString('# Basic PHP mail() test', $emailDoc);

        $this->assertStringNotContainsString('Use `test` action to verify settings before enabling', $emailDoc);
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

    public function testSettingsVideoFormatDocumentationUsesCurrentReadOnlySurface(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';
        $formatSettingsDoc = file_get_contents($docsRoot . '/commands/settings_video_format.md');
        $this->assertIsString($formatSettingsDoc);

        $this->assertStringContainsString('Inspect KVS video format configurations.', $formatSettingsDoc);
        $this->assertStringContainsString('| `--search=TEXT` | - | Search in title, postfix, and FFmpeg options |', $formatSettingsDoc);
        $this->assertStringContainsString('kvs video-format list --search=mp4', $formatSettingsDoc);
        $this->assertStringContainsString('- `format_video_id`', $formatSettingsDoc);
        $this->assertStringContainsString('- `postfix`', $formatSettingsDoc);
        $this->assertStringContainsString('- `status_id`', $formatSettingsDoc);
        $this->assertStringContainsString('- `size`', $formatSettingsDoc);
        $this->assertStringContainsString('- `videos_count`', $formatSettingsDoc);
        $this->assertStringContainsString('- `ffmpeg_options`', $formatSettingsDoc);
        $this->assertStringContainsString('- `format_count`', $formatSettingsDoc);
        $this->assertStringContainsString(
            'kvs video-format list --fields=format_video_id,title,postfix,status,size,access,videos_count --format=json',
            $formatSettingsDoc
        );
        $this->assertStringContainsString('It does not create, update, or delete KVS video formats.', $formatSettingsDoc);

        $this->assertStringNotContainsString('Resolution      1920x1080', $formatSettingsDoc);
        $this->assertStringNotContainsString('Bitrate         5000 kbps', $formatSettingsDoc);
        $this->assertStringNotContainsString('Changes to format configuration affect future conversions only', $formatSettingsDoc);
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

    public function testAlbumAndUserDocumentationUsesCurrentActionsAliasesAndFilters(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';

        $albumDoc = file_get_contents($docsRoot . '/commands/album.md');
        $this->assertIsString($albumDoc);
        $this->assertStringContainsString('Action: `list`, `show`, `delete` (default: `list`)', $albumDoc);
        $this->assertStringContainsString('The `delete` action modifies album data and uses KVS native cleanup.', $albumDoc);
        $this->assertStringContainsString('| `--content-source=SOURCE` | - | Filter by content source ID or title |', $albumDoc);
        $this->assertStringContainsString('| `--content-source-group=GROUP` | - | Filter by content source group ID or title |', $albumDoc);
        $this->assertStringContainsString('| `--review-needed` | - | Show only albums that need review |', $albumDoc);
        $this->assertStringContainsString('| `--locked` | - | Show only locked albums |', $albumDoc);
        $this->assertStringContainsString('| `--field=FIELD` | - | Display a single field value |', $albumDoc);
        $this->assertStringContainsString('kvs album delete <id>', $albumDoc);
        $this->assertStringContainsString('kvs album list --fields=album_id,title,photos_amount,album_viewed --format=json', $albumDoc);
        $this->assertStringContainsString('- `kvs gallery`', $albumDoc);
        $this->assertStringContainsString('`filled/tags`', $albumDoc);
        $this->assertStringNotContainsString('The `album` command allows you to list and view photo album content', $albumDoc);
        $this->assertStringNotContainsString('| `--status=<id>` | - | Filter by status (0, 1) |', $albumDoc);

        $userDoc = file_get_contents($docsRoot . '/commands/user.md');
        $this->assertIsString($userDoc);
        $this->assertStringContainsString('Action: `list`, `show`, `create`, `delete`, `stats` (default: `list`)', $userDoc);
        $this->assertStringContainsString('The `create` and `delete` actions modify user data.', $userDoc);
        $this->assertStringContainsString('| `--activity=ACTIVITY` | - | Filter by KVS admin activity bucket |', $userDoc);
        $this->assertStringContainsString('| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/avatar` |', $userDoc);
        $this->assertStringContainsString(
            '| `--banned-status=STATUS` | - | Filter by login protection status (`temporary`, `permanent`, `1`, `2`) |',
            $userDoc
        );
        $this->assertStringContainsString('| `-y, --yes` | - | Skip confirmation prompt for `delete` |', $userDoc);
        $this->assertStringContainsString('kvs user stats', $userDoc);
        $this->assertStringContainsString('kvs user delete 123 --yes', $userDoc);
        $this->assertStringContainsString('kvs user list --fields=user_id,username,status_id,videos_count,albums_count --format=json', $userDoc);
        $this->assertStringContainsString('- `kvs member`', $userDoc);
        $this->assertStringContainsString('- `kvs members`', $userDoc);
        $this->assertStringContainsString('- `have/logins`', $userDoc);
        $this->assertStringContainsString('`filled/avatar`', $userDoc);
        $this->assertStringContainsString('(user_purge.md)', $userDoc);
        $this->assertStringNotContainsString('The `user` command allows you to list and view user accounts', $userDoc);
        $this->assertStringNotContainsString('(user-purge.md)', $userDoc);
    }

    public function testCategoryTagAndCommentDocumentationUsesCurrentActionsAliasesAndFilters(): void
    {
        $docsRoot = dirname(__DIR__) . '/docs';

        $categoryDoc = file_get_contents($docsRoot . '/commands/category.md');
        $this->assertIsString($categoryDoc);
        $this->assertStringContainsString(
            'Action: `list`, `tree`, `show`, `create`, `delete`, `update`, `enable`, `disable`, `merge`, `assign-group`',
            $categoryDoc
        );
        $this->assertStringContainsString('| `--group=GROUP` | - | Filter by, or assign to, category group ID or title |', $categoryDoc);
        $this->assertStringContainsString('| `--usage=USAGE` | - | KVS admin usage filter, such as `used/videos` |', $categoryDoc);
        $this->assertStringContainsString(
            '| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/description` |',
            $categoryDoc
        );
        $this->assertStringContainsString('| `--field=FIELD` | - | Display a single field value |', $categoryDoc);
        $this->assertStringContainsString('kvs category tree', $categoryDoc);
        $this->assertStringContainsString('kvs category create "New Category" --group=5', $categoryDoc);
        $this->assertStringContainsString('kvs category assign-group 5 12,15,18 --dry-run', $categoryDoc);
        $this->assertStringContainsString('kvs category list --fields=category_id,title,videos_amount,albums_amount --format=json', $categoryDoc);
        $this->assertStringContainsString('- `kvs cat`', $categoryDoc);
        $this->assertStringContainsString('- `notused/all`', $categoryDoc);
        $this->assertStringContainsString('`filled/description`', $categoryDoc);
        $this->assertStringNotContainsString('| `--limit=<n>` | 20 | Maximum number of results |', $categoryDoc);

        $tagDoc = file_get_contents($docsRoot . '/commands/tag.md');
        $this->assertIsString($tagDoc);
        $this->assertStringContainsString(
            'Action: `list`, `show`, `create`, `delete`, `merge`, `update`, `enable`, `disable`, `stats`',
            $tagDoc
        );
        $this->assertStringContainsString('| `--name=NAME` | - | New tag name for `update` |', $tagDoc);
        $this->assertStringContainsString('| `--usage=USAGE` | - | KVS admin usage filter, such as `used/videos` |', $tagDoc);
        $this->assertStringContainsString('| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/synonyms` |', $tagDoc);
        $this->assertStringContainsString('| `--field=FIELD` | - | Display a single field value |', $tagDoc);
        $this->assertStringContainsString('kvs tag show 5', $tagDoc);
        $this->assertStringContainsString('kvs tag create "4K UHD"', $tagDoc);
        $this->assertStringContainsString('kvs tag stats', $tagDoc);
        $this->assertStringContainsString('kvs tag list --fields=tag_id,tag,tag_dir,videos_amount,albums_amount --format=json', $tagDoc);
        $this->assertStringContainsString('- `notused/all`', $tagDoc);
        $this->assertStringContainsString('`filled/synonyms`', $tagDoc);
        $this->assertStringNotContainsString('| `--limit=<n>` | 20 | Maximum number of results |', $tagDoc);

        $commentDoc = file_get_contents($docsRoot . '/commands/comment.md');
        $this->assertIsString($commentDoc);
        $this->assertStringContainsString(
            'Action: `list`, `pending`, `show`, `approve`, `reject`, `delete`, `stats`',
            $commentDoc
        );
        $this->assertStringContainsString('| `--content-source=ID` | - | Filter by content source ID |', $commentDoc);
        $this->assertStringContainsString('| `--object-type=TYPE` | - | Filter by KVS object type ID or alias |', $commentDoc);
        $this->assertStringContainsString('| `--approved` | - | Show only approved comments |', $commentDoc);
        $this->assertStringContainsString('| `--field=FIELD` | - | Display a single field value |', $commentDoc);
        $this->assertStringContainsString('| `-y, --yes` | - | Skip confirmation prompt for moderation actions |', $commentDoc);
        $this->assertStringContainsString('kvs comment pending', $commentDoc);
        $this->assertStringContainsString('kvs comment approve 1,2,3,4', $commentDoc);
        $this->assertStringContainsString('kvs comment delete 456 --yes', $commentDoc);
        $this->assertStringContainsString('`content-source`, `content_source`, `source`', $commentDoc);
        $this->assertStringContainsString('kvs comment list --object-type=playlist', $commentDoc);
        $this->assertStringContainsString(
            'kvs comment list --fields=comment_id,comment,object,user,username,post_type_id,object_id --format=json',
            $commentDoc
        );
        $this->assertStringNotContainsString('comments on videos and albums', $commentDoc);
        $this->assertStringNotContainsString('| `--limit=<n>` | 20 | Maximum number of results |', $commentDoc);
    }
}
