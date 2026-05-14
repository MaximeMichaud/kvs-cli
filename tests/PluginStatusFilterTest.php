<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\PluginCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PluginStatusFilterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kvs-plugin-status-test-' . uniqid();
        TestHelper::createMockKvsInstallation($this->tempDir);

        $pluginDir = $this->tempDir . '/admin/plugins/demo_plugin';
        mkdir($pluginDir, 0755, true);
        file_put_contents($pluginDir . '/demo_plugin.php', "<?php\nfunction demo_pluginShow() {}\n");
        file_put_contents($pluginDir . '/demo_plugin.tpl', '');
        file_put_contents(
            $pluginDir . '/demo_plugin.dat',
            '<plugin><plugin_name>Demo Plugin</plugin_name><author>Test</author>'
            . '<version>1.0</version><kvs_version>1.0</kvs_version><plugin_types>manual</plugin_types></plugin>'
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            TestHelper::removeDir($this->tempDir);
        }
    }

    public function testListWithInvalidStatusFails(): void
    {
        $tester = new CommandTester(new PluginCommand(new Configuration(['path' => $this->tempDir])));
        $exitCode = $tester->execute([
            'action' => 'list',
            '--status' => 'bogus',
            '--format' => 'count',
        ]);

        $output = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid status', $output);
    }

    public function testListWithInvalidTypeFails(): void
    {
        $tester = new CommandTester(new PluginCommand(new Configuration(['path' => $this->tempDir])));
        $exitCode = $tester->execute([
            'action' => 'list',
            '--type' => 'bogus',
            '--format' => 'count',
        ]);

        $output = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid type', $output);
    }
}
