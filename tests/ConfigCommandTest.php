<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\ConfigCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

/**
 * Tests configuration reads, writes, backups, and editor failures on isolated fixtures.
 */
class ConfigCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private ConfigCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = TestHelper::createTempDir('kvs-config-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);

        // Create config files with test data
        TestHelper::createMockDbConfig($this->tempDir);

        // Create version.php
        file_put_contents(
            $this->tempDir . '/admin/include/version.php',
            '<?php $config["project_version"] = "6.3.2";'
        );

        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php
include_once "version.php";
$config["project_name"] = "Test KVS";
$config["project_path"] = "/var/www/test";
$config["project_url"] = "https://test.com";
$config["content_path_videos_sources"] = "/var/www/test/contents/videos_sources";
$config["content_url_videos_sources"] = "https://test.com/contents/videos_sources";
$config["content_path_albums_sources"] = "/var/www/test/contents/albums_sources";
$config["content_url_albums_sources"] = "https://test.com/contents/albums_sources";
$config["php_path"] = "/usr/bin/php";
$config["ffmpeg_path"] = "/usr/bin/ffmpeg";
$config["server_type"] = "nginx";
$config["memcache_server"] = "127.0.0.1";
$config["installation_id"] = "secret-installation-id";
$config["billing_scripts_name"] = "secret-billing-script";
$config["player_license_code"] = "secret-player-license";
$config["player_lrc"] = "secret-player-lrc";
$config["cv"] = "secret-cv";
$config["ahv"] = "secret-ahv";
'
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new ConfigCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testConfigListAll(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('KVS Configuration', $output);
        $this->assertStringContainsString('6.3.2', $output); // Version value shown
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConfigGet(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'key' => 'main.project_version'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('6.3.2', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConfigGetMasksMainLicenseCode(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'key' => 'main.player_license_code',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('**********', $output);
        $this->assertStringNotContainsString('secret-player-license', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConfigGetCanShowProtectedLicenseCode(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'key' => 'main.player_license_code',
            '--show-protected' => true,
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('secret-player-license', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    /**
     * @dataProvider kvsSensitiveConfigKeyProvider
     */
    public function testConfigGetMasksKvsSensitiveKeys(string $key, string $secret): void
    {
        $this->tester->execute([
            'action' => 'get',
            'key' => $key,
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('**********', $output);
        $this->assertStringNotContainsString($secret, $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    /**
     * @dataProvider kvsSensitiveConfigKeyProvider
     */
    public function testConfigGetCanShowProtectedKvsSensitiveKeys(string $key, string $secret): void
    {
        $this->tester->execute([
            'action' => 'get',
            'key' => $key,
            '--show-protected' => true,
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString($secret, $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConfigListJsonMasksKvsSensitiveKeys(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--json' => true,
            '--file' => 'main',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('"main.cv": "**********"', $output);
        $this->assertStringContainsString('"main.ahv": "**********"', $output);
        $this->assertStringContainsString('"main.installation_id": "**********"', $output);
        $this->assertStringContainsString('"main.billing_scripts_name": "**********"', $output);
        $this->assertStringNotContainsString('secret-cv', $output);
        $this->assertStringNotContainsString('secret-ahv', $output);
        $this->assertStringNotContainsString('secret-installation-id', $output);
        $this->assertStringNotContainsString('secret-billing-script', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function kvsSensitiveConfigKeyProvider(): iterable
    {
        yield 'installation id' => ['main.installation_id', 'secret-installation-id'];
        yield 'billing script name' => ['main.billing_scripts_name', 'secret-billing-script'];
        yield 'player lrc' => ['main.player_lrc', 'secret-player-lrc'];
        yield 'cv' => ['main.cv', 'secret-cv'];
        yield 'ahv' => ['main.ahv', 'secret-ahv'];
    }

    public function testConfigGetRejectsIgnoredOptions(): void
    {
        foreach (['file', 'backup'] as $option) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                'action' => 'get',
                'key' => 'main.project_name',
                '--' . $option => $option === 'file' ? 'db' : true,
            ]);

            $this->assertSame(1, $tester->getStatusCode(), "--$option");
            $this->assertStringContainsString(
                "The get action does not support --$option",
                $tester->getDisplay()
            );
        }
    }

    public function testConfigListRejectsIgnoredBackupOption(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--backup' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString(
            'The list action does not support --backup',
            $this->tester->getDisplay()
        );
    }

    public function testConfigEditValidatesFilePathWithSpaces(): void
    {
        $tempDir = TestHelper::createTempDir('kvs config test ');
        mkdir($tempDir . '/admin/include', 0755, true);
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $previousEditor = getenv('EDITOR');
        putenv('EDITOR=true');

        try {
            $tester = new CommandTester(new ConfigCommand(new Configuration(['path' => $tempDir])));
            $tester->setInputs(['yes']);
            $tester->execute(['action' => 'edit', '--file' => 'main']);

            $output = $tester->getDisplay();
            $this->assertEquals(0, $tester->getStatusCode());
            $this->assertStringContainsString('Configuration file edited successfully', $output);
            $this->assertStringNotContainsString('Syntax error in configuration file', $output);
        } finally {
            if ($previousEditor === false) {
                putenv('EDITOR');
            } else {
                putenv('EDITOR=' . $previousEditor);
            }

            if (is_dir($tempDir)) {
                exec('rm -rf ' . escapeshellarg($tempDir));
            }
        }
    }

    public function testConfigEditSupportsEditorPathWithSpaces(): void
    {
        $tempDir = TestHelper::createTempDir('kvs editor path test ');
        mkdir($tempDir . '/admin/include', 0755, true);
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $editorPath = $tempDir . '/editor with spaces.sh';
        $markerPath = $tempDir . '/editor-called';
        file_put_contents(
            $editorPath,
            "#!/bin/sh\n" .
            'printf "%s" "$1" > ' . escapeshellarg($markerPath) . "\n"
        );
        chmod($editorPath, 0755);

        $previousEditor = getenv('EDITOR');
        putenv('EDITOR=' . $editorPath);

        try {
            $tester = new CommandTester(new ConfigCommand(new Configuration(['path' => $tempDir])));
            $tester->execute(['action' => 'edit', '--file' => 'main']);

            $output = $tester->getDisplay();
            $this->assertEquals(0, $tester->getStatusCode());
            $this->assertStringContainsString('Configuration file edited successfully', $output);
            $this->assertSame($tempDir . '/admin/include/setup.php', file_get_contents($markerPath));
        } finally {
            if ($previousEditor === false) {
                putenv('EDITOR');
            } else {
                putenv('EDITOR=' . $previousEditor);
            }

            if (is_dir($tempDir)) {
                exec('rm -rf ' . escapeshellarg($tempDir));
            }
        }
    }

    public function testConfigEditRequiresEditableFile(): void
    {
        $this->tester->execute(['action' => 'edit']);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString(
            'Config file is required for edit. Use --file=db or --file=main.',
            $this->tester->getDisplay()
        );
    }

    public function testConfigEditRejectsDerivedPathsView(): void
    {
        $this->tester->execute([
            'action' => 'edit',
            '--file' => 'paths',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('The paths view is derived from main config', $this->tester->getDisplay());
        $this->assertStringContainsString('Use --file=main.', $this->tester->getDisplay());
    }

    public function testConfigGetNonExistent(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'key' => 'nonexistent_key'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConfigSet(): void
    {
        $this->tester->execute([
            'action' => 'set',
            'key' => 'main.project_name',
            'value' => 'Updated KVS'
        ]);

        $output = $this->tester->getDisplay();
        $setupContent = (string) file_get_contents($this->tempDir . '/admin/include/setup.php');

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Configuration updated: main.project_name = Updated KVS', $output);
        $this->assertStringContainsString("\$config['project_name'] = 'Updated KVS';", $setupContent);
    }

    public function testConfigDatabaseListShowsInfo(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--file' => 'db'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Database Configuration', $output);
        $this->assertStringContainsString('127.0.0.1', $output);
        $this->assertStringContainsString('kvs_user', $output);
        $this->assertStringContainsString('kvs_test', $output);
        $this->assertStringNotContainsString('kvs_pass', $output); // Password should be hidden
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConfigInvalidAction(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Unknown config action "invalid"', $output);
        $this->assertMatchesRegularExpression('/Available actions: list, get, set,\s+edit/', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConfigListShowsAllPaths(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();
        // Should show project path in Project Configuration
        $this->assertStringContainsString('/var/www/test', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConfigListShowsProjectConfiguration(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Project Configuration', $output);
        $this->assertStringContainsString('Test KVS', $output);
        $this->assertStringContainsString('6.3.2', $output);
        $this->assertStringContainsString('/var/www/test', $output);
        $this->assertStringContainsString('https://test.com', $output);
    }

    public function testConfigListShowsToolsAndPaths(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Tools & Paths', $output);
        $this->assertStringContainsString('/usr/bin/php', $output);
        $this->assertStringContainsString('/usr/bin/ffmpeg', $output);
    }

    public function testConfigListShowsServerConfiguration(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Server Configuration', $output);
        $this->assertStringContainsString('nginx', $output);
        $this->assertStringContainsString('127.0.0.1', $output);
    }

    public function testConfigListShowsContentPathsInThreeColumns(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();

        // Should show Content Paths & URLs section
        $this->assertStringContainsString('Content Paths & URLs', $output);

        // Should show column headers
        $this->assertStringContainsString('Content Type', $output);
        $this->assertStringContainsString('Local Path', $output);
        $this->assertStringContainsString('URL', $output);

        // Should show videos data
        $this->assertStringContainsString('Videos Sources', $output);
        $this->assertStringContainsString('/var/www/test/contents/videos_sources', $output);
        $this->assertStringContainsString('https://test.com/contents/videos_sources', $output);

        // Should show albums data
        $this->assertStringContainsString('Albums Sources', $output);
        $this->assertStringContainsString('/var/www/test/contents/albums_sources', $output);
        $this->assertStringContainsString('https://test.com/contents/albums_sources', $output);
    }

    public function testConfigListShowsDatabaseConfiguration(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Database Configuration', $output);
        $this->assertStringContainsString('127.0.0.1', $output);
        $this->assertStringContainsString('kvs_user', $output);
        $this->assertStringContainsString('kvs_test', $output);
        // Password should be masked
        $this->assertStringContainsString('**********', $output);
        $this->assertStringNotContainsString('kvs_pass', $output);
    }

    public function testConfigListJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--json' => true
        ]);

        $output = $this->tester->getDisplay();

        // Remove any warnings or notes before JSON
        $lines = explode("\n", $output);
        $jsonLine = '';
        foreach ($lines as $line) {
            if (trim($line) && (str_starts_with(trim($line), '{') || $jsonLine !== '')) {
                $jsonLine .= $line;
            }
        }

        // Output should be valid JSON
        $json = json_decode($jsonLine, true);
        $this->assertIsArray($json);

        // Should contain database config
        $this->assertArrayHasKey('db.host', $json);
        // Host may include port (e.g., 127.0.0.1:3308)
        $this->assertStringStartsWith('127.0.0.1', $json['db.host']);
    }

    public function testConfigGetMainProjectVersion(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'key' => 'main.project_version'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('6.3.2', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConfigGetDatabaseHost(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'key' => 'db.host'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('127.0.0.1', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConfigListOnlyDatabase(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--file' => 'db'
        ]);

        $output = $this->tester->getDisplay();

        // Should show database config
        $this->assertStringContainsString('Database Configuration', $output);

        // Should NOT show project config
        $this->assertStringNotContainsString('Project Configuration', $output);
        $this->assertStringNotContainsString('Tools & Paths', $output);
    }

    public function testConfigListOnlyMain(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--file' => 'main'
        ]);

        $output = $this->tester->getDisplay();

        // Should show main config sections
        $this->assertStringContainsString('Project Configuration', $output);
        $this->assertStringContainsString('Tools & Paths', $output);

        // Should NOT show database config
        $this->assertStringNotContainsString('Database Configuration', $output);
    }

    public function testConfigListRejectsUnknownFile(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--file' => 'bogus',
            '--json' => true,
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Unknown config file', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConfigListPathsJsonIsNotEmpty(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--file' => 'paths',
            '--json' => true,
        ]);

        $json = json_decode($this->tester->getDisplay(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('main.project_path', $json);
        $this->assertArrayHasKey('main.content_path_videos_sources', $json);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConfigSetRequiresBothKeyAndValue(): void
    {
        // Try to set without value
        $this->tester->execute([
            'action' => 'set',
            'key' => 'db.host'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConfigSetValidatesKeyFormat(): void
    {
        // Invalid key format (no dot)
        $this->tester->execute([
            'action' => 'set',
            'key' => 'invalidkey',
            'value' => 'test'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('invalid key format', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConfigSetRejectsUnknownCategory(): void
    {
        $this->tester->execute([
            'action' => 'set',
            'key' => 'unknown.setting',
            'value' => 'test'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('unknown', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConfigSetDatabaseHost(): void
    {
        $this->tester->execute([
            'action' => 'set',
            'key' => 'db.host',
            'value' => '127.0.0.1'
        ]);

        $output = $this->tester->getDisplay();
        $dbContent = (string) file_get_contents($this->tempDir . '/admin/include/setup_db.php');

        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Configuration updated: db.host = 127.0.0.1', $output);
        $this->assertStringContainsString("define('DB_HOST','127.0.0.1')", $dbContent);
    }

    public function testConfigListWithShowProtected(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--show-protected' => true,
            '--file' => 'db'
        ]);

        $output = $this->tester->getDisplay();

        // With show-protected, password should be visible
        $this->assertStringContainsString('kvs_pass', $output);
    }

    public function testConfigGetProtectedValueHidesPassword(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'key' => 'db.pass'
        ]);

        $output = $this->tester->getDisplay();

        // Should show asterisks, not actual password
        $this->assertStringContainsString('**********', $output);
        $this->assertStringNotContainsString('kvs_pass', $output);
    }

    public function testConfigGetProtectedValueShowsWithFlag(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'key' => 'db.pass',
            '--show-protected' => true
        ]);

        $output = $this->tester->getDisplay();

        // With flag, should show actual password
        $this->assertStringContainsString('kvs_pass', $output);
    }

    public function testConfigContentPathsCombinesPathAndUrl(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();

        // Content Paths & URLs section should combine both
        // Verify Videos Sources appears once with both path and URL
        $videosCount = substr_count($output, 'Videos Sources');

        // Should appear exactly once in the three-column table
        $this->assertEquals(1, $videosCount, 'Videos Sources should appear exactly once in the table');
    }
    /**
     * @dataProvider literalConfigurationValueProvider
     */
    public function testConfigSetRoundTripsLiteralValues(string $value, mixed $expected): void
    {
        $this->tester->execute(['action' => 'set', 'key' => 'main.project_name', 'value' => $value]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame($expected, $this->loadWrittenMainConfig()['project_name']);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function literalConfigurationValueProvider(): iterable
    {
        yield 'replacement backreferences' => ['Price $1 and ${2}', 'Price $1 and ${2}'];
        yield 'quotes and backslashes' => ["A 'quoted' \\ path", "A 'quoted' \\ path"];
        yield 'semicolon and newline' => ["First; second\nthird", "First; second\nthird"];
        yield 'leading zero numeric' => ['08', 8];
        yield 'scientific number' => ['1e2', 100.0];
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'null' => ['null', null];
        yield 'empty string' => ['', ''];
    }

    public function testConfigSetCanReplaceAnExistingSemicolonString(): void
    {
        foreach (['First; semicolon', "Next; 'quoted' value", 'Final value'] as $value) {
            $this->tester->execute(['action' => 'set', 'key' => 'main.project_name', 'value' => $value]);
            $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $this->assertSame($value, $this->loadWrittenMainConfig()['project_name']);
        }
    }

    public function testConfigSetDatabaseLiteralCanBeReadAndUpdatedAgain(): void
    {
        $file = $this->tempDir . '/admin/include/setup_db.php';
        file_put_contents($file, "define('DB_CHARSET', 'initial');\n", FILE_APPEND);

        foreach (['charset-$1', "a'quote\\path;value", 'final-charset'] as $value) {
            $this->tester->execute(['action' => 'set', 'key' => 'db.charset', 'value' => $value]);
            $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $lint = new \Symfony\Component\Process\Process([PHP_BINARY, '-l', $file]);
            $this->assertSame(0, $lint->run(), $lint->getOutput() . $lint->getErrorOutput());
            $get = new CommandTester($this->command);
            $get->execute(['action' => 'get', 'key' => 'db.charset', '--json' => true]);
            $this->assertSame(0, $get->getStatusCode(), $get->getDisplay());
            $this->assertSame(['db.charset' => $value], json_decode($get->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testConfigSetFailsWithoutChangingAnUnwritableFile(): void
    {
        $file = $this->tempDir . '/admin/include/setup.php';
        $before = file_get_contents($file);
        chmod($file, 0444);
        clearstatcache(true, $file);
        if (is_writable($file)) {
            chmod($file, 0644);
            $this->markTestSkipped('The current user can write a read-only file.');
        }

        try {
            $this->tester->execute(['action' => 'set', 'key' => 'main.project_name', 'value' => 'Denied update']);
            $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $this->assertSame($before, file_get_contents($file));
            $this->assertStringNotContainsString('Configuration updated:', $this->tester->getDisplay());
        } finally {
            chmod($file, 0644);
        }
    }

    public function testConfigSetRefusesMutationWhenRequestedBackupFails(): void
    {
        $directory = $this->tempDir . '/admin/include';
        $file = $directory . '/setup.php';
        $before = file_get_contents($file);
        chmod($directory, 0555);
        clearstatcache(true, $directory);
        if (is_writable($directory)) {
            chmod($directory, 0755);
            $this->markTestSkipped('The current user can write a read-only directory.');
        }

        try {
            $this->tester->execute([
                'action' => 'set', 'key' => 'main.project_name', 'value' => 'Unbacked update', '--backup' => true,
            ]);
            $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $this->assertSame($before, file_get_contents($file));
            $this->assertSame([], glob($file . '.backup.*'));
            $this->assertStringNotContainsString('Configuration updated:', $this->tester->getDisplay());
        } finally {
            chmod($directory, 0755);
        }
    }

    /**
     * @dataProvider failingConfigurationEditorProvider
     */
    public function testConfigEditRestoresOriginalAndFailsOnEditorErrors(string $body, int $mode): void
    {
        $file = $this->tempDir . '/admin/include/setup.php';
        $before = file_get_contents($file);
        chmod($file, $mode);
        clearstatcache(true, $file);
        $editor = $this->tempDir . '/fixture-editor';
        file_put_contents($editor, "#!/bin/sh\n" . $body . "\n");
        chmod($editor, 0755);
        $previousEditor = getenv('EDITOR');
        putenv('EDITOR=' . $editor);

        try {
            $this->tester->setInputs(['yes']);
            $this->tester->execute(['action' => 'edit', '--file' => 'main']);
            $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
            clearstatcache(true, $file);
            $this->assertFileExists($file, $this->tester->getDisplay());
            $this->assertSame($before, file_get_contents($file));
            $this->assertSame($mode, fileperms($file) & 0777);
            $this->assertStringNotContainsString('Configuration file edited successfully', $this->tester->getDisplay());
        } finally {
            $previousEditor === false ? putenv('EDITOR') : putenv('EDITOR=' . $previousEditor);
            if (file_exists($file)) {
                chmod($file, 0644);
            }
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function failingConfigurationEditorProvider(): iterable
    {
        $editors = [
            'nonzero after changing file' => 'printf "%s" "<?php if (" > "$1"; echo "editor rejected file" >&2; exit 7',
            'invalid PHP with zero exit' => 'printf "%s" "<?php if (" > "$1"; exit 0',
            'removed file with nonzero exit' => 'rm -- "$1"; exit 7',
            'removed file with zero exit' => 'rm -- "$1"; exit 0',
        ];
        foreach ($editors as $label => $body) {
            foreach ([0644, 0640, 0600] as $mode) {
                yield $label . ' mode ' . decoct($mode) => [$body, $mode];
            }
        }
    }

    public function testConfigEditDoesNotLaunchEditorWhenBackupFails(): void
    {
        $directory = $this->tempDir . '/admin/include';
        $file = $directory . '/setup.php';
        $before = file_get_contents($file);
        $marker = $this->tempDir . '/editor-called';
        $editor = $this->tempDir . '/fixture-editor';
        file_put_contents($editor, '#!/bin/sh' . "\n" . 'touch ' . escapeshellarg($marker) . "\n");
        chmod($editor, 0755);
        chmod($directory, 0555);
        clearstatcache(true, $directory);
        if (is_writable($directory)) {
            chmod($directory, 0755);
            $this->markTestSkipped('The current user can write a read-only directory.');
        }
        $previousEditor = getenv('EDITOR');
        putenv('EDITOR=' . $editor);

        try {
            $this->tester->execute(['action' => 'edit', '--file' => 'main']);
            $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $this->assertSame($before, file_get_contents($file));
            $this->assertFileDoesNotExist($marker);
        } finally {
            chmod($directory, 0755);
            $previousEditor === false ? putenv('EDITOR') : putenv('EDITOR=' . $previousEditor);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadWrittenMainConfig(): array
    {
        $file = $this->tempDir . '/admin/include/setup.php';
        $lint = new \Symfony\Component\Process\Process([PHP_BINARY, '-l', $file]);
        $this->assertSame(0, $lint->run(), $lint->getOutput() . $lint->getErrorOutput());
        $previousDirectory = getcwd();
        chdir(dirname($file));
        try {
            return (static function (string $path): array {
                $config = [];
                include $path;
                return $config;
            })($file);
        } finally {
            if ($previousDirectory !== false) {
                chdir($previousDirectory);
            }
        }
    }
    /**
     * @dataProvider updatedDatabaseConnectionConfigurationProvider
     */
    public function testConfigSetTestsTheUpdatedEffectiveDatabaseConfiguration(
        string $key,
        string $value,
        string $observedKey,
        string $expected,
        bool $environmentOverride,
        bool $disableEnvironmentOverrides
    ): void {
        $previousEnvironment = [];
        foreach (['KVS_DB_HOST', 'KVS_DB_PORT', 'KVS_DB_USER', 'KVS_DB_PASS', 'KVS_DB_NAME'] as $name) {
            $previousEnvironment[$name] = getenv($name);
            putenv($name);
        }
        if ($environmentOverride) {
            putenv('KVS_DB_HOST=127.0.0.2:32124');
            putenv('KVS_DB_PORT=32125');
        }

        try {
            $configuration = new Configuration([
                'path' => $this->tempDir,
                'disable_db_env_overrides' => $disableEnvironmentOverrides,
            ]);
            $command = new class ($configuration) extends ConfigCommand {
                /** @var array<string, string> */
                public array $observedConfiguration = [];

                protected function getDatabaseConnection(bool $quiet = false): ?\PDO
                {
                    $this->observedConfiguration = $this->config->getDatabaseConfig();
                    return null;
                }
            };
            $command->setName('config');
            $application = new Application();
            $application->add($command);
            $tester = new CommandTester($command);
            $tester->execute(['action' => 'set', 'key' => 'db.' . $key, 'value' => $value]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($expected, $command->observedConfiguration[$observedKey] ?? null);
            $this->assertStringContainsString('Database connection failed', $tester->getDisplay());
            $this->assertStringNotContainsString('Database connection successful', $tester->getDisplay());
        } finally {
            foreach ($previousEnvironment as $name => $previousValue) {
                $previousValue === false ? putenv($name) : putenv($name . '=' . $previousValue);
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string, bool, bool}>
     */
    public static function updatedDatabaseConnectionConfigurationProvider(): iterable
    {
        yield 'updated host' => ['host', '127.0.0.1:32123', 'host', '127.0.0.1:32123', false, false];
        yield 'updated login' => ['login', 'fixture-new-user', 'user', 'fixture-new-user', false, false];
        yield 'updated password' => ['pass', 'fixture-new-password', 'password', 'fixture-new-password', false, false];
        yield 'updated database' => ['device', 'fixture_new_database', 'database', 'fixture_new_database', false, false];
        yield 'environment host and port override' => ['host', '127.0.0.1:32123', 'host', '127.0.0.2:32125', true, false];
        yield 'preserved disabled environment override' => ['host', '127.0.0.1:32123', 'host', '127.0.0.1:32123', true, true];
    }

    /**
     * @dataProvider configurationBackupPermissionProvider
     */
    public function testConfigBackupNeverBroadensSourcePermissions(string $category, int $mode, int $mask): void
    {
        $file = $this->tempDir . '/admin/include/' . ($category === 'db' ? 'setup_db.php' : 'setup.php');
        if ($category === 'db') {
            file_put_contents($file, "define('DB_CHARSET', 'initial');\n", FILE_APPEND);
        }
        chmod($file, $mode);
        $before = file_get_contents($file);
        $previousMask = umask($mask);

        try {
            $this->tester->execute([
                'action' => 'set',
                'key' => $category === 'db' ? 'db.charset' : 'main.project_name',
                'value' => 'Synthetic replacement',
                '--backup' => true,
            ]);
            $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $backups = glob($file . '.backup.*');
            $this->assertIsArray($backups);
            $this->assertCount(1, $backups);
            clearstatcache();
            $this->assertSame($before, file_get_contents($backups[0]));
            $this->assertFalse(is_link($backups[0]));
            $this->assertSame($mode, fileperms($file) & 0777);
            $backupMode = fileperms($backups[0]) & 0777;
            $this->assertSame(
                0,
                $backupMode & ~$mode,
                sprintf('Backup mode %04o must not grant access denied by source mode %04o.', $backupMode, $mode)
            );
        } finally {
            umask($previousMask);
            chmod($file, 0644);
        }
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function configurationBackupPermissionProvider(): iterable
    {
        foreach (['main', 'db'] as $category) {
            foreach ([[0600, 0022], [0640, 0022], [0644, 0022], [0600, 0077]] as [$mode, $mask]) {
                yield $category . ' mode ' . decoct($mode) . ' umask ' . decoct($mask) => [$category, $mode, $mask];
            }
        }
    }

    /**
     * @dataProvider configurationBackupCollisionProvider
     */
    public function testConfigBackupNeverFollowsOrOverwritesAnExistingDestination(
        string $category,
        string $collision
    ): void {
        $file = $this->tempDir . '/admin/include/' . ($category === 'db' ? 'setup_db.php' : 'setup.php');
        if ($category === 'db') {
            file_put_contents($file, "define('DB_CHARSET', 'initial');\n", FILE_APPEND);
        }
        $before = file_get_contents($file);
        $existing = [];
        $targets = [];
        $fixtureTime = time();
        if ($collision !== 'none') {
            // Cover clock ticks without stubbing ConfigCommand's real backup path selection.
            for ($offset = -2; $offset <= 60; $offset++) {
                $stamp = date('YmdHis', $fixtureTime + $offset);
                $candidate = $file . '.backup.' . $stamp;
                if ($collision === 'regular') {
                    file_put_contents($candidate, 'Existing synthetic backup');
                } else {
                    $target = $this->tempDir . '/collision-target-' . $stamp;
                    if ($collision === 'existing-link') {
                        file_put_contents($target, 'Existing synthetic target');
                    }
                    symlink($target, $candidate);
                    $targets[$candidate] = $target;
                }
                $existing[] = $candidate;
            }
        }

        $this->tester->execute([
            'action' => 'set',
            'key' => $category === 'db' ? 'db.charset' : 'main.project_name',
            'value' => 'Synthetic replacement',
            '--backup' => true,
        ]);
        $this->assertLessThanOrEqual($fixtureTime + 60, time(), 'The fixture backup collision window expired.');
        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        clearstatcache();
        foreach ($existing as $candidate) {
            if ($collision === 'regular') {
                $this->assertSame('Existing synthetic backup', file_get_contents($candidate));
                continue;
            }
            $this->assertTrue(is_link($candidate));
            $this->assertSame($targets[$candidate], readlink($candidate));
            if ($collision === 'existing-link') {
                $this->assertSame('Existing synthetic target', file_get_contents($targets[$candidate]));
            } else {
                $this->assertFileDoesNotExist($targets[$candidate], 'The backup must not follow a dangling symlink.');
            }
        }
        $backups = glob($file . '.backup.*');
        $this->assertIsArray($backups);
        $newBackups = array_values(array_diff($backups, $existing));
        $this->assertCount(1, $newBackups);
        $this->assertFalse(is_link($newBackups[0]));
        $this->assertSame($before, file_get_contents($newBackups[0]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function configurationBackupCollisionProvider(): iterable
    {
        foreach (['main', 'db'] as $category) {
            foreach (['none', 'regular', 'existing-link', 'dangling-link'] as $collision) {
                yield $category . ' ' . $collision => [$category, $collision];
            }
        }
    }

    /**
     * @dataProvider configurationHealthyLinkProvider
     */
    public function testConfigBackupPreservesHealthyConfigurationLinks(
        string $category,
        bool $relative,
        string $action
    ): void {
        $file = $this->tempDir . '/admin/include/' . ($category === 'db' ? 'setup_db.php' : 'setup.php');
        if ($category === 'db') {
            file_put_contents($file, "define('DB_CHARSET', 'initial');\n", FILE_APPEND);
        }
        $before = file_get_contents($file);
        $target = $this->tempDir . '/linked configuration.php';
        rename($file, $target);
        $link = $relative ? '../../' . basename($target) : $target;
        symlink($link, $file);
        chmod($target, 0640);
        $previousEditor = getenv('EDITOR');

        try {
            if ($action === 'edit') {
                $editor = $this->tempDir . '/fixture-editor';
                file_put_contents($editor, "#!/bin/sh\nprintf '%s' '<?php if (' > \"\$1\"\nexit 7\n");
                chmod($editor, 0700);
                putenv('EDITOR=' . $editor);
                $this->tester->execute(['action' => 'edit', '--file' => $category]);
                $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
                $this->assertSame($before, file_get_contents($target));
                $this->assertStringContainsString('Restored from backup', $this->tester->getDisplay());
            } else {
                $this->tester->execute([
                    'action' => 'set',
                    'key' => $category === 'db' ? 'db.charset' : 'main.project_name',
                    'value' => 'Synthetic replacement',
                    '--backup' => true,
                ]);
                $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
                $this->assertStringContainsString('Synthetic replacement', file_get_contents($target));
            }
            clearstatcache();
            $this->assertTrue(is_link($file));
            $this->assertSame($link, readlink($file));
            $this->assertSame(0640, fileperms($target) & 0777);
            $backups = glob($file . '.backup.*');
            $this->assertIsArray($backups);
            $this->assertCount(1, $backups);
            $this->assertFalse(is_link($backups[0]));
            $this->assertSame($before, file_get_contents($backups[0]));
            $this->assertSame(0, (fileperms($backups[0]) & 0777) & ~0640);
        } finally {
            $previousEditor === false ? putenv('EDITOR') : putenv('EDITOR=' . $previousEditor);
            chmod($target, 0644);
        }
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function configurationHealthyLinkProvider(): iterable
    {
        foreach (['main', 'db'] as $category) {
            foreach ([false, true] as $relative) {
                foreach (['set', 'edit'] as $action) {
                    yield $category . ' ' . ($relative ? 'relative' : 'absolute') . ' ' . $action
                        => [$category, $relative, $action];
                }
            }
        }
    }

    public function testConfigBackupDoesNotFallBackToTheSystemTemporaryDirectory(): void
    {
        $before = glob(sys_get_temp_dir() . '/setup.php.backup.*');
        $this->testConfigSetRefusesMutationWhenRequestedBackupFails();
        $this->assertSame($before, glob(sys_get_temp_dir() . '/setup.php.backup.*'));
    }

    public function testConfigEditRetainsBackupWhenTheEditorMakesRestorationImpossible(): void
    {
        $directory = $this->tempDir . '/admin/include';
        $file = $directory . '/setup.php';
        $before = file_get_contents($file);
        chmod($directory, 0555);
        clearstatcache(true, $directory);
        if (is_writable($directory)) {
            chmod($directory, 0755);
            $this->markTestSkipped('The current user can write a read-only directory.');
        }
        chmod($directory, 0755);
        $editor = $this->tempDir . '/fixture-editor';
        file_put_contents(
            $editor,
            '#!' . PHP_BINARY . "\n<?php unlink(\$argv[1]); chmod(dirname(\$argv[1]), 0555); exit(7);\n"
        );
        chmod($editor, 0700);
        $previousEditor = getenv('EDITOR');
        putenv('EDITOR=' . $editor);

        try {
            $this->tester->execute(['action' => 'edit', '--file' => 'main']);
            $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
            clearstatcache();
            $this->assertFileDoesNotExist($file);
            $this->assertStringContainsString('Unable to restore configuration', $this->tester->getDisplay());
            $this->assertStringNotContainsString('Restored from backup', $this->tester->getDisplay());
            $backups = glob($file . '.backup.*');
            $this->assertIsArray($backups);
            $this->assertCount(1, $backups);
            $this->assertSame($before, file_get_contents($backups[0]));
            $this->assertSame([], glob($file . '*.restore'));
        } finally {
            $previousEditor === false ? putenv('EDITOR') : putenv('EDITOR=' . $previousEditor);
            chmod($directory, 0755);
        }
    }

    /**
     * @dataProvider nonAssignmentConfigurationTextProvider
     */
    public function testConfigSetRejectsTextThatIsNotAnAssignment(string $category, string $example): void
    {
        $file = $this->tempDir . '/admin/include/' . ($category === 'db' ? 'setup_db.php' : 'setup.php');
        $initializer = $category === 'db'
            ? "const DB_CHARSET = 'Synthetic original';\n"
            : "\$config = ['project_name' => 'Synthetic original'];\n";
        $before = "<?php\n" . $initializer . $example . "\n";
        file_put_contents($file, $before);
        $this->assertSame('Synthetic original', $this->readSyntheticConfigurationValue($file, $category));

        $this->tester->execute([
            'action' => 'set',
            'key' => $category === 'db' ? 'db.charset' : 'main.project_name',
            'value' => 'Synthetic replacement',
        ]);

        $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame($before, file_get_contents($file));
        $this->assertSame('Synthetic original', $this->readSyntheticConfigurationValue($file, $category));
        $this->assertStringNotContainsString('Configuration updated:', $this->tester->getDisplay());
    }

    /**
     * @dataProvider nonAssignmentConfigurationTextProvider
     */
    public function testConfigSetPreservesExamplesWhenUpdatingRealAssignments(string $category, string $example): void
    {
        $file = $this->tempDir . '/admin/include/' . ($category === 'db' ? 'setup_db.php' : 'setup.php');
        $assignment = $category === 'db'
            ? "define('DB_CHARSET', 'Synthetic original');\n"
            : "\$config['project_name'] = 'Synthetic original';\n";
        // Initialize before comparisons; commented/string examples stay independent of the real assignment.
        file_put_contents($file, "<?php\n" . $assignment . $example . "\n");

        $this->tester->execute([
            'action' => 'set',
            'key' => $category === 'db' ? 'db.charset' : 'main.project_name',
            'value' => 'Synthetic replacement',
        ]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertStringContainsString($example, file_get_contents($file));
        $this->assertSame('Synthetic replacement', $this->readSyntheticConfigurationValue($file, $category));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonAssignmentConfigurationTextProvider(): iterable
    {
        foreach (['main', 'db'] as $category) {
            $statement = $category === 'db'
                ? "define('DB_CHARSET', 'Synthetic example');"
                : "\$config['project_name'] = 'Synthetic example';";
            yield $category . ' line comment' => [$category, '// ' . $statement];
            yield $category . ' block comment' => [$category, '/* ' . $statement . ' */'];
            yield $category . ' doc comment' => [$category, '/** ' . $statement . ' */'];
            yield $category . ' quoted example' => [
                $category,
                '$example = "' . str_replace('$', '\\$', $statement) . '";',
            ];
            yield $category . ' nowdoc example' => [$category, '$example = <<<' . "'EXAMPLE'\n" . $statement . "\nEXAMPLE;"];
        }
        yield 'main equality comparison' => ['main', "\$config['project_name'] == 'Synthetic original';"];
        yield 'main strict comparison' => ['main', "\$config['project_name'] === 'Synthetic original';"];
    }

    /**
     * @dataProvider literalConfigurationValueProvider
     */
    public function testConfigSetPreservesExamplesWhenWritingLiteralValues(string $value, mixed $expected): void
    {
        $file = $this->tempDir . '/admin/include/setup.php';
        $example = <<<'PHP'
$example = <<<'EXAMPLE'
$config['project_name'] = 'Keep this example';
EXAMPLE;
PHP;
        file_put_contents($file, "<?php\n" . $example . "\n\$config['project_name'] = 'Synthetic original';\n");

        $this->tester->execute(['action' => 'set', 'key' => 'main.project_name', 'value' => $value]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertStringContainsString($example, file_get_contents($file));
        $this->assertSame($expected, $this->readSyntheticConfigurationValue($file, 'main'));
    }

    /**
     * @dataProvider executableConfigurationAssignmentProvider
     */
    public function testConfigSetPreservesExistingExecutableAssignmentForms(string $category, string $body): void
    {
        $file = $this->tempDir . '/admin/include/' . ($category === 'db' ? 'setup_db.php' : 'setup.php');
        $sentinel = "\$sentinel = 'Keep unrelated trailing text';\n";
        file_put_contents($file, "<?php\n" . $body . "\n" . $sentinel);
        $this->assertSame('Synthetic original', $this->readSyntheticConfigurationValue($file, $category));

        $this->tester->execute([
            'action' => 'set',
            'key' => $category === 'db' ? 'db.charset' : 'main.project_name',
            'value' => 'Synthetic replacement',
        ]);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertStringContainsString($sentinel, file_get_contents($file));
        $this->assertSame('Synthetic replacement', $this->readSyntheticConfigurationValue($file, $category));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function executableConfigurationAssignmentProvider(): iterable
    {
        yield 'main single assignment' => ['main', "$" . "config['project_name'] = 'Synthetic original';"];
        yield 'main consecutive assignments' => ['main', <<<'PHP'
$config['project_name'] = 'Synthetic first';
$config['project_name'] = 'Synthetic original';
PHP];
        foreach (['true', 'false'] as $condition) {
            yield 'main conditional ' . $condition => ['main', 'if (' . $condition . ') {' . <<<'PHP'
$config['project_name'] = 'Synthetic original';
} else {
$config['project_name'] = 'Synthetic original';
}
PHP];
            yield 'db conditional ' . $condition => ['db', 'if (' . $condition . ') {' . <<<'PHP'
define('DB_CHARSET', 'Synthetic original');
} else {
define('DB_CHARSET', 'Synthetic original');
}
PHP];
        }
        yield 'main nested same-key assignment' => ['main', <<<'PHP'
$config['project_name'] = $config['project_name'] = 'Synthetic original';
PHP];
        yield 'main called function using global' => ['main', <<<'PHP'
function syntheticGlobalConfiguration(): void {
    global $config;
    $config['project_name'] = 'Synthetic original';
}
syntheticGlobalConfiguration();
PHP];
        yield 'main called reference closure' => ['main', <<<'PHP'
$config = [];
$initialize = static function () use (&$config): void {
    $config['project_name'] = 'Synthetic original';
};
$initialize();
PHP];
        yield 'main called function returning configuration' => ['main', <<<'PHP'
function syntheticReturnedConfiguration(): array {
    $config['project_name'] = 'Synthetic original';
    return $config;
}
$config = syntheticReturnedConfiguration();
PHP];
        yield 'main called function with reference parameter' => ['main', <<<'PHP'
function syntheticReferenceConfiguration(array &$config): void {
    $config['project_name'] = 'Synthetic original';
}
$config = [];
syntheticReferenceConfiguration($config);
PHP];
        yield 'db single define' => ['db', "define('DB_CHARSET', 'Synthetic original');"];
        yield 'db repeated guarded defines' => ['db', <<<'PHP'
if (!defined('DB_CHARSET')) { define('DB_CHARSET', 'Synthetic original'); }
if (!defined('DB_CHARSET')) { define('DB_CHARSET', 'Synthetic alternate'); }
PHP];
        yield 'db called function define' => ['db', <<<'PHP'
function syntheticDatabaseConfiguration(): void {
    define('DB_CHARSET', 'Synthetic original');
}
syntheticDatabaseConfiguration();
PHP];
        yield 'db called closure define' => ['db', <<<'PHP'
$initialize = static function (): void {
    define('DB_CHARSET', 'Synthetic original');
};
$initialize();
PHP];
    }

    /**
     * @dataProvider malformedConfigurationProvider
     */
    public function testConfigSetRejectsMalformedPhpWithoutChangingTheFile(string $category, string $body): void
    {
        $file = $this->tempDir . '/admin/include/' . ($category === 'db' ? 'setup_db.php' : 'setup.php');
        $before = "<?php\n" . $body . "\nif (\n";
        file_put_contents($file, $before);
        $this->tester->execute([
            'action' => 'set',
            'key' => $category === 'db' ? 'db.charset' : 'main.project_name',
            'value' => 'Synthetic replacement',
        ]);
        $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame($before, file_get_contents($file));
        $this->assertStringNotContainsString('Configuration updated:', $this->tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedConfigurationProvider(): iterable
    {
        yield 'main' => ['main', "\$config['project_name'] = 'Synthetic original';"];
        yield 'db' => ['db', "define('DB_CHARSET', 'Synthetic original');"];
    }

    private function readSyntheticConfigurationValue(string $file, string $category): mixed
    {
        $code = <<<'PHP'
$config = [];
include $argv[1];
echo json_encode($argv[2] === 'db' ? constant('DB_CHARSET') : $config['project_name'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
PHP;
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $code, $file, $category]);
        $this->assertSame(0, $process->run(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @dataProvider unsupportedDatabaseDefinitionProvider
     */
    public function testConfigSetDatabaseDoesNotExpandUnsupportedDefinitionForms(string $body): void
    {
        $file = $this->tempDir . '/admin/include/setup_db.php';
        $before = "<?php\n" . $body . "\n";
        file_put_contents($file, $before);
        $this->tester->execute(['action' => 'set', 'key' => 'db.charset', 'value' => 'Synthetic replacement']);
        $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame($before, file_get_contents($file));
        $this->assertStringNotContainsString('Configuration updated:', $this->tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedDatabaseDefinitionProvider(): iterable
    {
        yield 'double-quoted name' => ['define("DB_CHARSET", \'Synthetic original\');'];
        yield 'double-quoted value' => ['define(\'DB_CHARSET\', "Synthetic original");'];
        yield 'environment expression' => ["define('DB_CHARSET', getenv('SYNTHETIC_CHARSET') ?: 'Synthetic original');"];
        yield 'concatenated expression' => ["define('DB_CHARSET', 'Synthetic ' . 'original');"];
        yield 'constant declaration' => ["const DB_CHARSET = 'Synthetic original';"];
        yield 'third argument' => ["define('DB_CHARSET', 'Synthetic original', false);"];
        yield 'named arguments' => ["define(constant_name: 'DB_CHARSET', value: 'Synthetic original');"];
        yield 'nowdoc value' => [<<<'PHP'
define('DB_CHARSET', <<<'VALUE'
Synthetic original
VALUE
);
PHP];
    }

    public function testConfigSetDatabaseKeepsGetConsistentWithATrailingComment(): void
    {
        $file = $this->tempDir . '/admin/include/setup_db.php';
        file_put_contents(
            $file,
            "define('DB_CHARSET', 'Synthetic original');\n// define('DB_CHARSET', 'Synthetic example');\n",
            FILE_APPEND
        );
        $this->tester->execute(['action' => 'set', 'key' => 'db.charset', 'value' => 'Synthetic replacement']);
        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $get = new CommandTester($this->command);
        $get->execute(['action' => 'get', 'key' => 'db.charset', '--json' => true]);
        $this->assertSame(0, $get->getStatusCode(), $get->getDisplay());
        $this->assertSame(
            ['db.charset' => 'Synthetic replacement'],
            json_decode($get->getDisplay(), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testConfigSetTestsUpdatedDatabaseConfigurationWhenACommentPrecedesTheDefinition(): void
    {
        $file = $this->tempDir . '/admin/include/setup_db.php';
        $source = file_get_contents($file);
        $this->assertIsString($source);
        file_put_contents($file, str_replace(
            "define('DB_HOST',",
            "// define('DB_HOST', '127.0.0.2');\ndefine('DB_HOST',",
            $source
        ));
        $configuration = new Configuration(['path' => $this->tempDir, 'disable_db_env_overrides' => true]);
        $command = new class ($configuration) extends ConfigCommand {
            /** @var array<string, string> */
            public array $observedConfiguration = [];
            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                $this->observedConfiguration = $this->config->getDatabaseConfig();
                return null;
            }
        };
        $tester = new CommandTester($command);
        $tester->execute(['action' => 'set', 'key' => 'db.host', 'value' => '127.0.0.3']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('127.0.0.3', $command->observedConfiguration['host'] ?? null);
    }

    /**
     * @dataProvider listedDatabaseDefinitionProvider
     */
    public function testConfigDatabaseGetAndListSelectSyntacticDefinitions(string $body, ?string $expected): void
    {
        $file = $this->tempDir . '/admin/include/setup_db.php';
        file_put_contents($file, "<?php\n" . $body . "\n");
        foreach (['get', 'list'] as $action) {
            $tester = new CommandTester($this->command);
            $arguments = ['action' => $action, '--json' => true];
            $arguments += $action === 'get' ? ['key' => 'db.charset'] : ['--file' => 'db'];
            $tester->execute($arguments);
            if ($expected === null && $action === 'get') {
                $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
            } else {
                $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
                $values = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame($expected, $values['db.charset'] ?? null);
            }
        }
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function listedDatabaseDefinitionProvider(): iterable
    {
        $actual = "define('DB_CHARSET', 'Synthetic actual');";
        $fake = "define('DB_CHARSET', 'Synthetic example');";
        yield 'literal control' => [$actual, 'Synthetic actual'];
        yield 'line comment' => [$actual . "\n// " . $fake, 'Synthetic actual'];
        yield 'block comment' => [$actual . "\n/* " . $fake . ' */', 'Synthetic actual'];
        yield 'documentation comment' => [$actual . "\n/** " . $fake . ' */', 'Synthetic actual'];
        yield 'quoted example' => [$actual . "\n" . '$example = "' . $fake . '";', 'Synthetic actual'];
        yield 'nowdoc example' => [$actual . "\n\$example = <<<'EXAMPLE'\n" . $fake . "\nEXAMPLE;", 'Synthetic actual'];
        yield 'comment inside a real call' => ["define('DB_CHARSET', /* $fake */ 'Synthetic actual');", 'Synthetic actual'];
        yield 'method call' => [$actual . "\n\$object->" . $fake, 'Synthetic actual'];
        yield 'static call' => [$actual . "\nFixture::" . $fake, 'Synthetic actual'];
        yield 'foreign qualified function' => [$actual . "\nFixture\\" . $fake, 'Synthetic actual'];
        yield 'relative function' => [$actual . "\nnamespace\\" . $fake, 'Synthetic actual'];
        yield 'fully qualified define' => ['\\' . $actual, 'Synthetic actual'];
        yield 'simple define in a namespace' => ['namespace Fixture; ' . $actual, 'Synthetic actual'];
        yield 'fully qualified define in a namespace' => ['namespace Fixture; \\' . $actual, 'Synthetic actual'];
        yield 'function body retains syntactic selection' => ['function fixture() { ' . $actual . ' }', 'Synthetic actual'];
        yield 'last literal wins' => [$actual . "\n" . $fake, 'Synthetic example'];
        yield 'only a comment' => ['// ' . $fake, null];
        yield 'double quoted key remains unsupported' => ['define("DB_CHARSET", \'Synthetic example\');', null];
        yield 'double quoted value remains unsupported' => ['define(\'DB_CHARSET\', "Synthetic example");', null];
        yield 'environment expression remains unsupported' => [
            "define('DB_CHARSET', getenv('KVS_READER_SYNTHETIC_VALUE') ?: 'Synthetic example');", null,
        ];
        yield 'third argument remains unsupported' => ["define('DB_CHARSET', 'Synthetic example', false);", null];
        yield 'named arguments remain unsupported' => ["define(constant_name: 'DB_CHARSET', value: 'Synthetic example');", null];
        yield 'concatenation remains unsupported' => ["define('DB_CHARSET', 'Synthetic ' . 'example');", null];
    }

    public function testConfigDatabaseReaderDecodesLiteralBytes(): void
    {
        $expected = "Synthetic apostrophe's \\path; \"quote\"\nline";
        file_put_contents(
            $this->tempDir . '/admin/include/setup_db.php',
            "<?php define('DB_CHARSET', " . var_export($expected, true) . ');'
        );
        // Isolate extraction from the separate Console output formatting contract.
        $reader = new \ReflectionMethod(ConfigCommand::class, 'getDatabaseConfigs');
        $this->assertSame(['charset' => $expected], $reader->invoke($this->command));
    }

    public function testConfigSetReloadsEscapedDatabaseValueWithoutExecutingTheFile(): void
    {
        $file = $this->tempDir . '/admin/include/setup_db.php';
        $marker = $this->tempDir . '/must-not-exist';
        $expected = "Synthetic apostrophe's \\path; \"quote\"";
        file_put_contents(
            $file,
            "<?php define('DB_PASS', 'Synthetic original');\nfile_put_contents("
                . var_export($marker, true) . ", 'unexpected');"
        );
        $configuration = new Configuration(['path' => $this->tempDir, 'disable_db_env_overrides' => true]);
        $command = new class ($configuration) extends ConfigCommand {
            /** @var array<string, string> */
            public array $observedConfiguration = [];

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                $this->observedConfiguration = $this->config->getDatabaseConfig();
                return null;
            }
        };
        $tester = new CommandTester($command);
        $tester->execute(['action' => 'set', 'key' => 'db.pass', 'value' => $expected]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame($expected, $command->observedConfiguration['password'] ?? null);
        $this->assertFileDoesNotExist($marker);
    }

    public function testConfigSetPreservesPreviousReferenceAssignmentReplacement(): void
    {
        $file = $this->tempDir . '/admin/include/setup.php';
        $body = <<<'PHP_SOURCE'
<?php
$source = 'Synthetic original';
$config['project_name'] =& $source;
$source = 'Synthetic source changed';
$config['source_after'] = $source;
PHP_SOURCE;
        file_put_contents($file, $body);
        $this->tester->execute(['action' => 'set', 'key' => 'main.project_name', 'value' => 'Synthetic replacement']);
        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $actual = $this->loadWrittenMainConfig();
        $this->assertSame('Synthetic replacement', $actual['project_name']);
        $this->assertSame('Synthetic source changed', $actual['source_after']);
    }

    /**
     * @dataProvider recoverableDatabaseSyntaxErrorProvider
     */
    public function testConfigEditCanRepairRecoverableDatabaseSyntaxErrors(string $suffix): void
    {
        $file = $this->tempDir . '/admin/include/setup_db.php';
        $valid = "<?php define('DB_HOST', '127.0.0.1');";
        file_put_contents($file, $valid . "\n" . $suffix);
        $configuration = new Configuration(['path' => $this->tempDir, 'disable_db_env_overrides' => true]);
        $this->assertTrue($configuration->isKvsInstalled());
        $editor = $this->tempDir . '/repair-fixture';
        file_put_contents($editor, '#!' . PHP_BINARY . "\n<?php file_put_contents(\$argv[1], " . var_export($valid, true) . ');');
        chmod($editor, 0755);
        $previous = getenv('EDITOR');
        putenv('EDITOR=' . $editor);
        try {
            $tester = new CommandTester(new ConfigCommand($configuration));
            $tester->execute(['action' => 'edit', '--file' => 'db']);
            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame($valid, file_get_contents($file));
        } finally {
            $previous === false ? putenv('EDITOR') : putenv('EDITOR=' . $previous);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recoverableDatabaseSyntaxErrorProvider(): iterable
    {
        yield 'incomplete condition' => ['if ('];
        yield 'invalid statement' => ['invalid syntax;'];
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function directConfigPayloads(): iterable
    {
        foreach (FormatterTest::exportContractPayloads() as $name => [$payload, $format, $decorated]) {
            if ($format === 'json') {
                foreach (['get', 'list'] as $action) {
                    yield $name . ' ' . $action => [$payload, $decorated, $action];
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('directConfigPayloads')]
    public function testDirectJsonPreservesConfigurationValue(string $payload, bool $decorated, string $action): void
    {
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config[\'project_name\'] = ' . var_export($payload, true) . ';'
        );
        $arguments = ['action' => $action, '--json' => true];
        $arguments += $action === 'get' ? ['key' => 'main.project_name'] : ['--file' => 'main'];
        $this->tester->execute($arguments, ['decorated' => $decorated]);
        $text = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $text);
        $this->assertJson($text);
        $this->assertSame($payload, json_decode($text, true)['main.project_name']);
    }
}
