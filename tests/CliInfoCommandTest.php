<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\CliInfoCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(CliInfoCommand::class)]
class CliInfoCommandTest extends TestCase
{
    private CliInfoCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->command = new CliInfoCommand();

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    public function testCliInfoDefaultFormat(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('OS:', $output);
        $this->assertStringContainsString('PHP binary:', $output);
        $this->assertStringContainsString('PHP version:', $output);
    }

    public function testCliInfoJsonFormat(): void
    {
        $this->tester->execute(['--format' => 'json']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('os', $json);
        $this->assertArrayHasKey('php_binary', $json);
        $this->assertArrayHasKey('php_version', $json);
    }

    public function testCliInfoContainsPhpInfo(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString(PHP_VERSION, $output);
    }

    public function testCliInfoContainsCliVersion(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('KVS CLI version:', $output);
    }

    public function testCliInfoListFormatExplicit(): void
    {
        $this->tester->execute(['--format' => 'list']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('OS:', $output);
    }

    public function testCliInfoJsonContainsExpectedKeys(): void
    {
        $this->tester->execute(['--format' => 'json']);

        $output = $this->tester->getDisplay();
        $json = json_decode($output, true);

        $expectedKeys = ['os', 'shell', 'php_binary', 'php_version', 'kvs_cli_version'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json, "Missing key: $key");
        }
    }
}
