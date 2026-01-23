<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Migrate\ToDockerCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class ToDockerCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private ToDockerCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kvs-to-docker-test-' . uniqid();
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data', 0755, true);
        mkdir($this->tempDir . '/contents', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);

        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_version" => "6.4.0"];'
        );

        file_put_contents(
            $this->tempDir . '/admin/include/version.php',
            "<?php \$config['project_version'] = '6.4.0';"
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new ToDockerCommand($this->config);

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

    public function testToDockerCommandShowsTitle(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--dry-run' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('KVS Migration to Docker', $output);
    }

    public function testToDockerCommandDryRunShowsSteps(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--dry-run' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Dry run mode', $output);
        $this->assertStringContainsString('Clone KVS-Install', $output);
        $this->assertStringContainsString('Export source database', $output);
        $this->assertStringContainsString('KVS-Install setup (headless)', $output);
        $this->assertStringContainsString('Import database', $output);
    }

    public function testToDockerCommandShowsMigrationPlan(): void
    {
        $this->tester->execute([
            '--domain' => 'example.com',
            '--email' => 'admin@example.com',
            '--dry-run' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Migration Plan', $output);
        $this->assertStringContainsString('Source Path', $output);
        $this->assertStringContainsString('Target Domain', $output);
        $this->assertStringContainsString('example.com', $output);
    }

    public function testToDockerCommandWithInvalidSource(): void
    {
        $this->tester->execute([
            'source' => '/nonexistent/path',
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testToDockerCommandShowsMariaDbChoice(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--db' => '3',
            '--dry-run' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('MariaDB 10.11 LTS', $output);
    }

    public function testToDockerCommandShowsDbChoice2(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--db' => '2',
            '--dry-run' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('MariaDB 11.4 LTS', $output);
    }

    public function testToDockerCommandShowsSslProvider(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--ssl' => '1',
            '--dry-run' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString("Let's Encrypt", $output);
    }

    public function testToDockerCommandNoContentOption(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--no-content' => true,
            '--dry-run' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Include Content', $output);
        $this->assertStringContainsString('No', $output);
    }
}
