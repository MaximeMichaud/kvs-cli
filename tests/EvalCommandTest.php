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

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
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
