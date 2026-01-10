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
}
