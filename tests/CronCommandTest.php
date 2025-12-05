<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\CronCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class CronCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private CronCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation with cron files
        $this->tempDir = sys_get_temp_dir() . '/kvs-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);

        // Create mock cron files
        file_put_contents($this->tempDir . '/admin/include/cron.php', '<?php echo "Main cron";');
        file_put_contents($this->tempDir . '/admin/include/cron_conversion.php', '<?php echo "Conversion cron";');
        file_put_contents($this->tempDir . '/admin/include/cron_optimize.php', '<?php echo "Optimize cron";');
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new CronCommand($this->config);

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

    public function testCronRunAll(): void
    {
        $this->tester->execute(['action' => 'run']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Running cron jobs', $output);
        $this->assertStringContainsString('Main cron', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCronRunSpecificJob(): void
    {
        $this->tester->execute([
            'action' => 'run',
            '--job' => 'conversion'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Running conversion cron', $output);
        $this->assertStringContainsString('Conversion cron', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCronList(): void
    {
        $this->tester->execute(['action' => 'list']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Available cron jobs', $output);
        $this->assertStringContainsString('cron.php', $output);
        $this->assertStringContainsString('cron_conversion.php', $output);
        $this->assertStringContainsString('cron_optimize.php', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCronStatus(): void
    {
        $this->tester->execute(['action' => 'status']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Cron job status', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testCronInvalidAction(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid action', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testCronInvalidJob(): void
    {
        $this->tester->execute([
            'action' => 'run',
            '--job' => 'nonexistent'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
