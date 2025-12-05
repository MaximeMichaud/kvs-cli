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

    public function testEvalWithKvsContext(): void
    {
        $this->tester->execute(['code' => 'echo $config->getKvsPath();']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString($this->tempDir, $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEvalCommandMetadata(): void
    {
        $this->assertEquals('eval', $this->command->getName());
        $this->assertStringContainsString('Evaluate PHP code', $this->command->getDescription());
    }
}
