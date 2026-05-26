<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\EvalCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class EvalCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private EvalCommand $command;
    private CommandTester $tester;
    private string|false $originalKvsEnv;
    private string|false $originalAllowEval;

    protected function setUp(): void
    {
        $this->originalKvsEnv = getenv('KVS_ENV');
        $this->originalAllowEval = getenv('KVS_ALLOW_EVAL');
        putenv('KVS_ENV=dev');
        putenv('KVS_ALLOW_EVAL');

        // Create mock KVS installation
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);

        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new EvalCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }

        if ($this->originalKvsEnv === false) {
            putenv('KVS_ENV');
        } else {
            putenv('KVS_ENV=' . $this->originalKvsEnv);
        }

        if ($this->originalAllowEval === false) {
            putenv('KVS_ALLOW_EVAL');
        } else {
            putenv('KVS_ALLOW_EVAL=' . $this->originalAllowEval);
        }
    }

    public function testEvalSimpleExpression(): void
    {
        $this->tester->execute(['code' => 'echo "Hello World";']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Hello World', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEvalMathExpression(): void
    {
        $this->tester->execute(['code' => 'echo 2 + 2;']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('4', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEvalWithKvsPath(): void
    {
        // Uses $kvsPath variable which is available in eval context
        $this->tester->execute(['code' => 'echo $kvsPath;']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString($this->tempDir, $output);
    }

    public function testEvalExposesKvsConfigArrayGlobally(): void
    {
        $this->tester->execute(['code' => 'global $config; echo $config["tables_prefix"];']);

        $this->assertStringContainsString('ktvs_', $this->tester->getDisplay());
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEvalUsesMysqliConnectionForKvsContext(): void
    {
        $command = new class ($this->config) extends EvalCommand {
            public bool $mysqliConnectionRequested = false;

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \RuntimeException('Eval should not request a PDO connection');
            }

            protected function getMysqliConnection(bool $quiet = false): ?\mysqli
            {
                $this->mysqliConnectionRequested = true;
                return null;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute(['code' => 'echo $db === null ? "null-db" : get_class($db);']);

        $this->assertTrue($command->mysqliConnectionRequested);
        $this->assertStringContainsString('null-db', $tester->getDisplay());
        $this->assertEquals(0, $tester->getStatusCode());
    }

    public function testEvalCommandMetadata(): void
    {
        $this->assertEquals('eval', $this->command->getName());
        $this->assertStringContainsString('Execute PHP code', $this->command->getDescription());
        $this->assertContains('eval-php', $this->command->getAliases());
    }

    public function testEvalWithSkipKvsOption(): void
    {
        $this->tester->execute([
            'code' => 'echo "No KVS context";',
            '--skip-kvs' => true
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('No KVS context', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEvalSyntaxError(): void
    {
        $this->tester->execute(['code' => 'echo "unclosed string;']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Parse Error', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEvalReturnValue(): void
    {
        $this->tester->execute(['code' => 'return 42;']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('42', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEvalReturnBoolTrue(): void
    {
        $this->tester->execute(['code' => 'return true;']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('true', $output);
    }

    public function testEvalReturnBoolFalse(): void
    {
        $this->tester->execute(['code' => 'return false;']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('false', $output);
    }
}
