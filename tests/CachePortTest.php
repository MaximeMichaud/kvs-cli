<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Command\System\BenchmarkCommand;
use KVS\CLI\Command\System\CheckCommand;
use KVS\CLI\Command\System\StatusCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

class CachePortTest extends TestCase
{
    /**
     * @dataProvider cacheDiagnostics
     * @param class-string<BaseCommand> $class
     */
    public function testDiagnosticsUseTheConfiguredPortForIntegersAndStrings(string $class): void
    {
        $tempDir = TestHelper::createTempDir('kvs-cache-port-test-');
        TestHelper::createMockDbConfig($tempDir);
        $server = new Process([PHP_BINARY, '-r', $this->cacheServerScript()]);
        $server->setTimeout(10);

        try {
            $server->start();
            $ready = $server->waitUntil(
                static fn(string $type, string $buffer): bool => $type === Process::OUT && str_contains($buffer, "\n")
            );
            $this->assertTrue($ready, $server->getErrorOutput());
            $ports = json_decode(trim($server->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            if (isset($ports['error'])) {
                $this->markTestSkipped('Could not reserve isolated loopback endpoints: ' . $ports['error']);
            }
            $port = $ports['configured'];
            $this->assertIsInt($port);
            $this->assertNotSame(11211, $port);

            $cases = [
                [$port, $port, 768],
                [(string) $port, $port, 768],
                ['invalid', 11211, 512],
                [null, 11211, 512],
            ];
            foreach ($cases as [$configuredPort, $expectedPort, $expectedMemory]) {
                file_put_contents(
                    $tempDir . '/admin/include/setup.php',
                    '<?php $config = ' . var_export([
                        'project_url' => '',
                        'memcache_server' => '127.0.0.93',
                        'memcache_port' => $configuredPort,
                    ], true) . ';'
                );
                $config = new Configuration(['path' => $tempDir]);
                $command = $this->getMockBuilder($class)
                    ->setConstructorArgs([$config])
                    ->onlyMethods(['isDockerMode'])
                    ->getMock();
                $command->method('isDockerMode')->willReturn(false);
                (new \ReflectionMethod(BaseCommand::class, 'initialize'))->invoke(
                    $command,
                    new ArrayInput([]),
                    new BufferedOutput()
                );

                if ($class === BenchmarkCommand::class) {
                    $result = (new \ReflectionMethod($class, 'collectConfigData'))->invoke($command);
                    $this->assertTrue($result['cache']['connected']);
                    $this->assertSame($expectedMemory, $result['cache']['memory_mb']);
                } elseif ($class === CheckCommand::class) {
                    $result = (new \ReflectionMethod($class, 'checkMemcached'))->invoke($command, false);
                    $this->assertTrue($result['connected']);
                    $this->assertSame('127.0.0.93:' . $expectedPort, $result['server']);
                    $this->assertSame($expectedMemory, $result['memory_mb']);
                } else {
                    $result = (new \ReflectionMethod($class, 'checkMemcached'))->invoke($command);
                    $this->assertTrue($result['available']);
                    $this->assertSame('127.0.0.93:' . $expectedPort, $result['host']);
                }
            }
        } finally {
            $server->stop(0);
            TestHelper::removeDir($tempDir);
        }
    }

    /** @return array<string, array{class-string<BaseCommand>}> */
    public static function cacheDiagnostics(): array
    {
        return [
            'system check' => [CheckCommand::class],
            'system status' => [StatusCommand::class],
            'benchmark configuration' => [BenchmarkCommand::class],
        ];
    }

    private function cacheServerScript(): string
    {
        return <<<'PHP'
$servers = [];
$ports = [];
foreach (['default' => 11211, 'configured' => 0] as $name => $port) {
    $server = @stream_socket_server('tcp://127.0.0.93:' . $port, $errno, $errstr);
    if ($server === false) {
        echo json_encode(['error' => $errstr]), "\n";
        exit(1);
    }
    stream_set_blocking($server, false);
    $servers[$name] = $server;
    $address = stream_socket_get_name($server, false);
    $ports[$name] = (int) substr(strrchr($address, ':'), 1);
}
echo json_encode($ports), "\n";
flush();
while (true) {
    $read = array_values($servers);
    $write = $except = null;
    if (stream_select($read, $write, $except, 0, 100000) === 0) {
        continue;
    }
    foreach ($read as $server) {
        $connection = @stream_socket_accept($server, 0);
        if ($connection === false) {
            continue;
        }
        stream_set_timeout($connection, 1);
        $request = strtolower(trim((string) fgets($connection, 1024)));
        $bytes = (array_search($server, $servers, true) === 'configured' ? 768 : 512) * 1024 * 1024;
        $response = match ($request) {
            'stats' => "STAT limit_maxbytes $bytes\r\nEND\r\n",
            'version' => "VERSION 1.6.0\r\n",
            default => "ERROR\r\n",
        };
        fwrite($connection, $response);
        fclose($connection);
    }
}
PHP;
    }
}
