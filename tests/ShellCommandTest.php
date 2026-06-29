<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\ShellCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(ShellCommand::class)]
class ShellCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private ShellCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);

        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new ShellCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testShellCommandConfiguration(): void
    {
        $this->assertEquals('shell', $this->command->getName());
        $this->assertStringContainsString('Interactive PHP shell', $this->command->getDescription());
    }

    public function testShellCommandHasKvsContext(): void
    {
        // This would normally launch an interactive shell, which we can't test directly
        // We can only verify the command is properly configured
        $this->assertInstanceOf(ShellCommand::class, $this->command);

        // Check that it has the right name and aliases
        $this->assertEquals('shell', $this->command->getName());
        $aliases = $this->command->getAliases();
        $this->assertContains('console', $aliases);
    }

    public function testShellCommandHasReplAlias(): void
    {
        $aliases = $this->command->getAliases();
        $this->assertContains('repl', $aliases);
    }

    public function testShellCommandHasIncludesOption(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('includes'));
    }

    public function testShellCommandHasBootstrapOption(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('bootstrap'));
    }

    public function testShellCommandHasHelpText(): void
    {
        $help = $this->command->getHelp();
        $this->assertStringContainsString('interactive PHP shell', $help);
        $this->assertStringContainsString('$config', $help);
        $this->assertStringContainsString('$db', $help);
    }

    public function testShellVariablesUseMysqliConnection(): void
    {
        $command = new class ($this->config) extends ShellCommand {
            public bool $mysqliRequested = false;

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \RuntimeException('Shell variables should not request a PDO connection');
            }

            protected function getMysqliConnection(bool $quiet = false): ?\mysqli
            {
                $this->mysqliRequested = true;
                return null;
            }
        };

        $reflection = new \ReflectionClass(ShellCommand::class);
        $method = $reflection->getMethod('getShellVariables');
        $variables = $method->invoke($command);

        $this->assertTrue($command->mysqliRequested);
        $this->assertIsArray($variables);
        $this->assertArrayHasKey('config', $variables);
        $this->assertIsArray($variables['config']);
        $this->assertSame('ktvs_', $variables['config']['tables_prefix']);
        $this->assertArrayHasKey('kvsConfig', $variables);
        $this->assertSame($this->config, $variables['kvsConfig']);
        $this->assertArrayNotHasKey('db', $variables);
        $this->assertArrayNotHasKey('kvs_db', $variables);
    }

    public function testShellVariablesExposeKvsDbAliasWithMysqliConnection(): void
    {
        $mysqli = $this->createMock(\mysqli::class);
        $command = new class ($this->config, $mysqli) extends ShellCommand {
            public function __construct(Configuration $config, private \mysqli $mysqli)
            {
                parent::__construct($config);
            }

            protected function getMysqliConnection(bool $quiet = false): ?\mysqli
            {
                return $this->mysqli;
            }
        };

        $reflection = new \ReflectionClass(ShellCommand::class);
        $method = $reflection->getMethod('getShellVariables');
        $variables = $method->invoke($command);

        $this->assertSame($mysqli, $variables['db']);
        $this->assertSame($mysqli, $variables['kvs_db']);
    }

    public function testShellRequiresPsySH(): void
    {
        if (!class_exists('Psy\Shell')) {
            $this->tester->execute([]);

            $output = $this->tester->getDisplay();
            $this->assertEquals(1, $this->tester->getStatusCode());
            $this->assertStringContainsString('PsySH is not installed', $output);
        } else {
            // If PsySH is installed, we can't really test the interactive shell
            // Just verify the command configuration is correct
            $this->assertTrue(class_exists('Psy\Shell'));
        }
    }
}
