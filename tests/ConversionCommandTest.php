<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\ConversionCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class ConversionCommandTest extends TestCase
{
    private Configuration $config;
    private ConversionCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new ConversionCommand($this->config);

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

    public function testConversionListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 10
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConversionListWithStatusFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--limit' => 10
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConversionListWithErrorsFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--errors' => true,
            '--limit' => 10
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConversionListJsonFormat(): void
    {
        $testerJson = new CommandTester($this->command);
        $testerJson->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'json'
        ]);

        $output = $testerJson->getDisplay();
        $this->assertJson($output);
        $this->assertEquals(0, $testerJson->getStatusCode());
    }

    public function testConversionListCountFormat(): void
    {
        $testerCount = new CommandTester($this->command);
        $testerCount->execute([
            'action' => 'list',
            '--format' => 'count'
        ]);

        $output = trim($testerCount->getDisplay());
        $this->assertMatchesRegularExpression('/^\d+$/', $output);
        $this->assertEquals(0, $testerCount->getStatusCode());
    }

    public function testConversionShow(): void
    {
        $stmt = $this->db->query("SELECT server_id FROM ktvs_admin_conversion_servers LIMIT 1");
        $serverId = $stmt->fetchColumn();

        if (!$serverId) {
            $this->markTestSkipped('No conversion servers in database');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => $serverId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion Server #', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConversionShowNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionShowMissingId(): void
    {
        $this->tester->execute([
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionStats(): void
    {
        $this->tester->execute([
            'action' => 'stats'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Conversion Statistics', $output);
        $this->assertStringContainsString('Total Servers', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConversionCommandMetadata(): void
    {
        $this->assertEquals('system:conversion', $this->command->getName());
        $this->assertStringContainsString('conversion', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('conversion', $aliases);
    }

    public function testConversionDefaultAction(): void
    {
        $this->tester->execute([]);

        // Default action is list
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testConversionEnableMissingId(): void
    {
        $this->tester->execute([
            'action' => 'enable'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionDisableMissingId(): void
    {
        $this->tester->execute([
            'action' => 'disable'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionEnableNotFound(): void
    {
        $this->tester->execute([
            'action' => 'enable',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionDebugOnMissingId(): void
    {
        $this->tester->execute([
            'action' => 'debug-on'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionDebugOffMissingId(): void
    {
        $this->tester->execute([
            'action' => 'debug-off'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionDebugOnNotFound(): void
    {
        $this->tester->execute([
            'action' => 'debug-on',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionLogMissingId(): void
    {
        $this->tester->execute([
            'action' => 'log'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionLogNotFound(): void
    {
        $this->tester->execute([
            'action' => 'log',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionConfigMissingId(): void
    {
        $this->tester->execute([
            'action' => 'config'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testConversionConfigNotFound(): void
    {
        $this->tester->execute([
            'action' => 'config',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
