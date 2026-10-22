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

    public function testCliInfoRejectsInvalidFormat(): void
    {
        $this->tester->execute(['--format' => 'xml']);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Invalid value for --format "xml"', $output);
        $this->assertStringNotContainsString('OS:', $output);
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

    #[DataProvider('directJsonPayloads')]
    public function testDirectJsonPreservesShellEnvironment(string $payload, bool $decorated): void
    {
        $previousShell = getenv('SHELL');
        $previousKvsPath = getenv('KVS_PATH');
        $path = TestHelper::createTestKvsInstallation();
        putenv('SHELL=' . $payload);
        putenv('KVS_PATH=' . $path);
        try {
            $this->tester->execute(['--format' => 'json'], ['decorated' => $decorated]);
            $text = $this->tester->getDisplay();
            $this->assertSame(0, $this->tester->getStatusCode(), $text);
            $this->assertJson($text);
            $this->assertSame($payload, json_decode($text, true)['shell']);
        } finally {
            $previousShell === false ? putenv('SHELL') : putenv('SHELL=' . $previousShell);
            $previousKvsPath === false ? putenv('KVS_PATH') : putenv('KVS_PATH=' . $previousKvsPath);
            TestHelper::removeDir($path);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function directJsonPayloads(): iterable
    {
        foreach (FormatterTest::exportContractPayloads() as $name => [$payload, $format, $decorated]) {
            if ($format === 'json') {
                yield $name => [$payload, $decorated];
            }
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function jsonEncodingShellCases(): iterable
    {
        foreach ([false, true] as $valid) {
            foreach ([false, true] as $decorated) {
                yield (int) $valid . ' ' . (int) $decorated => [$valid, $decorated];
            }
        }
    }

    #[DataProvider('jsonEncodingShellCases')]
    public function testJsonEncodingCliInfoFailsExplicitlyWithoutChangingEnvironment(bool $valid, bool $decorated): void
    {
        $previousShell = getenv('SHELL');
        $previousPath = getenv('KVS_PATH');
        $fixture = TestHelper::createTestKvsInstallation();
        $payload = $valid ? 'Synthetic é <info>literal</info>' : "Synthetic private bytes \xff";
        putenv('SHELL=' . $payload);
        putenv('KVS_PATH=' . $fixture);
        try {
            $this->tester->execute(['--format' => 'json'], ['decorated' => $decorated]);
            $display = $this->tester->getDisplay();
            $this->assertSame($payload, getenv('SHELL'));
            if ($valid) {
                $this->assertSame(0, $this->tester->getStatusCode(), $display);
                $this->assertJson($display);
                $this->assertSame($payload, json_decode($display, true)['shell']);
            } else {
                $this->assertSame(1, $this->tester->getStatusCode());
                $this->assertStringContainsString('Failed to encode CLI information as JSON:', $display);
                $this->assertStringContainsString('Malformed UTF-8', $display);
                $this->assertStringNotContainsString('Synthetic private bytes', $display);
                $this->assertStringNotContainsString("\xff", $display);
            }
        } finally {
            $previousShell === false ? putenv('SHELL') : putenv('SHELL=' . $previousShell);
            $previousPath === false ? putenv('KVS_PATH') : putenv('KVS_PATH=' . $previousPath);
            TestHelper::removeDir($fixture);
        }
    }
}
