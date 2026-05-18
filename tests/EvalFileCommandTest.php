<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\EvalFileCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class EvalFileCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private EvalFileCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);

        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new EvalFileCommand($this->config);

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

    public function testEvalFileExecution(): void
    {
        // Create a test PHP file
        $testFile = $this->tempDir . '/test.php';
        file_put_contents($testFile, '<?php echo "Test output";');

        $this->tester->execute(['file' => $testFile]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Test output', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEvalFileWithKvsContext(): void
    {
        // Create a test PHP file that uses KVS context
        $testFile = $this->tempDir . '/test_context.php';
        file_put_contents($testFile, '<?php echo "KVS Path: " . $kvsConfig->getKvsPath();');

        $this->tester->execute(['file' => $testFile]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('KVS Path: ' . $this->tempDir, $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEvalFileExposesKvsConfigArrayGlobally(): void
    {
        $testFile = $this->tempDir . '/test_config.php';
        file_put_contents($testFile, '<?php global $config; echo $config["tables_prefix"];');

        $this->tester->execute(['file' => $testFile]);

        $this->assertStringContainsString('ktvs_', $this->tester->getDisplay());
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEvalFileUsesMysqliConnectionForKvsContext(): void
    {
        $testFile = $this->tempDir . '/test_mysqli_context.php';
        file_put_contents($testFile, '<?php echo $db === null ? "null-db" : get_class($db);');

        $command = new class ($this->config) extends EvalFileCommand {
            public bool $mysqliConnectionRequested = false;

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                throw new \RuntimeException('Eval-file should not request a PDO connection');
            }

            protected function getMysqliConnection(bool $quiet = false): ?\mysqli
            {
                $this->mysqliConnectionRequested = true;
                return null;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute(['file' => $testFile]);

        $this->assertTrue($command->mysqliConnectionRequested);
        $this->assertStringContainsString('null-db', $tester->getDisplay());
        $this->assertEquals(0, $tester->getStatusCode());
    }

    public function testEvalFileNotFound(): void
    {
        $this->tester->execute(['file' => '/nonexistent/file.php']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEvalFileCommandMetadata(): void
    {
        $this->assertEquals('eval-file', $this->command->getName());
        $this->assertStringContainsString('Execute a PHP file', $this->command->getDescription());
    }
}
