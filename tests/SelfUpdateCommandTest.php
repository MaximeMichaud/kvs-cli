<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\SelfUpdateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Style\SymfonyStyle;

class SelfUpdateCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private SelfUpdateCommand $command;

    protected function setUp(): void
    {
        $this->command = new SelfUpdateCommand();

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    public function testCommandIsRegistered(): void
    {
        $this->assertEquals('self-update', $this->command->getName());
    }

    public function testCommandHasAliases(): void
    {
        $aliases = $this->command->getAliases();

        $this->assertContains('selfupdate', $aliases);
        $this->assertContains('self:update', $aliases);
    }

    public function testCommandHasDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
        $this->assertStringContainsString('update', strtolower($this->command->getDescription()));
    }

    public function testCommandHasStableOption(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('stable'));
        $this->assertFalse($definition->getOption('stable')->isValueRequired());
    }

    public function testCommandHasPreviewOption(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('preview'));
        $this->assertFalse($definition->getOption('preview')->isValueRequired());
    }

    public function testCommandHasCheckOption(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('check'));
        $this->assertFalse($definition->getOption('check')->isValueRequired());
    }

    public function testCommandHasYesOption(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('yes'));
        $this->assertEquals('y', $definition->getOption('yes')->getShortcut());
    }

    public function testNonPharExecutionShowsError(): void
    {
        // When not running as PHAR, should show error
        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('PHAR', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testCheckOptionWithNonPhar(): void
    {
        $this->commandTester->execute(['--check' => true]);

        $output = $this->commandTester->getDisplay();

        // Should still fail because not running as PHAR
        $this->assertStringContainsString('PHAR', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testCheckOptionDoesNotRequireWritablePhar(): void
    {
        $pharPath = tempnam(sys_get_temp_dir(), 'kvs-readonly-');
        $this->assertIsString($pharPath);
        file_put_contents($pharPath, 'phar');
        chmod($pharPath, 0555);

        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = [$pharPath];

        $command = new class extends SelfUpdateCommand {
            protected function isRunningAsPhar(): bool
            {
                return true;
            }

            protected function getCurrentVersion(): string
            {
                return '1.0.0';
            }

            /**
             * @return array<array{tag_name: string, prerelease: bool, assets: array<array{name: string, browser_download_url: string}>}>
             */
            protected function getGitHubReleases(SymfonyStyle $io, bool $includePrerelease): ?array
            {
                return [[
                    'tag_name' => 'v1.1.0',
                    'prerelease' => false,
                    'assets' => [],
                ]];
            }
        };

        try {
            $tester = new CommandTester($command);
            $tester->execute(['--check' => true]);

            $output = $tester->getDisplay();
            $this->assertSame(0, $tester->getStatusCode());
            $this->assertStringContainsString('New version available', $output);
            $this->assertStringNotContainsString('not writable', $output);
        } finally {
            if ($previousArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArgv;
            }

            chmod($pharPath, 0644);
            unlink($pharPath);
        }
    }

    public function testHelpOutput(): void
    {
        $help = $this->command->getHelp();

        $this->assertStringContainsString('--stable', $help);
        $this->assertStringContainsString('--preview', $help);
        $this->assertStringContainsString('--check', $help);
        $this->assertStringContainsString('Examples', $help);
    }
}
