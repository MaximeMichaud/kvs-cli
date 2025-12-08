<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\SelfUpdateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

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

    public function testHelpOutput(): void
    {
        $help = $this->command->getHelp();

        $this->assertStringContainsString('--stable', $help);
        $this->assertStringContainsString('--preview', $help);
        $this->assertStringContainsString('--check', $help);
        $this->assertStringContainsString('Examples', $help);
    }
}
