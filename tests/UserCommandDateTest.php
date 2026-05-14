<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;

class UserCommandDateTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kvs-user-date-test-' . uniqid();
        TestHelper::createMockKvsInstallation($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            TestHelper::removeDir($this->tempDir);
        }
    }

    public function testZeroDatesUseFallback(): void
    {
        $command = new UserCommand(new Configuration(['path' => $this->tempDir]));
        $method = new \ReflectionMethod($command, 'formatDate');
        $method->setAccessible(true);

        $this->assertSame('Never', $method->invoke($command, '0000-00-00 00:00:00'));
        $this->assertSame('Unknown', $method->invoke($command, '0000-00-00', 'Y-m-d H:i:s', 'Unknown'));
    }
}
