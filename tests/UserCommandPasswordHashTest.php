<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;

class UserCommandPasswordHashTest extends TestCase
{
    private string $kvsPath;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testGeneratedPasswordHashUsesKvsNativeAlgorithm(): void
    {
        $command = new class (TestHelper::createTestConfiguration($this->kvsPath)) extends UserCommand {
            public function hashForTest(string $password): string
            {
                return $this->generateKvsPasswordHash($password);
            }
        };

        $password = 'CodexPassword123';
        $hash = $command->hashForTest($password);

        $this->assertNotSame(md5($password), $hash);
        $this->assertSame(\crypt($password, '$2a$07$aa5f7b4693ccdbdd792f6a998e9ed446$'), $hash);
        $this->assertStringStartsWith('$2a$07$', $hash);
    }
}
