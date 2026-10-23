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
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('settings')
            . " (section TEXT NOT NULL, satellite_prefix TEXT NOT NULL DEFAULT '', value TEXT NOT NULL)"
        );

        $settings = [
            'use_mailer' => 'smtp',
            'smtp_host' => 'smtp.example.test',
            'smtp_username' => 'mailer',
            'smtp_password' => 'secret-value',
        ];

        $stmt = $db->prepare('INSERT INTO ' . TestHelper::table('settings') . ' (section, value) VALUES (:section, :value)');
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
        $db->exec(
            'CREATE TABLE ' . TestHelper::table('settings')
            . " (section TEXT NOT NULL, satellite_prefix TEXT NOT NULL DEFAULT '', value TEXT NOT NULL)"
        );
        $db->prepare('INSERT INTO ' . TestHelper::table('settings') . ' (section, value) VALUES (:section, :value)')->execute([
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

    #[\PHPUnit\Framework\Attributes\DataProvider('directJsonPayloads')]
    public function testDirectJsonPreservesEmailSettings(string $payload, bool $decorated): void
    {
        $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE ' . TestHelper::table('settings')
            . " (section TEXT NOT NULL, satellite_prefix TEXT NOT NULL DEFAULT '', value TEXT NOT NULL)");
        $settings = ['use_mailer' => 'php', 'from_name' => $payload, 'smtp_password' => 'secret-value'];
        $db->prepare('INSERT INTO ' . TestHelper::table('settings') . ' (section, value) VALUES (?, ?)')
            ->execute(['email', json_encode($settings, JSON_THROW_ON_ERROR)]);
        $command = new class (TestHelper::createTestConfiguration($this->kvsPath), $db) extends EmailCommand {
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
        $tester->execute(['action' => 'show', '--format' => 'json', '--force' => true], ['decorated' => $decorated]);
        $text = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $text);
        $this->assertJson($text);
        $data = json_decode($text, true);
        $this->assertSame($payload, $data['from_name']);
        $this->assertSame('********', $data['smtp_password']);
        $this->assertStringNotContainsString('secret-value', $text);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('directJsonPayloads')]
    public function testDirectJsonPreservesEmailLog(string $payload, bool $decorated): void
    {
        file_put_contents($this->kvsPath . '/admin/logs/email_test.txt', $payload . "\n");
        $tester = new CommandTester(new EmailCommand(TestHelper::createTestConfiguration($this->kvsPath)));
        $tester->execute(['action' => 'log', '--format' => 'json', '--force' => true], ['decorated' => $decorated]);
        $text = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $text);
        $this->assertJson($text);
        $data = json_decode($text, true);
        $this->assertSame([$payload], $data['lines']);
        $this->assertTrue($data['exists']);
        $this->assertSame(1, $data['shown_lines']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('directJsonPayloads')]
    public function testDirectJsonPreservesEmailTemplateSubject(string $payload, bool $decorated): void
    {
        $directory = $this->kvsPath . '/blocks/signup/emails';
        mkdir($directory, 0755, true);
        file_put_contents($directory . '/synthetic_subject.txt', $payload);
        file_put_contents($directory . '/synthetic_body.txt', 'Synthetic body');
        $tester = new CommandTester(new EmailCommand(TestHelper::createTestConfiguration($this->kvsPath)));
        $tester->execute(['action' => 'templates', '--format' => 'json', '--force' => true], ['decorated' => $decorated]);
        $text = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $text);
        $this->assertJson($text);
        $data = json_decode($text, true);
        $this->assertSame($payload, $data[0]['subject']);
        $this->assertSame('User Registration', $data[0]['description']);
        $this->assertTrue($data[0]['has_body']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function directJsonPayloads(): iterable
    {
        foreach (FormatterTest::exportContractPayloads() as $name => [$payload, $format, $decorated]) {
            if ($format === 'json') {
                yield $name => [$payload, $decorated];
            }
        }
    }
}
