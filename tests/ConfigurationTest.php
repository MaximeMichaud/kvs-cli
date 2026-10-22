<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Config\Configuration;
use KVS\CLI\Constants;

#[CoversClass(Configuration::class)]
class ConfigurationTest extends TestCase
{
    public function testConfigurationThrowsExceptionWhenNoKvsFound(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not contain a valid KVS installation');

        // Try to create config with non-existent path
        $tempDir = TestHelper::getProjectTempDir();
        new Configuration(['path' => $tempDir . '/nonexistent-kvs']);
    }

    public function testConfigurationAcceptsValidPath(): void
    {
        // Create mock KVS structure with valid DB config using TestHelper
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals($tempDir, $config->getKvsPath());
        $this->assertTrue($config->isKvsInstalled());

        // Cleanup
        TestHelper::removeDir($tempDir);
    }

    public function testGetAdminPath(): void
    {
        // Create mock KVS structure using TestHelper
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals($tempDir . '/admin', $config->getAdminPath());

        // Cleanup
        TestHelper::removeDir($tempDir);
    }

    public function testGetDatabaseConfigReturnsArray(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $config = new Configuration(['path' => $tempDir]);
        $dbConfig = $config->getDatabaseConfig();

        // Verify structure - values may come from file or env vars
        $this->assertArrayHasKey('host', $dbConfig);
        $this->assertArrayHasKey('user', $dbConfig);
        $this->assertArrayHasKey('password', $dbConfig);
        $this->assertArrayHasKey('database', $dbConfig);
        $this->assertNotEmpty($dbConfig['host']);
        $this->assertNotEmpty($dbConfig['user']);
        $this->assertNotEmpty($dbConfig['database']);

        TestHelper::removeDir($tempDir);
    }

    public function testDatabaseConfigKeepsEmptyPassword(): void
    {
        $tempDir = TestHelper::createTempDir();
        mkdir($tempDir . '/admin/include', 0755, true);
        file_put_contents(
            $tempDir . '/admin/include/setup_db.php',
            <<<'PHP'
<?php
define('DB_HOST', '127.0.0.1');
define('DB_LOGIN', 'root');
define('DB_PASS', '');
define('DB_DEVICE', 'kvs_test');

PHP
        );
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        try {
            $config = new Configuration([
                'path' => $tempDir,
                'disable_db_env_overrides' => true,
            ]);

            $dbConfig = $config->getDatabaseConfig();

            $this->assertArrayHasKey('password', $dbConfig);
            $this->assertSame('', $dbConfig['password']);
            $this->assertTrue($config->isKvsInstalled());
        } finally {
            TestHelper::removeDir($tempDir);
        }
    }

    public function testDatabasePortEnvironmentOverrideIsAppliedToHost(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir, ['host' => '127.0.0.1']);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $previousPort = getenv('KVS_DB_PORT');
        putenv('KVS_DB_PORT=3308');

