<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\EmailCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class EmailCommandTest extends TestCase
{
    private Configuration $config;
    private EmailCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new EmailCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);

        try {
            $this->db = TestHelper::getPDO();
        } catch (\PDOException $e) {
            $this->markTestSkipped('Cannot connect to test database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testEmailShowBasic(): void
    {
        $this->tester->execute([
            'action' => 'show'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEmailShowJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'show',
            '--format' => 'json'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertJson($output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEmailDefaultAction(): void
    {
        $this->tester->execute([]);

        // Default action is show
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEmailCommandMetadata(): void
    {
        $this->assertEquals('system:email', $this->command->getName());
        $this->assertStringContainsString('email', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('email', $aliases);
    }

    public function testEmailTestMissingRecipient(): void
    {
        $this->tester->execute([
            'action' => 'test'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEmailTestInvalidEmail(): void
    {
        $this->tester->execute([
            'action' => 'test',
            '--to' => 'invalid-email'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('invalid', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEmailSetNoOptions(): void
    {
        $this->tester->execute([
            'action' => 'set'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('no settings', strtolower($output));
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEmailSetInvalidMailer(): void
    {
        $this->tester->execute([
            'action' => 'set',
            '--mailer' => 'invalid'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('invalid mailer', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEmailSetInvalidFromEmail(): void
    {
        $this->tester->execute([
            'action' => 'set',
            '--from-email' => 'not-an-email'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('invalid', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEmailSetInvalidSmtpSecurity(): void
    {
        $this->tester->execute([
            'action' => 'set',
            '--smtp-security' => 'invalid'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('invalid smtp security', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEmailSetInvalidSmtpPort(): void
    {
        $this->tester->execute([
            'action' => 'set',
            '--smtp-port' => '99999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('invalid', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
