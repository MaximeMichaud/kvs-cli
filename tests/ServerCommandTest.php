<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\ServerCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class ServerCommandTest extends TestCase
{
    private Configuration $config;
    private ServerCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new ServerCommand($this->config);

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

    public function testServerListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 10
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerListWithStatusFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active',
            '--limit' => 10
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerListWithTypeFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--type' => 'video',
            '--limit' => 10
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerListWithConnectionFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--connection' => 'local',
            '--limit' => 10
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerListWithErrorsFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--errors' => true,
            '--limit' => 10
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerListJsonFormat(): void
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

    public function testServerListCountFormat(): void
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

    public function testServerShow(): void
    {
        $stmt = $this->db->query("SELECT server_id FROM ktvs_admin_servers LIMIT 1");
        $serverId = $stmt->fetchColumn();

        if (!$serverId) {
            $this->markTestSkipped('No servers in database');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => $serverId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server #', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerShowNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerShowMissingId(): void
    {
        $this->tester->execute([
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerStats(): void
    {
        $this->tester->execute([
            'action' => 'stats'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Storage Statistics', $output);
        $this->assertStringContainsString('Total Servers', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerGroupList(): void
    {
        $this->tester->execute([
            'action' => 'group'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerGroupShow(): void
    {
        $stmt = $this->db->query("SELECT group_id FROM ktvs_admin_servers_groups LIMIT 1");
        $groupId = $stmt->fetchColumn();

        if (!$groupId) {
            $this->markTestSkipped('No server groups in database');
        }

        $this->tester->execute([
            'action' => 'group',
            'id' => $groupId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Server Group #', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerGroupShowNotFound(): void
    {
        $this->tester->execute([
            'action' => 'group',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerCommandMetadata(): void
    {
        $this->assertEquals('system:server', $this->command->getName());
        $this->assertStringContainsString('server', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('server', $aliases);
        $this->assertContains('servers', $aliases);
    }

    public function testServerDefaultAction(): void
    {
        $this->tester->execute([]);

        // Default action is list
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testServerEnableMissingId(): void
    {
        $this->tester->execute([
            'action' => 'enable'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerDisableMissingId(): void
    {
        $this->tester->execute([
            'action' => 'disable'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerEnableNotFound(): void
    {
        $this->tester->execute([
            'action' => 'enable',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testServerDisableNotFound(): void
    {
        $this->tester->execute([
            'action' => 'disable',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
