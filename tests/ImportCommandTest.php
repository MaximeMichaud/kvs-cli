<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Migrate\ImportCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class ImportCommandTest extends TestCase
{
    private ImportCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->command = new ImportCommand();

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    public function testImportCommandValidatesPackageExists(): void
    {
        $this->tester->execute([
            'package' => '/nonexistent/package.tar.zst',
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Package not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testImportCommandWithMissingPackage(): void
    {
        $this->tester->execute([
            'package' => '/nonexistent/package.tar.zst',
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Package not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testImportCommandWithInvalidExtension(): void
    {
        $tempFile = sys_get_temp_dir() . '/test-package-' . uniqid() . '.zip';
        touch($tempFile);

        try {
            $this->tester->execute([
                'package' => $tempFile,
                '--domain' => 'test.local',
                '--email' => 'test@test.com',
                '--force' => true,
            ]);

            $output = $this->tester->getDisplay();

            $this->assertStringContainsString('must be a .tar.zst file', $output);
            $this->assertEquals(1, $this->tester->getStatusCode());
        } finally {
            unlink($tempFile);
        }
    }

    public function testImportCommandRejectsNonexistentWithSslOption(): void
    {
        $this->tester->execute([
            'package' => '/nonexistent/package.tar.zst',
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--ssl' => '1',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Package not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testImportCommandRejectsNonexistentWithDbOption(): void
    {
        $this->tester->execute([
            'package' => '/nonexistent/package.tar.zst',
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--db' => '3',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Package not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testImportCommandHelp(): void
    {
        $help = $this->command->getHelp();

        $this->assertStringContainsString('migrate:package', $help);
        $this->assertStringContainsString('migrate:import', $help);
        $this->assertStringContainsString('SSL options', $help);
    }
}
