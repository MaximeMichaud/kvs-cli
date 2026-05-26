<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\StatusCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class StatusCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private StatusCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data', 0755, true);
        mkdir($this->tempDir . '/content', 0755, true);

        // Create config files
        TestHelper::createMockDbConfig($this->tempDir);

        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_version" => "6.3.2", "project_name" => "Test KVS"];'
        );

        file_put_contents(
            $this->tempDir . '/admin/include/version.php',
            '<?php define("KVS_VERSION", "6.3.2");'
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new StatusCommand($this->config);

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

    public function testStatusCommandOutput(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Check for expected sections
        $this->assertStringContainsString('KVS System Status', $output);
        $this->assertStringContainsString('Installation', $output);
        $this->assertStringContainsString('Version', $output);
        $this->assertStringContainsString('Path', $output);
        $this->assertStringContainsString($this->tempDir, $output);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testStatusWithVerboseOutput(): void
    {
        $this->tester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        $output = $this->tester->getDisplay();

        // Verbose mode should show more details
        $this->assertStringContainsString('Database', $output);
        $this->assertStringContainsString('PHP Version', $output);
        $this->assertStringContainsString(PHP_VERSION, $output);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testStatusShowsSystemInfo(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should show system information (Installation section has this info)
        $this->assertStringContainsString('Installation', $output);

        // Should show version info
        $this->assertStringContainsString('Version', $output);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testStatusChecksDiskSpace(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Should show disk information
        $this->assertStringContainsString('Disk', $output);
        $this->assertMatchesRegularExpression('/\d+(\.\d+)?\s*(TB|GB|MB)/', $output); // Should show size

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testStatusAverageTimeUsesBackgroundTaskHistory(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_background_tasks (task_id INTEGER, status_id INTEGER, effective_duration INTEGER, added_date TEXT)');
        $db->exec('CREATE TABLE ktvs_background_tasks_history (task_id INTEGER, status_id INTEGER, effective_duration INTEGER)');
        $db->exec("INSERT INTO ktvs_background_tasks (task_id, status_id, effective_duration, added_date) VALUES (1, 3, 999, '2026-05-26 12:00:00')");
        $db->exec('DELETE FROM ktvs_background_tasks WHERE status_id = 3');
        $db->exec('INSERT INTO ktvs_background_tasks_history (task_id, status_id, effective_duration) VALUES (10, 3, 30)');
        $db->exec('INSERT INTO ktvs_background_tasks_history (task_id, status_id, effective_duration) VALUES (11, 3, 90)');

        $command = new class (TestHelper::createTestConfiguration($this->tempDir), $db) extends StatusCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('Average Time', $display);
        $this->assertStringContainsString('1m 0s', $display);
    }

    public function testStatusReusesSingleDatabaseConnection(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_background_tasks (task_id INTEGER, status_id INTEGER, added_date TEXT)');
        $db->exec('CREATE TABLE ktvs_background_tasks_history (task_id INTEGER, status_id INTEGER, effective_duration INTEGER)');

        $command = new class (TestHelper::createTestConfiguration($this->tempDir), $db) extends StatusCommand {
            public int $connectionCalls = 0;

            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                ++$this->connectionCalls;

                return $this->testDb;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertSame(1, $command->connectionCalls);
    }

    public function testStatusDetectsRedisProtocolCacheWithoutDockerCli(): void
    {
        $portFile = $this->tempDir . '/redis-port.txt';
        $serverScript = $this->tempDir . '/redis-server.php';
        file_put_contents(
            $serverScript,
            <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    exit(1);
}
$address = stream_socket_get_name($server, false);
if ($address === false) {
    exit(1);
}
file_put_contents($argv[1], $address);
$connection = @stream_socket_accept($server, 10);
if (is_resource($connection)) {
    $line = fgets($connection);
    fwrite($connection, is_string($line) && stripos($line, 'PING') !== false ? "+PONG\r\n" : "-ERR\r\n");
    fclose($connection);
}
fclose($server);
PHP
        );

        $process = proc_open(
            [PHP_BINARY, $serverScript, $portFile],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        $this->assertIsResource($process);

        try {
            $address = $this->waitForPortFile($portFile);
            $parts = explode(':', $address);
            $port = (int) end($parts);
            $this->assertGreaterThan(0, $port);

            file_put_contents(
                $this->tempDir . '/admin/include/setup.php',
                "<?php\n"
                . '$config = ["project_version" => "6.3.2", "project_name" => "Test KVS", '
                . "\"memcache_server\" => \"127.0.0.1\", \"memcache_port\" => $port];\n"
            );

            $db = new PDO('sqlite::memory:');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec('CREATE TABLE ktvs_background_tasks (task_id INTEGER, status_id INTEGER, added_date TEXT)');
            $db->exec('CREATE TABLE ktvs_background_tasks_history (task_id INTEGER, status_id INTEGER, effective_duration INTEGER)');

            $command = new class (new Configuration(['path' => $this->tempDir]), $db) extends StatusCommand {
                public function __construct(Configuration $config, private PDO $testDb)
                {
                    parent::__construct($config);
                }

                protected function getDatabaseConnection(bool $quiet = false): ?PDO
                {
                    return $this->testDb;
                }
            };
            $tester = new CommandTester($command);

            $tester->execute([]);

            $display = $tester->getDisplay();
            $this->assertSame(0, $tester->getStatusCode(), $display);
            $this->assertStringContainsString('Dragonfly', $display);
            $this->assertStringContainsString("127.0.0.1:$port", $display);
            $this->assertStringContainsString('Connected', $display);
        } finally {
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === true) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    public function testStatusExitCode(): void
    {
        // StatusCommand doesn't support --format option
        // Just verify the command runs successfully
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('KVS System Status', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    private function waitForPortFile(string $portFile): string
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (is_file($portFile)) {
                $address = trim((string) file_get_contents($portFile));
                if ($address !== '') {
                    return $address;
                }
            }
            usleep(10000);
        }

        $this->fail('Redis test server did not start');
    }
}
