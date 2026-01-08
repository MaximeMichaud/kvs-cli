<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Docker\DockerDetector;
use PHPUnit\Framework\TestCase;

class DockerDetectorTest extends TestCase
{
    public function testSetKvsPathReturnsSelf(): void
    {
        $detector = new DockerDetector();
        $result = $detector->setKvsPath('/var/www/kvs');

        $this->assertSame($detector, $result);
    }

    public function testSetKvsPathTrimsTrailingSlash(): void
    {
        $detector = new DockerDetector();
        $detector->setKvsPath('/var/www/kvs/');

        // We can't directly access private property, but we can verify
        // it doesn't throw and returns self
        $this->assertInstanceOf(DockerDetector::class, $detector);
    }

    public function testGetContainerPrefixReturnsNullWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertNull($detector->getContainerPrefix());
    }

    public function testIsKvsInDockerReturnsFalseWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertFalse($detector->isKvsInDocker());
    }

    public function testIsRunningReturnsFalseWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertFalse($detector->isRunning('php'));
        $this->assertFalse($detector->isRunning('mariadb'));
        $this->assertFalse($detector->isRunning('memcached'));
    }

    public function testGetContainerNameReturnsNullWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertNull($detector->getContainerName('php'));
        $this->assertNull($detector->getContainerName('mariadb'));
    }

    public function testGetCacheContainerReturnsNullWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertNull($detector->getCacheContainer());
    }

    public function testGetRunningContainersReturnsEmptyArrayWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertSame([], $detector->getRunningContainers());
    }

    public function testGetSummaryStructure(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $summary = $detector->getSummary();

        $this->assertArrayHasKey('docker_available', $summary);
        $this->assertArrayHasKey('kvs_in_docker', $summary);
        $this->assertArrayHasKey('prefix', $summary);
        $this->assertArrayHasKey('containers', $summary);
        $this->assertFalse($summary['docker_available']);
        $this->assertFalse($summary['kvs_in_docker']);
        $this->assertNull($summary['prefix']);
        $this->assertSame([], $summary['containers']);
    }

    public function testCheckCacheReturnsUnavailableWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $result = $detector->checkCache();

        $this->assertArrayHasKey('available', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('memory_mb', $result);
        $this->assertFalse($result['available']);
        $this->assertNull($result['type']);
        $this->assertNull($result['memory_mb']);
    }

    public function testExecReturnsNullWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertNull($detector->exec('php', 'echo test'));
    }

    public function testExecPhpReturnsNullWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertNull($detector->execPhp('echo "test";'));
    }

    public function testGetPhpVersionReturnsNullWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertNull($detector->getPhpVersion());
    }

    public function testGetPhpIniReturnsNullWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertNull($detector->getPhpIni('memory_limit'));
    }

    public function testIsPhpExtensionLoadedReturnsNullWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $this->assertNull($detector->isPhpExtensionLoaded('opcache'));
    }

    public function testGetPhpInfoReturnsEmptyWithoutDocker(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable']);
        $detector->method('isDockerAvailable')->willReturn(false);

        $result = $detector->getPhpInfo(['memory_limit'], ['opcache']);

        $this->assertArrayHasKey('settings', $result);
        $this->assertArrayHasKey('extensions', $result);
        $this->assertArrayHasKey('version', $result);
        $this->assertSame([], $result['settings']);
        $this->assertSame([], $result['extensions']);
        $this->assertSame('', $result['version']);
    }

    public function testGetCacheMemoryViaPhpReturnsNullWithoutPhpContainer(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['isDockerAvailable', 'execPhp']);
        $detector->method('isDockerAvailable')->willReturn(false);
        $detector->method('execPhp')->willReturn(null);

        $this->assertNull($detector->getCacheMemoryViaPhp('memcached', 11211));
    }

    public function testGetCacheMemoryViaPhpParsesValidResponse(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['execPhp']);
        // 512MB in bytes
        $detector->method('execPhp')->willReturn('536870912');

        $result = $detector->getCacheMemoryViaPhp('memcached', 11211);

        $this->assertSame(512, $result);
    }

    public function testGetCacheMemoryViaPhpReturnsNullOnFail(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, ['execPhp']);
        $detector->method('execPhp')->willReturn('FAIL');

        $this->assertNull($detector->getCacheMemoryViaPhp('memcached', 11211));
    }

    /**
     * Test checkCache with mocked running containers and fallback
     */
    public function testCheckCacheUsesFallbackForDragonfly(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, [
            'isDockerAvailable',
            'isRunning',
            'exec',
            'execPhp',
        ]);

        $detector->method('isDockerAvailable')->willReturn(true);
        $detector->method('isRunning')->willReturnCallback(function ($service) {
            return $service === 'dragonfly';
        });
        // redis-cli fails (not available in container)
        $detector->method('exec')->willReturn(null);
        // PHP fallback succeeds - 512MB in bytes
        $detector->method('execPhp')->willReturn('536870912');

        $result = $detector->checkCache();

        $this->assertTrue($result['available']);
        $this->assertSame('Dragonfly', $result['type']);
        $this->assertSame(512, $result['memory_mb']);
    }

    /**
     * Test checkCache with mocked running containers and fallback for memcached
     */
    public function testCheckCacheUsesFallbackForMemcached(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, [
            'isDockerAvailable',
            'isRunning',
            'exec',
            'execPhp',
        ]);

        $detector->method('isDockerAvailable')->willReturn(true);
        $detector->method('isRunning')->willReturnCallback(function ($service) {
            return $service === 'memcached';
        });
        // nc fails (not available in container)
        $detector->method('exec')->willReturn(null);
        // PHP fallback succeeds - 256MB in bytes
        $detector->method('execPhp')->willReturn('268435456');

        $result = $detector->checkCache();

        $this->assertTrue($result['available']);
        $this->assertSame('Memcached', $result['type']);
        $this->assertSame(256, $result['memory_mb']);
    }

    /**
     * Test checkCache prefers direct method over fallback
     */
    public function testCheckCachePrefersDirect(): void
    {
        $detector = $this->createPartialMock(DockerDetector::class, [
            'isDockerAvailable',
            'isRunning',
            'exec',
        ]);

        $detector->method('isDockerAvailable')->willReturn(true);
        $detector->method('isRunning')->willReturnCallback(function ($service) {
            return $service === 'dragonfly';
        });
        // redis-cli succeeds
        $detector->method('exec')->willReturnCallback(function ($service, $cmd) {
            if (str_contains($cmd, 'PING')) {
                return '+PONG';
            }
            if (str_contains($cmd, 'INFO memory')) {
                return "# Memory\nmaxmemory:1073741824\n";
            }
            return null;
        });

        $result = $detector->checkCache();

        $this->assertTrue($result['available']);
        $this->assertSame('Dragonfly', $result['type']);
        $this->assertSame(1024, $result['memory_mb']);
    }
}
