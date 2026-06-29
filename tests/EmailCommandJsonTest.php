<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\EmailCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class EmailCommandJsonTest extends TestCase
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

    public function testShowJsonMasksSmtpPassword(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_settings (section TEXT NOT NULL, value TEXT NOT NULL)');

        $settings = [
            'use_mailer' => 'smtp',
            'smtp_host' => 'smtp.example.test',
            'smtp_username' => 'mailer',
            'smtp_password' => 'secret-value',
        ];

        $stmt = $db->prepare('INSERT INTO ktvs_settings (section, value) VALUES (:section, :value)');
        $stmt->execute([
            'section' => 'email',
            'value' => json_encode($settings),
        ]);

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $db) extends EmailCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([
            'action' => 'show',
            '--format' => 'json',
            '--force' => true,
        ]);

        $display = $tester->getDisplay();
        $decoded = json_decode($display, true);

        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertIsArray($decoded);
        $this->assertSame('********', $decoded['smtp_password'] ?? null);
        $this->assertStringNotContainsString('secret-value', $display);
    }

    public function testLogJsonReturnsStructuredEmptyPayloadWhenNoLogExists(): void
    {
        $command = new EmailCommand(TestHelper::createTestConfiguration($this->kvsPath));
        $tester = new CommandTester($command);

        $tester->execute([
            'action' => 'log',
            '--format' => 'json',
            '--force' => true,
        ]);

        $display = $tester->getDisplay();
        $decoded = json_decode($display, true);

        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertIsArray($decoded);
        $this->assertNull($decoded['file'] ?? null);
        $this->assertFalse($decoded['exists'] ?? true);
        $this->assertSame(0, $decoded['total_lines'] ?? null);
        $this->assertSame(0, $decoded['shown_lines'] ?? null);
        $this->assertSame([], $decoded['lines'] ?? null);
        $this->assertStringNotContainsString('[WARNING]', $display);
    }

    public function testLogJsonReturnsTailLinesFromExistingLog(): void
    {
        file_put_contents($this->kvsPath . '/admin/logs/email_test.txt', "first\nsecond\nthird\n");

        $command = new EmailCommand(TestHelper::createTestConfiguration($this->kvsPath));
        $tester = new CommandTester($command);

        $tester->execute([
            'action' => 'log',
            '--format' => 'json',
            '--lines' => '2',
            '--force' => true,
        ]);

        $display = $tester->getDisplay();
        $decoded = json_decode($display, true);

        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertIsArray($decoded);
        $this->assertSame($this->kvsPath . '/admin/logs/email_test.txt', $decoded['file'] ?? null);
        $this->assertTrue($decoded['exists'] ?? false);
        $this->assertSame(3, $decoded['total_lines'] ?? null);
        $this->assertSame(2, $decoded['shown_lines'] ?? null);
        $this->assertSame(['second', 'third'], $decoded['lines'] ?? null);
        $this->assertSame('Showing last 2 of 3 lines', $decoded['message'] ?? null);
        $this->assertStringNotContainsString('Email Log', $display);
    }

    public function testReadActionsRejectInvalidFormat(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_settings (section TEXT NOT NULL, value TEXT NOT NULL)');
        $db->prepare('INSERT INTO ktvs_settings (section, value) VALUES (:section, :value)')->execute([
            'section' => 'email',
            'value' => json_encode(['use_mailer' => 'php']),
        ]);

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $db) extends EmailCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };

        foreach (['show', 'log', 'templates'] as $action) {
            $tester = new CommandTester($command);
            $tester->execute([
                'action' => $action,
                '--format' => 'xml',
                '--force' => true,
            ]);

            $output = $tester->getDisplay();
            $this->assertSame(1, $tester->getStatusCode(), $action . ': ' . $output);
            $this->assertStringContainsString('Invalid value for --format "xml"', $output);
        }
    }
}
