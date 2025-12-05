<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Config\Configuration;

class ConfigurationTest extends TestCase
{
    public function testConfigurationThrowsExceptionWhenNoKvsFound(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not contain a valid KVS installation');

        // Try to create config with non-existent path
        $tempDir = TestHelper::getProjectTempDir();
        $config = new Configuration(['path' => $tempDir . '/nonexistent-kvs']);
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
}
