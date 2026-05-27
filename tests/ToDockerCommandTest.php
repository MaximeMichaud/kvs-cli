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
        $this->tempDir = TestHelper::createTempDir('kvs-to-docker-test-');
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
            '--force' => true,
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
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Dry run mode', $output);
        $this->assertStringContainsString('Clone KVS-Install', $output);
        $this->assertStringContainsString('Export source database', $output);
        $this->assertStringContainsString('KVS-Install setup (headless)', $output);
        $this->assertStringContainsString('Import database', $output);
    }

    public function testToDockerDryRunUsesProvidedEmail(): void
    {
        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'myemail@mycompany.com',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('EMAIL=myemail@mycompany.com', $output);
        $this->assertStringNotContainsString('EMAIL=admin@test.example.com', $output);
    }

    public function testToDockerDryRunUsesSanitizedMariaDbContainerName(): void
    {
        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'test@test.com',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('docker exec -i kvs-test-example-com-mariadb', $output);
        $this->assertStringNotContainsString('docker exec -i kvs-test.example.com-mariadb', $output);
    }

    public function testToDockerDryRunUsesDomainDatabaseName(): void
    {
        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'test@test.com',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('mariadb test_example_com < /tmp/kvs-migration.sql', $output);
        $this->assertStringNotContainsString('mariadb kvs < /tmp/kvs-migration.sql', $output);
    }

    public function testToDockerDryRunShowsSourceDatabaseConnectionOptions(): void
    {
        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'test@test.com',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();
        $dbConfig = $this->config->getDatabaseConfig();
        $host = $dbConfig['host'];
        $port = 3306;
        if (str_contains($host, ':')) {
            [$host, $portString] = explode(':', $host, 2);
            $port = (int) $portString;
        }

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString("MYSQL_PWD='<DB_PASS>' mariadb-dump", $output);
        $this->assertStringContainsString('--host=' . escapeshellarg($host), $output);
        $this->assertStringContainsString('--port=' . $port, $output);
        $this->assertStringContainsString('--user=' . escapeshellarg($dbConfig['user']), $output);
        $this->assertStringContainsString(escapeshellarg($dbConfig['database']) . ' > /tmp/kvs-migration.sql', $output);
        $this->assertStringNotContainsString($dbConfig['password'], $output);
    }

    public function testToDockerDryRunNoContentDoesNotShowContentCopy(): void
    {
        mkdir($this->tempDir . '/contents/videos_sources/1000', 0755, true);
        file_put_contents($this->tempDir . '/contents/videos_sources/1000/source.mp4', 'video');

        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--dry-run' => true,
            '--no-content' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Include Content', $output);
        $this->assertStringNotContainsString('Copy content', $output);
        $this->assertStringNotContainsString('rsync -av', $output);
    }

    public function testToDockerCommandShowsMigrationPlan(): void
    {
        $this->tester->execute([
            '--domain' => 'example.com',
            '--email' => 'admin@example.com',
            '--dry-run' => true,
            '--force' => true,
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
            '--force' => true,
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
            '--force' => true,
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
            '--force' => true,
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
            '--force' => true,
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
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Include Content', $output);
        $this->assertStringContainsString('No', $output);
    }
}
