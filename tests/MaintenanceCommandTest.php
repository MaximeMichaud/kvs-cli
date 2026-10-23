<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\MaintenanceCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\Attributes\DataProvider;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

class MaintenanceCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private MaintenanceCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create temporary KVS installation
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data/system', 0755, true);
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        // Setup command
        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new MaintenanceCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        // Cleanup
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testMaintenanceStatus(): void
    {
        $this->tester->execute(['action' => 'status']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Maintenance mode is DISABLED', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testMaintenanceEnable(): void
    {
        $this->tester->execute(['action' => 'on']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Maintenance mode enabled', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // Check file was created
        $settingsFile = $this->tempDir . '/admin/data/system/website_ui_params.dat';
        $this->assertFileExists($settingsFile);

        $settings = unserialize(file_get_contents($settingsFile));
        $this->assertEquals(1, $settings['DISABLE_WEBSITE']);
    }

    public function testMaintenanceDisable(): void
    {
        // First enable
        $this->tester->execute(['action' => 'on']);

        // Then disable
        $this->tester->execute(['action' => 'off']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Maintenance mode disabled', $output);

        // Check file was updated
        $settingsFile = $this->tempDir . '/admin/data/system/website_ui_params.dat';
        $settings = unserialize(file_get_contents($settingsFile));
        $this->assertEquals(0, $settings['DISABLE_WEBSITE']);
    }

    /** @return array<string, array{string, int}> */
    public static function maintenanceModes(): array
    {
        return ['enable' => ['on', 0], 'disable' => ['off', 1]];
    }

    #[DataProvider('maintenanceModes')]
    public function testMaintenanceRejectsReadOnlySettings(string $action, int $initialMode): void
    {
        $contents = serialize(['DISABLE_WEBSITE' => $initialMode, 'CUSTOM_SETTING' => 'preserve']);
        [$tester, $settings] = $this->createVirtualSettingsTester($contents, 0444);

        $tester->execute(['action' => $action]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Failed to write maintenance settings', $tester->getDisplay());
        $this->assertSame($contents, $this->readVirtualSettings($settings));
    }

    /** @return array<string, array{string}> */
    public static function settingsActions(): array
    {
        return ['status' => ['status'], 'enable' => ['on'], 'disable' => ['off']];
    }

    #[DataProvider('settingsActions')]
    public function testMaintenanceRejectsUnreadableSettings(string $action): void
    {
        $contents = serialize(['DISABLE_WEBSITE' => 1, 'CUSTOM_SETTING' => 'preserve']);
        [$tester, $settings] = $this->createVirtualSettingsTester($contents, 0200);

        $tester->execute(['action' => $action]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Failed to read maintenance settings', $tester->getDisplay());
        $this->assertSame($contents, $this->readVirtualSettings($settings));
        $this->assertStringNotContainsString('Maintenance mode is DISABLED', $tester->getDisplay());
    }

    #[DataProvider('settingsActions')]
    public function testMaintenanceRejectsInvalidSettings(string $action): void
    {
        $contents = 'invalid serialized settings';
        [$tester, $settings] = $this->createVirtualSettingsTester($contents, 0644);

        $tester->execute(['action' => $action]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Invalid maintenance settings', $tester->getDisplay());
        $this->assertSame($contents, $this->readVirtualSettings($settings));
    }

    public function testMaintenancePreservesOtherSettings(): void
    {
        [$tester, $settings] = $this->createVirtualSettingsTester(serialize(['CUSTOM_SETTING' => 'preserve']), 0644);

        foreach (['on' => 1, 'off' => 0] as $action => $mode) {
            $tester->execute(['action' => $action]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame(
                ['CUSTOM_SETTING' => 'preserve', 'DISABLE_WEBSITE' => $mode],
                unserialize($this->readVirtualSettings($settings))
            );
        }
    }

    /** @return array{CommandTester, vfsStreamFile} */
    private function createVirtualSettingsTester(string $contents, int $permissions): array
    {
        $root = vfsStream::setup('maintenance', 0755, [
            'admin' => [
                'include' => ['setup_db.php' => '<?php', 'setup.php' => '<?php'],
                'data' => ['system' => ['website_ui_params.dat' => $contents]],
            ],
        ]);
        $settings = $root->getChild('admin/data/system/website_ui_params.dat');
        $this->assertInstanceOf(vfsStreamFile::class, $settings);
        $settings->chmod($permissions);
        $config = $this->getMockBuilder(Configuration::class)
            ->setConstructorArgs([['path' => $this->tempDir]])
            ->onlyMethods(['getKvsPath'])
            ->getMock();
        $config->method('getKvsPath')->willReturn($root->url());

        return [new CommandTester(new MaintenanceCommand($config)), $settings];
    }

    public function testInvalidMode(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid action', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testMaintenancePreservesSymbolicDirectoryResolution(): void
    {
        $directory = $this->tempDir . '/admin/data/system';
        $storage = $this->tempDir . '/shared-settings';
        mkdir($storage . '/nested', 0755, true);
        $actual = $storage . '/params.dat';
        $decoy = $directory . '/params.dat';
        file_put_contents($actual, serialize(['DISABLE_WEBSITE' => 0, 'CUSTOM_SETTING' => 'actual']));
        $decoyContents = serialize(['DISABLE_WEBSITE' => 0, 'CUSTOM_SETTING' => 'decoy']);
        file_put_contents($decoy, $decoyContents);
        $this->assertTrue(symlink($storage . '/nested', $directory . '/bridge'));
        $settingsFile = $directory . '/website_ui_params.dat';
        $this->assertTrue(symlink('bridge/../params.dat', $settingsFile));

        $this->tester->execute(['action' => 'on']);

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertSame(
            serialize(['DISABLE_WEBSITE' => 1, 'CUSTOM_SETTING' => 'actual']),
            file_get_contents($actual)
        );
        $this->assertSame($decoyContents, file_get_contents($decoy));
        $this->assertSame('bridge/../params.dat', readlink($settingsFile));
    }

    #[DataProvider('maintenanceModes')]
    public function testMaintenancePreservesSettingsAfterPartialWrite(string $action, int $initialMode): void
    {
        $contents = serialize(['DISABLE_WEBSITE' => $initialMode, 'CUSTOM_SETTING' => 'preserve']);
        [$tester, $settings] = $this->createVirtualSettingsTester($contents, 0644);
        vfsStream::setQuota(32);
        try {
            $tester->execute(['action' => $action]);
            $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString('Failed to write maintenance settings', $tester->getDisplay());
            $this->assertSame($contents, $this->readVirtualSettings($settings));
        } finally {
            vfsStream::setQuota(-1);
        }
    }

    private function readVirtualSettings(vfsStreamFile $settings): string
    {
        $root = \org\bovigo\vfs\vfsStreamWrapper::getRoot();
        $this->assertNotNull($root);
        $current = $root->getChild($settings->path());
        $this->assertInstanceOf(vfsStreamFile::class, $current);

        return $current->getContent();
    }

    #[DataProvider('hardlinkedSymbolicTargets')]
    public function testMaintenancePreservesHardlinkedSymbolicTargets(bool $targetExists): void
    {
        $directory = $this->tempDir . '/admin/data/system';
        mkdir($directory . '/nested/nested', 0755, true);
        $settingsFile = $directory . '/website_ui_params.dat';
        $secondLink = $directory . '/nested/website_ui_params.dat';
        $actual = $directory . '/nested/nested/website_ui_params.dat';
        $settings = ['DISABLE_WEBSITE' => 0, 'CUSTOM_SETTING' => 'preserve'];
        if ($targetExists) {
            file_put_contents($actual, serialize($settings));
            chmod($actual, 0640);
        }
        $metadata = $targetExists ? stat($actual) : false;
        $this->assertTrue(symlink('nested/website_ui_params.dat', $settingsFile));
        $this->assertTrue(link($settingsFile, $secondLink));
        $this->assertTrue(is_link($secondLink));
        $firstLinkMetadata = lstat($settingsFile);
        $secondLinkMetadata = lstat($secondLink);
        $this->assertNotFalse($firstLinkMetadata);
        $this->assertNotFalse($secondLinkMetadata);
        $this->assertSame($firstLinkMetadata['dev'], $secondLinkMetadata['dev']);
        $this->assertSame($firstLinkMetadata['ino'], $secondLinkMetadata['ino']);

        $this->tester->execute(['action' => 'on']);
        clearstatcache();

        $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $expected = $targetExists ? $settings : [];
        $expected['DISABLE_WEBSITE'] = 1;
        $this->assertSame(serialize($expected), file_get_contents($actual));
        $this->assertTrue(is_link($settingsFile));
        $this->assertTrue(is_link($secondLink));
        $this->assertSame('nested/website_ui_params.dat', readlink($settingsFile));
        $this->assertSame('nested/website_ui_params.dat', readlink($secondLink));
        $this->assertSame($firstLinkMetadata['ino'], lstat($settingsFile)['ino']);
        $this->assertSame($secondLinkMetadata['ino'], lstat($secondLink)['ino']);
        if ($metadata !== false) {
            $current = stat($actual);
            $this->assertNotFalse($current);
            $this->assertSame($metadata['mode'] & 0777, $current['mode'] & 0777);
            $this->assertSame($metadata['uid'], $current['uid']);
            $this->assertSame($metadata['gid'], $current['gid']);
        }
        $this->assertSame([], glob($directory . '/nested/nested/.kvs-maintenance-*'));
    }

    /** @return array<string, array{bool}> */
    public static function hardlinkedSymbolicTargets(): array
    {
        return ['existing target' => [true], 'dangling target' => [false]];
    }
}
