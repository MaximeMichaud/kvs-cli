<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\BackupCommand;
use KVS\CLI\Command\System\CacheCommand;
use KVS\CLI\Command\System\CheckCommand;
use KVS\CLI\Command\System\CronCommand;
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
            new StatusCommand($this->config),
        ];

        foreach ($commands as $command) {
            $this->assertStringContainsString('EXAMPLES', $command->getHelp(), (string) $command->getName());
        }
    }
}
