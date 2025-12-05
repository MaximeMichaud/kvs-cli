<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

class ApplicationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application();
    }

    public function testApplicationHasName(): void
    {
        $this->assertEquals('KVS CLI', $this->app->getName());
    }

    public function testApplicationHasVersion(): void
    {
        $this->assertEquals('1.0.0-beta', $this->app->getVersion());
    }

    public function testApplicationHasDefaultCommands(): void
    {
        $commands = $this->app->all();

        // Should have at least help and list commands
        $this->assertArrayHasKey('help', $commands);
        $this->assertArrayHasKey('list', $commands);
    }

    public function testApplicationHasPathOption(): void
    {
        $definition = $this->app->getDefinition();

        $this->assertTrue($definition->hasOption('path'));
        $option = $definition->getOption('path');
        $this->assertEquals('Path to KVS installation directory', $option->getDescription());
    }

    public function testApplicationHelp(): void
    {
        $help = $this->app->getHelp();

        // When no KVS detected, should show warning
        $this->assertStringContainsString('No KVS installation detected', $help);
    }

    public function testApplicationRun(): void
    {
        $tester = new ApplicationTester($this->app);
        $tester->run(['command' => 'list'], ['capture_stderr_separately' => true]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Available commands:', $output);
    }
}
