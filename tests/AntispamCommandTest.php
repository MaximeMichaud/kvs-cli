<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\AntispamCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class AntispamCommandTest extends TestCase
{
    private string $kvsPath;
    private Configuration $config;
    private AntispamCommand $command;
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

    public function testAntispamShowBasic(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Anti-spam Settings', $output);
        $this->assertStringContainsString('Blacklisting', $output);
        $this->assertStringContainsString('Duplicates', $output);
        $this->assertStringContainsString('Section Rules', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAntispamShowJsonFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'show',
            '--format' => 'json'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertJson($output);

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('blacklist', $data);
        $this->assertArrayHasKey('duplicates', $data);
        $this->assertArrayHasKey('sections', $data);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAntispamDefaultAction(): void
    {
        $this->tester->execute(['--force' => true]);

        // Default action is show
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Anti-spam Settings', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAntispamRejectsUnknownAction(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'unknown_action',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Unknown antispam action "unknown_action"', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testAntispamBlacklistAction(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'blacklist'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Blacklist Details', $output);
        $this->assertStringContainsString('Blacklisted Words', $output);
        $this->assertStringContainsString('Blocked Domains', $output);
        $this->assertStringContainsString('Blocked IPs', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAntispamBlacklistJsonFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'blacklist',
            '--format' => 'json'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertJson($output);

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('words', $data);
        $this->assertArrayHasKey('domains', $data);
        $this->assertArrayHasKey('ips', $data);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testReadActionsRejectInvalidFormat(): void
    {
        foreach (['show', 'blacklist'] as $action) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => $action,
                '--format' => 'xml',
            ]);

            $output = $tester->getDisplay();
            $this->assertSame(1, $tester->getStatusCode(), $action . ': ' . $output);
            $this->assertStringContainsString('Invalid value for --format "xml"', $output);
        }
    }

    public function testReadActionsRejectMutationOptions(): void
    {
        $cases = [
            ['show', '--words', 'spam', 'words'],
            ['blacklist', '--clear-words', true, 'clear-words'],
        ];

        foreach ($cases as [$action, $option, $value, $optionName]) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => $action,
                $option => $value,
                '--format' => 'json',
            ]);

            $output = $tester->getDisplay();
            $this->assertSame(1, $tester->getStatusCode(), $action . ': ' . $output);
            $this->assertStringContainsString("The $action action does not support --$optionName", $output);
        }
    }

    public function testAntispamCommandMetadata(): void
    {
        $this->assertEquals('system:antispam', $this->command->getName());
        $this->assertStringContainsString('anti-spam', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('antispam', $aliases);
    }

    public function testAntispamSetNoOptions(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'set'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('No settings to update', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testMutationActionsRejectFormatBeforeNoopSuccess(): void
    {
        foreach (['set', 'add', 'remove'] as $action) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--force' => true,
                'action' => $action,
                '--format' => 'json',
            ]);

            $output = $tester->getDisplay();

            $this->assertSame(1, $tester->getStatusCode(), $action . ': ' . $output);
            $this->assertStringContainsString("The $action action does not support --format", $output);
            $this->assertStringNotContainsString('No settings to update', $output);
            $this->assertStringNotContainsString('Nothing to add', $output);
            $this->assertStringNotContainsString('Nothing removed', $output);
        }
    }

    public function testAntispamSetInvalidBlacklistAction(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'set',
            '--blacklist-action' => 'invalid'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid value for --blacklist-action', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testAntispamSetInvalidWordsIgnore(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'set',
            '--words-ignore-feedbacks' => 'invalid'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid value for --words-ignore-feedbacks', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testAntispamSetInvalidDuplicatesComments(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'set',
            '--duplicates-comments' => 'invalid'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid value for --duplicates-comments', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testAntispamSetInvalidRuleFormat(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'set',
            '--comments-captcha' => 'invalid'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid rule format', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testAntispamSetInvalidHistory(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'set',
            '--videos-history' => 'invalid'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid value for --videos-history', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testAntispamAddNoOptions(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'add'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Nothing to add', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAntispamRemoveNoOptions(): void
    {
        $this->tester->execute([
            '--force' => true,
            'action' => 'remove'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Nothing removed', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ' . TestHelper::table('options') . ' (variable TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $db->exec('CREATE TABLE ' . TestHelper::table('users_blocked_domains') . ' (domain TEXT, sort_id INTEGER)');
        $db->exec('CREATE TABLE ' . TestHelper::table('users_blocked_ips') . ' (ip TEXT, sort_id INTEGER)');

        return $db;
    }

    private function createCommand(PDO $db): AntispamCommand
    {
        return new class ($this->config, $db) extends AntispamCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('system:antispam');
                $this->setDescription('[EXPERIMENTAL] Manage KVS anti-spam settings');
                $this->setAliases(['antispam']);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }
}
