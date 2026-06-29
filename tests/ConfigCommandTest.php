<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\ConfigCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

/**
 * ConfigCommand Test Suite
 *
 * Coverage: 78.26% (234/299 lines)
 *
 * TESTED (✅):
 * - Configuration listing (all sections)
 * - Project, Tools, Server, Content, Database sections
 * - 3-column Content Paths & URLs display ⭐
 * - JSON export format
 * - Config get with key validation
 * - Protected value masking (passwords)
 * - --show-protected flag
 * - --file filtering (db, main, all)
 * - Input validation (key format, categories)
 *
 * NOT TESTED (❌) - See tests/COVERAGE.md for details:
 * - setDatabaseConfig() - File write operations
 * - setMainConfig() - File write operations
 * - editConfig() - Interactive editor
 * - createBackup() - File creation
 * - getConfigFilePath() - Path resolution edge cases
 *
 * Reason: These require:
 * 1. File write operations (risk of corrupting test setup)
 * 2. Mock interactive editor (passthru() not easily mockable)
 * 3. Real database connection testing
 * 4. Complex cleanup mechanisms
 *
 * Alternative: Use vfsStream for safe file system testing
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
$config["player_license_code"] = "secret-player-license";
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
}
