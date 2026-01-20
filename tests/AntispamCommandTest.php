<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\AntispamCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class AntispamCommandTest extends TestCase
{
    private Configuration $config;
    private AntispamCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new AntispamCommand($this->config);

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

    public function testAntispamShowBasic(): void
    {
        $this->tester->execute([
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
        $this->tester->execute([]);

        // Default action is show
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Anti-spam Settings', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAntispamBlacklistAction(): void
    {
        $this->tester->execute([
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
            'action' => 'set'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('No settings to update', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testAntispamSetInvalidBlacklistAction(): void
    {
        $this->tester->execute([
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
            'action' => 'set',
            '--videos-history' => 'invalid'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Invalid value for --videos-history', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
