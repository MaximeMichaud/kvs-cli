<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Benchmark\RemoteBenchmarkClient;
use KVS\CLI\Benchmark\BenchmarkResult;
use KVS\CLI\Benchmark\CpuBench;
use KVS\CLI\Benchmark\FileIOBench;
use KVS\CLI\Service\TempFileManager;
use Symfony\Component\Process\Process;
use KVS\CLI\Util\FpmConfigReader;
use PHPUnit\Framework\TestCase;

class GeneratedHttpEndpointTest extends TestCase
{
    public function testRemoteBenchmarkForbiddenResponseReturnsInsteadOfDying(): void
    {
        $client = new RemoteBenchmarkClient(TestHelper::createTestConfiguration(TestHelper::createTestKvsInstallation()));
        $method = new \ReflectionMethod(RemoteBenchmarkClient::class, 'generateBenchmarkCode');
        $code = $method->invoke($client, 'expected-token');
        $this->assertIsString($code);

        $output = $this->includeGeneratedEndpoint($code, 'wrong-token');

        $this->assertSame('{"error":"Forbidden"}', $output);
        $this->assertStringNotContainsString('die(', $code);
    }

    public function testGeneratedBenchmarkExecutesTheCompleteSharedWorkloadSuite(): void
    {
        $dir = TestHelper::createTestKvsInstallation();
        TestHelper::createMockDbConfig($dir, ['user' => '', 'password' => '', 'database' => '']);
        $client = new RemoteBenchmarkClient(TestHelper::createTestConfiguration($dir));
        $client->setIterations(10, 10, 10, 10);
        $method = new \ReflectionMethod(RemoteBenchmarkClient::class, 'generateBenchmarkCode');
        $file = $dir . '/endpoint.php';
        file_put_contents($file, $method->invoke($client, 'expected-token'));

        try {
            // No optional extensions: never connect to a real cache or database.
            $process = new Process([
                PHP_BINARY, '-n', '-r',
                '$_GET = ["t" => "expected-token"]; include ' . var_export($file, true) . ';',
            ]);
            $process->mustRun();
            $remote = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('error', $remote);
            $local = new BenchmarkResult();
            (new CpuBench(10))->run($local);
            (new FileIOBench(10))->run($local);
            $this->assertSame(array_keys($local->getCpuResults()), array_keys($remote['cpu']));
            $this->assertSame(array_keys($local->getFileIOResults()), array_keys($remote['fileio']));
            foreach ($remote['cpu'] as $measurement) {
                $this->assertGreaterThan(0, $measurement['ops_sec']);
                $this->assertSame(10, $measurement['samples']);
            }
            $this->assertSame(10, $remote['fileio']['write_sync']['samples']);
            $this->assertFalse($remote['cache']['connected']);
            $this->assertFalse($remote['database']['connected']);
        } finally {
            TempFileManager::cleanupAll();
            TestHelper::removeDir($dir);
        }
    }

    public function testFpmConfigForbiddenResponseReturnsInsteadOfDying(): void
    {
        $reader = new FpmConfigReader(TestHelper::createTestConfiguration(TestHelper::createTestKvsInstallation()));
        $method = new \ReflectionMethod(FpmConfigReader::class, 'generatePhpCode');
        $code = $method->invoke($reader, 'expected-token');
        $this->assertIsString($code);

        $output = $this->includeGeneratedEndpoint($code, 'wrong-token');

        $this->assertSame('Forbidden', $output);
        $this->assertStringNotContainsString('die(', $code);
    }

    public function testFpmConfigEndpointReportsServerSoftware(): void
    {
        $reader = new FpmConfigReader(TestHelper::createTestConfiguration(TestHelper::createTestKvsInstallation()));
        $method = new \ReflectionMethod(FpmConfigReader::class, 'generatePhpCode');
        $code = $method->invoke($reader, 'expected-token');
        $this->assertIsString($code);

        $previousServerSoftware = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = 'nginx';

        try {
            $output = $this->includeGeneratedEndpoint($code, 'expected-token', false);
        } finally {
            if ($previousServerSoftware === null) {
                unset($_SERVER['SERVER_SOFTWARE']);
            } else {
                $_SERVER['SERVER_SOFTWARE'] = $previousServerSoftware;
            }
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('nginx', $decoded['server_software'] ?? null);
    }

    private function includeGeneratedEndpoint(string $code, string $token, bool $expectEarlyReturn = true): string
    {
        $dir = TestHelper::createTempDir('generated-endpoint-');
        $file = $dir . '/endpoint.php';
        file_put_contents($file, $code);

        $previousGet = $_GET;
        $_GET = ['t' => $token];

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $result = @include $file;
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $_GET = $previousGet;
            TestHelper::removeDir($dir);
        }

        if ($expectEarlyReturn) {
            $this->assertNull($result);
        } else {
            $this->assertSame(1, $result);
        }

        return $output;
    }
}
