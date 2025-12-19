<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
        $expectedVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
        $this->assertEquals($expectedVersion, $this->app->getVersion());
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
        $this->app->setAutoExit(false);

        $input = new ArrayInput(['command' => 'list']);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->app->run($input, $output);

        $display = $output->fetch();
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Available commands:', $display);
    }
}
