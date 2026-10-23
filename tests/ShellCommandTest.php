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

    public function testShellIsBlockedInProductionByDefaultBeforeStartingPsysh(): void
    {
        $previousKvsEnv = getenv('KVS_ENV');
        $previousAllowEval = getenv('KVS_ALLOW_EVAL');
        putenv('KVS_ENV');
        putenv('KVS_ALLOW_EVAL');

        try {
            $this->tester->execute([]);

            $output = $this->tester->getDisplay();
            $this->assertSame(1, $this->tester->getStatusCode(), $output);
            $this->assertStringContainsString('Shell command is disabled in production environment.', $output);
            $this->assertStringNotContainsString('KVS Interactive Shell', $output);
        } finally {
            if ($previousKvsEnv === false) {
                putenv('KVS_ENV');
            } else {
                putenv('KVS_ENV=' . $previousKvsEnv);
            }
            if ($previousAllowEval === false) {
                putenv('KVS_ALLOW_EVAL');
            } else {
                putenv('KVS_ALLOW_EVAL=' . $previousAllowEval);
            }
        }
    }

    public function testShellRejectsMissingBootstrapFileBeforeStartingPsysh(): void
    {
        $previousAllowEval = getenv('KVS_ALLOW_EVAL');
        putenv('KVS_ALLOW_EVAL=true');

        try {
            $this->tester->execute([
                '--bootstrap' => $this->tempDir . '/missing-bootstrap.php',
            ]);
        } finally {
            if ($previousAllowEval === false) {
                putenv('KVS_ALLOW_EVAL');
            } else {
                putenv('KVS_ALLOW_EVAL=' . $previousAllowEval);
            }
        }

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Bootstrap file not found or not readable', $output);
        $this->assertStringNotContainsString('KVS Interactive Shell', $output);
    }

    public function testShellRejectsMissingIncludeFileBeforeStartingPsysh(): void
    {
        $previousAllowEval = getenv('KVS_ALLOW_EVAL');
        putenv('KVS_ALLOW_EVAL=true');

        try {
            $this->tester->execute([
                '--includes' => [$this->tempDir . '/missing-include.php'],
            ]);
        } finally {
            if ($previousAllowEval === false) {
                putenv('KVS_ALLOW_EVAL');
            } else {
                putenv('KVS_ALLOW_EVAL=' . $previousAllowEval);
            }
        }

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Include file not found or not readable', $output);
        $this->assertStringNotContainsString('KVS Interactive Shell', $output);
    }

    public function testShellRequiresPsySH(): void
    {
        if (!class_exists('Psy\Shell')) {
            $previousKvsEnv = getenv('KVS_ENV');
            putenv('KVS_ENV=dev');

            try {
                $this->tester->execute([]);
            } finally {
                if ($previousKvsEnv === false) {
                    putenv('KVS_ENV');
                } else {
                    putenv('KVS_ENV=' . $previousKvsEnv);
                }
            }

            $output = $this->tester->getDisplay();
            $this->assertEquals(1, $this->tester->getStatusCode());
            $this->assertStringContainsString('PsySH is not installed', $output);
        } else {
            // If PsySH is installed, we can't really test the interactive shell
            // Just verify the command configuration is correct
            $this->assertTrue(class_exists('Psy\Shell'));
        }
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('provideStandaloneBootstraps')]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testShellBootstrapLoadsStandalonePhpFileFromItsOwnDirectory(bool $namespaced, bool $relative): void
    {
        $customDir = $this->tempDir . '/bootstrap';
        mkdir($customDir, 0755);
        $bootstrapFile = $customDir . '/custom-bootstrap.php';
        file_put_contents(
            $customDir . '/helper.php',
            '<?php $GLOBALS["shell_bootstrap_test_helper"] = "ready";'
        );
        file_put_contents(
            $bootstrapFile,
            ($namespaced ? "<?php\nnamespace ShellBootstrapFixture;\n" : "<?php\n")
            . 'require __DIR__ . "/helper.php";' . "\n"
            . '$GLOBALS["shell_bootstrap_test_dir"] = __DIR__;' . "\n"
            . '$GLOBALS["shell_bootstrap_test_path"] = $kvsPath;' . "\n"
            . '$GLOBALS["shell_bootstrap_test_config"] = is_array($config);' . "\n"
        );
        $originalCwd = getcwd();
        if ($relative) {
            chdir($customDir);
        }

        try {
            $input = new \Symfony\Component\Console\Input\ArrayInput(
                ['--bootstrap' => $relative ? './custom-bootstrap.php' : $bootstrapFile],
                $this->command->getDefinition()
            );
            $method = new \ReflectionMethod(ShellCommand::class, 'createBootstrap');
            $code = $method->invoke($this->command, $input);
            $this->assertIsString($code);
            token_get_all($code, TOKEN_PARSE);

            $generatedFile = $this->tempDir . '/generated-bootstrap.php';
            file_put_contents($generatedFile, $code);
            (static function (string $file): void {
                require $file;
            })($generatedFile);

            $this->assertSame('ready', $GLOBALS['shell_bootstrap_test_helper']);
            $this->assertSame(realpath($customDir), $GLOBALS['shell_bootstrap_test_dir']);
            $this->assertSame($this->tempDir, $GLOBALS['shell_bootstrap_test_path']);
            $this->assertTrue($GLOBALS['shell_bootstrap_test_config']);
        } finally {
            if (is_string($originalCwd)) {
                chdir($originalCwd);
            }
            unset(
                $GLOBALS['shell_bootstrap_test_helper'],
                $GLOBALS['shell_bootstrap_test_dir'],
                $GLOBALS['shell_bootstrap_test_path'],
                $GLOBALS['shell_bootstrap_test_config']
            );
        }
    }

    /**
     * @return list<array{bool, bool}>
     */
    public static function provideStandaloneBootstraps(): array
    {
        return [
            [false, false],
            [true, false],
            [false, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideBootstrapFailures')]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testShellReportsBootstrapErrorsBeforeStartingRepl(string $code, string $expectedError): void
    {
        $bootstrapFile = $this->tempDir . '/broken-bootstrap.php';
        file_put_contents($bootstrapFile, $code);
        $command = new class ($this->config) extends ShellCommand {
            protected function getMysqliConnection(bool $quiet = false): ?\mysqli
            {
                return null;
            }
        };
        $tester = new CommandTester($command);
        $originalEnv = getenv('KVS_ENV');
        putenv('KVS_ENV=dev');

        try {
            $tester->execute(['--bootstrap' => $bootstrapFile], ['decorated' => false]);

            $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString('Failed to load shell bootstrap:', $tester->getDisplay());
            $this->assertStringContainsString($expectedError, $tester->getDisplay());
            $this->assertStringNotContainsString('kvs>>>', $tester->getDisplay());
        } finally {
            if ($originalEnv === false) {
                putenv('KVS_ENV');
            } else {
                putenv('KVS_ENV=' . $originalEnv);
            }
        }
    }

    /**
     * @return list<array{string, string}>
     */
    public static function provideBootstrapFailures(): array
    {
        return [
            ["<?php\nfunction broken_bootstrap( {", 'syntax error'],
            ["<?php\nthrow new RuntimeException('bootstrap sentinel error');", 'bootstrap sentinel error'],
        ];
    }
}
