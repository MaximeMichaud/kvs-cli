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
        $kvsPath = TestHelper::createTestKvsInstallation();

        $this->config = TestHelper::createTestConfiguration($kvsPath);
        $this->command = new EmailCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);

        if (TestHelper::isCommandDefinitionTest($this->name())) {
            return;
        }

        try {
            $this->db = TestHelper::getPDO();
        } catch (\PDOException $e) {
            $this->markTestSkipped(TestHelper::databaseSkipMessage($e));
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testEmailShowBasic(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEmailShowJsonFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            '--format' => 'json'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertJson($output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEmailDefaultAction(): void
    {
        $this->tester->execute(['--force' => true]);

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
            '--force' => true,
            'action' => 'test'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEmailTestInvalidEmail(): void
    {
        $this->tester->execute([
            '--force' => true,
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
            '--force' => true,
            'action' => 'set'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('no settings', strtolower($output));
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEmailSetInvalidMailer(): void
    {
        $this->tester->execute([
            '--force' => true,
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
            '--force' => true,
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
            '--force' => true,
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
            '--force' => true,
            'action' => 'set',
            '--smtp-port' => '99999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('invalid', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEmailSetInvalidDebugLevel(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'set',
            '--debug' => '5'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('invalid debug level', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEmailSetInvalidSmtpTimeout(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'set',
            '--smtp-timeout' => '9999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('invalid', strtolower($output));
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testEmailLogAction(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'log'
        ]);

        // Should succeed (may show "no log files" warning)
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEmailTemplatesAction(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'templates'
        ]);

        // Should succeed (may show templates or "not found")
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEmailTemplatesJsonFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'templates',
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertJson($output);
        $this->assertIsArray(json_decode($output, true));
        $this->assertEquals(0, $this->tester->getStatusCode());
    }
}