        try {
            $config = new Configuration(['path' => $tempDir]);
            $dbConfig = $config->getDatabaseConfig();

            $this->assertSame('127.0.0.1:3308', $dbConfig['host'] ?? null);
            $this->assertSame('3308', $dbConfig['port'] ?? null);
        } finally {
            if ($previousPort === false) {
                putenv('KVS_DB_PORT');
            } else {
                putenv('KVS_DB_PORT=' . $previousPort);
            }
            TestHelper::removeDir($tempDir);
        }
    }

    public function testDatabaseEnvironmentOverridesCanBeDisabled(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir, [
            'host' => '127.0.0.1',
            'user' => 'file_user',
            'password' => 'file_pass',
            'database' => 'file_database',
        ]);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $previousUser = getenv('KVS_DB_USER');
        $previousPass = getenv('KVS_DB_PASS');
        $previousDatabase = getenv('KVS_DB_NAME');
        putenv('KVS_DB_USER=env_user');
        putenv('KVS_DB_PASS=env_pass');
        putenv('KVS_DB_NAME=env_database');

        try {
            $config = new Configuration([
                'path' => $tempDir,
                'disable_db_env_overrides' => true,
            ]);
            $dbConfig = $config->getDatabaseConfig();

            $this->assertSame('file_user', $dbConfig['user']);
            $this->assertSame('file_pass', $dbConfig['password']);
            $this->assertSame('file_database', $dbConfig['database']);
        } finally {
            if ($previousUser === false) {
                putenv('KVS_DB_USER');
            } else {
                putenv('KVS_DB_USER=' . $previousUser);
            }
            if ($previousPass === false) {
                putenv('KVS_DB_PASS');
            } else {
                putenv('KVS_DB_PASS=' . $previousPass);
            }
            if ($previousDatabase === false) {
                putenv('KVS_DB_NAME');
            } else {
                putenv('KVS_DB_NAME=' . $previousDatabase);
            }
            TestHelper::removeDir($tempDir);
        }
    }

    public function testGetTablePrefixReturnsDefault(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals(Constants::DEFAULT_TABLE_PREFIX, $config->getTablePrefix());

        TestHelper::removeDir($tempDir);
    }

    public function testGetTablePrefixReturnsConfiguredValue(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents(
            $tempDir . '/admin/include/setup.php',
            '<?php $config = ["tables_prefix" => "custom_"];'
        );

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals('custom_', $config->getTablePrefix());

        TestHelper::removeDir($tempDir);
    }

    public function testGetKvsVersionReturnsEmptyWhenNotSet(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals('', $config->getKvsVersion());

        TestHelper::removeDir($tempDir);
    }

    public function testGetKvsVersionReturnsConfiguredValue(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents(
            $tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_version" => "6.3.2"];'
        );

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals('6.3.2', $config->getKvsVersion());

        TestHelper::removeDir($tempDir);
    }

    public function testGetKvsVersionSurvivesRepeatedSetupIncludeOnceLoads(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents(
            $tempDir . '/admin/include/version.php',
            '<?php $config["project_version"] = "7.0.0";'
        );
        file_put_contents(
            $tempDir . '/admin/include/setup.php',
            <<<'PHP'
<?php
include_once 'version.php';
if (!isset($config)) {
    $config = [];
}
$config['tables_prefix'] = 'ktvs_';
PHP
        );

        $firstConfig = new Configuration(['path' => $tempDir]);
        $secondConfig = new Configuration(['path' => $tempDir]);

        $this->assertSame('7.0.0', $firstConfig->getKvsVersion());
        $this->assertSame('7.0.0', $secondConfig->getKvsVersion());
        $this->assertSame('ktvs_', $secondConfig->getTablePrefix());

        TestHelper::removeDir($tempDir);
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $config = new Configuration(['path' => $tempDir]);

        $this->assertNull($config->get('nonexistent'));
        $this->assertEquals('default_value', $config->get('nonexistent', 'default_value'));

        TestHelper::removeDir($tempDir);
    }

    public function testGetReturnsConfiguredValue(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents(
            $tempDir . '/admin/include/setup.php',
            '<?php $config = ["custom_key" => "custom_value"];'
        );

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals('custom_value', $config->get('custom_key'));

        TestHelper::removeDir($tempDir);
    }

    public function testGetContentPathWithStandardLayout(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        // Create standard content directory
        mkdir($tempDir . '/' . Constants::CONTENT_DIR, 0755, true);

        $config = new Configuration(['path' => $tempDir]);

        $this->assertStringContainsString(Constants::CONTENT_DIR, $config->getContentPath());

        TestHelper::removeDir($tempDir);
    }

    public function testGetVideoSourcesPath(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');
        mkdir($tempDir . '/' . Constants::CONTENT_DIR, 0755, true);

        $config = new Configuration(['path' => $tempDir]);

        $this->assertStringContainsString(Constants::CONTENT_VIDEOS_SOURCES, $config->getVideoSourcesPath());

        TestHelper::removeDir($tempDir);
    }

    public function testGetVideoSourcesPathWithConfiguredPath(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents(
            $tempDir . '/admin/include/setup.php',
            '<?php $config = ["content_path_videos_sources" => "/custom/videos/sources"];'
        );

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals('/custom/videos/sources', $config->getVideoSourcesPath());

        TestHelper::removeDir($tempDir);
    }

    public function testGetVideoSourcesPathFallsBackWhenConfiguredPathIsStale(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        mkdir($tempDir . '/' . Constants::CONTENT_DIR . '/' . Constants::CONTENT_VIDEOS_SOURCES, 0755, true);
        file_put_contents(
            $tempDir . '/admin/include/setup.php',
            '<?php $config = ["content_path_videos_sources" => "/stale/videos_sources"];'
        );

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals(
            $tempDir . '/' . Constants::CONTENT_DIR . '/' . Constants::CONTENT_VIDEOS_SOURCES,
            $config->getVideoSourcesPath()
        );

        TestHelper::removeDir($tempDir);
    }

    public function testGetVideoScreenshotsPath(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');
        mkdir($tempDir . '/' . Constants::CONTENT_DIR, 0755, true);

        $config = new Configuration(['path' => $tempDir]);

        $this->assertStringContainsString(Constants::CONTENT_VIDEOS_SCREENSHOTS, $config->getVideoScreenshotsPath());

        TestHelper::removeDir($tempDir);
    }

    public function testGetVideoScreenshotsPathWithConfiguredPath(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents(
            $tempDir . '/admin/include/setup.php',
            '<?php $config = ["content_path_videos_screenshots" => "/custom/videos/screenshots"];'
        );

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals('/custom/videos/screenshots', $config->getVideoScreenshotsPath());

        TestHelper::removeDir($tempDir);
    }

    public function testGetVideoScreenshotsPathFallsBackWhenConfiguredPathIsStale(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        mkdir($tempDir . '/' . Constants::CONTENT_DIR . '/' . Constants::CONTENT_VIDEOS_SCREENSHOTS, 0755, true);
        file_put_contents(
            $tempDir . '/admin/include/setup.php',
            '<?php $config = ["content_path_videos_screenshots" => "/stale/videos_screenshots"];'
        );

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals(
            $tempDir . '/' . Constants::CONTENT_DIR . '/' . Constants::CONTENT_VIDEOS_SCREENSHOTS,
            $config->getVideoScreenshotsPath()
        );

        TestHelper::removeDir($tempDir);
    }

    public function testGetAlbumSourcesPath(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');
        mkdir($tempDir . '/' . Constants::CONTENT_DIR, 0755, true);

        $config = new Configuration(['path' => $tempDir]);

        $this->assertStringContainsString(Constants::CONTENT_ALBUMS_SOURCES, $config->getAlbumSourcesPath());

        TestHelper::removeDir($tempDir);
    }

    public function testGetAlbumSourcesPathWithConfiguredPath(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents(
            $tempDir . '/admin/include/setup.php',
            '<?php $config = ["content_path_albums_sources" => "/custom/albums/sources"];'
        );

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals('/custom/albums/sources', $config->getAlbumSourcesPath());

        TestHelper::removeDir($tempDir);
    }

    public function testIsKvsInstalledReturnsTrueWithValidConfig(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');

        $config = new Configuration(['path' => $tempDir]);

        $this->assertTrue($config->isKvsInstalled());

        TestHelper::removeDir($tempDir);
    }

    public function testGetContentPathReturnsProjectPathWhenContentDirMissing(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        file_put_contents($tempDir . '/admin/include/setup.php', '<?php $config = [];');
        // Do NOT create content dir

        $config = new Configuration(['path' => $tempDir]);
        $contentPath = $config->getContentPath();

        // Should return the expected path even if directory doesn't exist
        $this->assertStringContainsString(Constants::CONTENT_DIR, $contentPath);

        TestHelper::removeDir($tempDir);
    }

    public function testMultipleConfigurationValues(): void
    {
        $tempDir = TestHelper::createTempDir();
        TestHelper::createMockDbConfig($tempDir);
        $setupContent = '<?php $config = [
            "project_version" => "6.4.0",
            "tables_prefix" => "kvs_",
            "custom_setting" => "custom_value",
            "numeric_setting" => 42
        ];';
        file_put_contents($tempDir . '/admin/include/setup.php', $setupContent);

        $config = new Configuration(['path' => $tempDir]);

        $this->assertEquals('6.4.0', $config->getKvsVersion());
        $this->assertEquals('kvs_', $config->getTablePrefix());
        $this->assertEquals('custom_value', $config->get('custom_setting'));
        $this->assertEquals(42, $config->get('numeric_setting'));

        TestHelper::removeDir($tempDir);
    }

    /**
     * @dataProvider databaseDefinitionSyntaxProvider
     */
    public function testDatabaseReaderSelectsSyntacticDefinitions(string $body, ?string $expected): void
    {
        $this->assertSame($expected, $this->readSyntheticDatabaseDefinitions($body)['password'] ?? null);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function databaseDefinitionSyntaxProvider(): iterable
    {
        $actual = "define('DB_PASS', 'Synthetic actual');";
        $fake = "define('DB_PASS', 'Synthetic example');";
        yield 'literal control' => [$actual, 'Synthetic actual'];
        yield 'line comment' => ['// ' . $fake . "\n" . $actual, 'Synthetic actual'];
        yield 'block comment' => ['/* ' . $fake . " */\n" . $actual, 'Synthetic actual'];
        yield 'documentation comment' => ['/** ' . $fake . " */\n" . $actual, 'Synthetic actual'];
        yield 'quoted example' => ['$example = "' . $fake . '";' . "\n" . $actual, 'Synthetic actual'];
        yield 'nowdoc example' => ["\$example = <<<'EXAMPLE'\n" . $fake . "\nEXAMPLE;\n" . $actual, 'Synthetic actual'];
        yield 'comment inside a real call' => [
            "define('DB_PASS', /* $fake */ 'Synthetic actual');", 'Synthetic actual',
        ];
        yield 'method call' => ['$object->' . $fake . "\n" . $actual, 'Synthetic actual'];
        yield 'static call' => ['Fixture::' . $fake . "\n" . $actual, 'Synthetic actual'];
        yield 'foreign qualified function' => ['Fixture\\' . $fake . "\n" . $actual, 'Synthetic actual'];
        yield 'relative function' => ['namespace\\' . $fake . "\n" . $actual, 'Synthetic actual'];
        yield 'fully qualified define' => ['\\' . $actual, 'Synthetic actual'];
        yield 'simple define in a namespace' => ['namespace Fixture; ' . $actual, 'Synthetic actual'];
        yield 'fully qualified define in a namespace' => ['namespace Fixture; \\' . $actual, 'Synthetic actual'];
        yield 'function body retains syntactic selection' => ['function fixture() { ' . $actual . ' }', 'Synthetic actual'];
        yield 'first literal wins' => [$actual . "\n" . $fake, 'Synthetic actual'];
        yield 'double-quoted key and value' => ['define("DB_PASS", "Synthetic actual");', 'Synthetic actual'];
        yield 'only a comment' => ['// ' . $fake, null];
        yield 'third argument remains unsupported' => ["define('DB_PASS', 'Synthetic example', false);", null];
        yield 'named arguments remain unsupported' => ["define(constant_name: 'DB_PASS', value: 'Synthetic example');", null];
        yield 'concatenation remains unsupported' => ["define('DB_PASS', 'Synthetic ' . 'example');", null];
        yield 'nowdoc value remains unsupported' => ["define('DB_PASS', <<<'VALUE'\nSynthetic example\nVALUE\n);", null];
    }

    /**
     * @dataProvider databaseStringLiteralProvider
     */
    public function testDatabaseReaderDecodesQuotedLiteralBytes(string $literal, string $expected): void
    {
        $this->assertSame($expected, $this->readSyntheticDatabaseDefinitions("define('DB_PASS', $literal);")['password'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function databaseStringLiteralProvider(): iterable
    {
        foreach (["Synthetic apostrophe's", 'Synthetic \\path\\tail', 'Synthetic "quote"; value', "Synthetic\nline", ''] as $index => $value) {
            yield 'single quoted ' . $index => [var_export($value, true), $value];
        }
        yield 'double quoted escaped bytes' => ['"Synthetic \\nline \\\\path \\"quote\\""', "Synthetic \nline \\path \"quote\""];
    }

    /**
     * @dataProvider databaseEnvironmentExpressionProvider
     */
    public function testDatabaseReaderPreservesEnvironmentFormsAndPriorities(string $body, string|false $environment, ?string $expected): void
    {
        $name = 'KVS_READER_SYNTHETIC_VALUE';
        $previous = getenv($name);
        $environment === false ? putenv($name) : putenv($name . '=' . $environment);
        try {
            $this->assertSame($expected, $this->readSyntheticDatabaseDefinitions($body)['password'] ?? null);
        } finally {
            $previous === false ? putenv($name) : putenv($name . '=' . $previous);
        }
    }

    /**
     * @return iterable<string, array{string, string|false, ?string}>
     */
    public static function databaseEnvironmentExpressionProvider(): iterable
    {
        $env = "getenv('KVS_READER_SYNTHETIC_VALUE')";
        $cases = [
            'missing' => [false, 'Synthetic default', 'Synthetic default', null],
            'empty' => ['', 'Synthetic default', '', ''],
            'zero' => ['0', '0', '0', '0'],
            'nonempty' => ['Synthetic environment', 'Synthetic environment', 'Synthetic environment', 'Synthetic environment'],
        ];
        foreach ($cases as $label => [$value, $ternaryValue, $coalesceValue, $directValue]) {
            yield 'short ternary ' . $label => [
                "define('DB_PASS', $env ?: 'Synthetic default');", $value, $ternaryValue,
            ];
            yield 'coalesce ' . $label => [
                "define('DB_PASS', $env ?? 'Synthetic default');", $value, $coalesceValue,
            ];
            yield 'direct getenv ' . $label => ["define('DB_PASS', $env);", $value, $directValue];
        }
        $direct = "define('DB_PASS', $env);";
        $coalesce = "define('DB_PASS', $env ?? 'Synthetic coalesce');";
        $ternary = "define('DB_PASS', $env ?: 'Synthetic ternary');";
        $literal = "define('DB_PASS', 'Synthetic literal');";
        yield 'literal before environment' => [$literal . $ternary . $coalesce . $direct, 'Synthetic environment', 'Synthetic literal'];
        yield 'literal after environment' => [$direct . $coalesce . $ternary . $literal, 'Synthetic environment', 'Synthetic literal'];
        yield 'ternary before coalesce' => [$ternary . $coalesce . $direct, false, 'Synthetic ternary'];
        yield 'ternary after coalesce' => [$direct . $coalesce . $ternary, false, 'Synthetic ternary'];
        yield 'coalesce before direct' => [$coalesce . $direct, false, 'Synthetic coalesce'];
        yield 'coalesce after direct' => [$direct . $coalesce, false, 'Synthetic coalesce'];
        yield 'first expression within the same form' => [
            $ternary . "define('DB_PASS', $env ?: 'Synthetic second');", false, 'Synthetic ternary',
        ];
        yield 'escaped fallback' => [
            "define('DB_PASS', $env ?: " . var_export("Synthetic default's \\path;", true) . ');', false,
            "Synthetic default's \\path;",
        ];
        yield 'commented literal cannot override getenv' => [
            "// $literal\n$ternary", 'Synthetic environment', 'Synthetic environment',
        ];
    }

    public function testDatabaseReaderDoesNotExecuteTheDatabaseConfiguration(): void
    {
        $directory = TestHelper::createTempDir();
        $marker = $directory . '/must-not-exist';
        try {
            $body = "file_put_contents(" . var_export($marker, true) . ", 'unexpected'); define('DB_PASS', 'Synthetic actual');";
            $this->assertSame('Synthetic actual', $this->readSyntheticDatabaseDefinitions($body)['password'] ?? null);
            $this->assertFileDoesNotExist($marker);
        } finally {
            TestHelper::removeDir($directory);
        }
    }

    /**
     * @return array<string, string>
     */
    private function readSyntheticDatabaseDefinitions(string $body): array
    {
        $directory = TestHelper::createTempDir();
        mkdir($directory . '/admin/include', 0755, true);
        file_put_contents($directory . '/admin/include/setup_db.php', "<?php\n" . $body . "\n");
        file_put_contents($directory . '/admin/include/setup.php', '<?php $config = [];');
        try {
            return (new Configuration(['path' => $directory, 'disable_db_env_overrides' => true]))->getDatabaseConfig();
        } finally {
            TestHelper::removeDir($directory);
        }
    }
}
