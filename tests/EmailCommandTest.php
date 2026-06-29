<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\EmailCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class EmailCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private EmailCommand $command;
    private CommandTester $tester;
    private PDO $db;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->db = $this->createDatabase();

        $this->config = TestHelper::createTestConfiguration($this->kvsPath);
        $this->command = $this->createCommand($this->db);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
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

    public function testEmailShowRejectsNonShowOptions(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            '--to' => 'recipient@example.test',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('The show action does not support --to', $this->tester->getDisplay());
    }

    public function testEmailDefaultAction(): void
    {
        $this->tester->execute(['--force' => true]);

        // Default action is show
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testEmailRejectsUnknownAction(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'unknown_action',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Unknown email action "unknown_action"', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
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

    public function testEmailTestRejectsNonTestOptionsBeforeRecipientValidation(): void
    {
        $cases = [
            ['--format', 'json', 'format'],
            ['--lines', '1', 'lines'],
            ['--smtp-host', 'smtp.example.test', 'smtp-host'],
        ];

        foreach ($cases as [$option, $value, $optionName]) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => 'test',
                '--to' => 'invalid-email',
                $option => $value,
            ]);

            $output = $tester->getDisplay();

            $this->assertSame(1, $tester->getStatusCode(), $optionName . ': ' . $output);
            $this->assertStringContainsString("test action does not support --$optionName", $output);
            $this->assertStringNotContainsString('Invalid email address format', $output);
        }
    }

    public function testEmailHelpDocumentsSmtpTestLimitation(): void
    {
        $output = $this->command->getHelp();

        $this->assertStringContainsString('The test action can send only through PHP mail().', $output);
        $this->assertStringContainsString('For SMTP test emails, use the KVS admin panel.', $output);
    }

    public function testEmailHelpDocumentsJsonLastTestMetadata(): void
    {
        $output = $this->command->getHelp();

        $this->assertStringContainsString('show --format=json includes KVS admin last-test metadata:', $output);
        $this->assertStringContainsString('test_email, test_subject, test_body.', $output);
        $this->assertStringContainsString('kvs email set does not write these fields.', $output);
    }

    public function testEmailTemplatesRejectsNonTemplateOptions(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'templates',
            '--smtp-host' => 'smtp.example.test',
            '--format' => 'json',
        ]);

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('The templates action does not support --smtp-host', $this->tester->getDisplay());
    }

    public function testEmailTestWithSmtpMailerReportsCliLimitation(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'test',
            '--to' => 'recipient@example.test',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('SMTP sending requires KVS internal API', $output);
        $this->assertStringContainsString('Use the admin panel to send test emails via SMTP.', $output);
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

    public function testEmailSetRejectsNonSetOptionsBeforeNoopSuccess(): void
    {
        $cases = [
            ['--format', 'json', 'format'],
            ['--lines', '1', 'lines'],
            ['--to', 'test@example.com', 'to'],
        ];

        foreach ($cases as [$option, $value, $optionName]) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => 'set',
                $option => $value,
            ]);

            $output = $tester->getDisplay();

            $this->assertSame(1, $tester->getStatusCode(), $optionName . ': ' . $output);
            $this->assertStringContainsString("set action does not support --$optionName", $output);
            $this->assertStringNotContainsString('No settings to update', $output);
        }
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

    public function testEmailSetRejectsMalformedSmtpPort(): void
    {
        foreach (['587abc', '587.5'] as $smtpPort) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => 'set',
                '--smtp-port' => $smtpPort,
            ]);

            $output = $tester->getDisplay();

            $this->assertSame(1, $tester->getStatusCode(), $smtpPort . ': ' . $output);
            $this->assertStringContainsString('Invalid value for --smtp-port', $output);
            $this->assertStringNotContainsString('Email settings updated', $output);
        }
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

    public function testEmailSetRejectsMalformedSmtpTimeout(): void
    {
        foreach (['20abc', '20.5'] as $smtpTimeout) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => 'set',
                '--smtp-timeout' => $smtpTimeout,
            ]);

            $output = $tester->getDisplay();

            $this->assertSame(1, $tester->getStatusCode(), $smtpTimeout . ': ' . $output);
            $this->assertStringContainsString('Invalid value for --smtp-timeout', $output);
            $this->assertStringNotContainsString('Email settings updated', $output);
        }
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

    public function testEmailLogRejectsInvalidLinesBeforeLogLookup(): void
    {
        foreach (['abc', '-5', '1.5'] as $lines) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => 'log',
                '--lines' => $lines,
                '--format' => 'json',
            ]);

            $output = $tester->getDisplay();
            $this->assertSame(1, $tester->getStatusCode(), "lines=$lines: $output");
            $this->assertStringContainsString('Invalid value for --lines (use: integer >= 0)', $output);
            $this->assertStringNotContainsString('No email log files found', $output);
        }
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

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('settings') .
            ' (section TEXT PRIMARY KEY, satellite_prefix TEXT, value TEXT NOT NULL, added_date TEXT, version_control INTEGER)'
        );

        $settings = json_encode([
            'use_mailer' => 'smtp',
            'send_from_email' => 'noreply@example.test',
            'send_from_title' => 'Test Sender',
            'debug_level' => '1',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'smtp-user',
            'smtp_password' => 'smtp-secret',
            'smtp_security' => 'tls',
            'smtp_timeout' => 20,
        ]);
        $this->assertIsString($settings);

        $stmt = $db->prepare(
            'INSERT INTO ' . TestHelper::table('settings') .
            ' (section, satellite_prefix, value, added_date, version_control) VALUES (:section, :prefix, :value, :date, :version)'
        );
        $stmt->execute([
            'section' => 'email',
            'prefix' => '',
            'value' => $settings,
            'date' => '2026-05-26 00:00:00',
            'version' => 1,
        ]);

        return $db;
    }

    private function createCommand(PDO $db): EmailCommand
    {
        return new class ($this->config, $db) extends EmailCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:email');
                $this->setDescription('[EXPERIMENTAL] Manage KVS email settings');
                $this->setAliases(['email']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
