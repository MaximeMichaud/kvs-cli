<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;

class TestHelperTest extends TestCase
{
    public function testCreateTestKvsInstallationUsesDedicatedTestDatabaseConfig(): void
    {
        $customPort = (string) random_int(20000, 29999);
        $previousEnv = $this->setEnv([
            'KVS_DB_HOST' => false,
            'KVS_DB_PORT' => false,
            'KVS_TEST_DB_HOST' => '127.0.0.1',
            'KVS_TEST_DB_PORT' => $customPort,
            'KVS_TEST_DB_USER' => 'kvs_user',
            'KVS_TEST_DB_PASS' => 'kvs_pass',
            'KVS_TEST_DB_NAME' => 'kvs_test',
        ]);

        $path = TestHelper::createTestKvsInstallation();

        try {
            $config = new Configuration(['path' => $path]);
            $dbConfig = $config->getDatabaseConfig();

            $this->assertSame($path, $config->getKvsPath());
            $this->assertSame('127.0.0.1:' . $customPort, $dbConfig['host']);
            $this->assertSame('kvs_user', $dbConfig['user']);
            $this->assertSame('kvs_pass', $dbConfig['password']);
            $this->assertSame('kvs_test', $dbConfig['database']);
            $this->assertSame($path, $config->getProjectConfig()['project_path']);
            $this->assertSame('ktvs_', $config->getTablePrefix());
        } finally {
            TestHelper::removeDir($path);
            $this->restoreEnv($previousEnv);
        }
    }

    public function testDatabaseSkipMessageDoesNotExposeCredentials(): void
    {
        $previousEnv = $this->setEnv([
            'KVS_TEST_DB_HOST' => '127.0.0.1',
            'KVS_TEST_DB_PORT' => '3306',
            'KVS_TEST_DB_USER' => 'secret_user',
            'KVS_TEST_DB_PASS' => 'secret_pass',
            'KVS_TEST_DB_NAME' => 'kvs_test',
        ]);

        try {
            $message = TestHelper::databaseSkipMessage(new \PDOException(
                "SQLSTATE[HY000] [1045] Access denied for user 'secret_user'@'host' (using password: YES)"
            ));

            $this->assertStringContainsString('SQLSTATE[HY000] [1045]', $message);
            $this->assertStringNotContainsString('secret_user', $message);
            $this->assertStringNotContainsString('secret_pass', $message);
        } finally {
            $this->restoreEnv($previousEnv);
        }
    }

    /**
     * @param array<string, string|false> $values
     * @return array<string, string|false>
     */
    private function setEnv(array $values): array
    {
        $previous = [];

        foreach ($values as $name => $value) {
            $previous[$name] = getenv($name);
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }

        return $previous;
    }

    /**
     * @param array<string, string|false> $values
     */
    private function restoreEnv(array $values): void
    {
        foreach ($values as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }
}
