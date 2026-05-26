<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Benchmark\RemoteBenchmarkClient;
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

    private function includeGeneratedEndpoint(string $code, string $token): string
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

        $this->assertNull($result);

        return $output;
    }
}
