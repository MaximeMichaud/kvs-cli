<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\PlaylistCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class PlaylistCommandTest extends TestCase
{
    private Configuration $config;
    private PlaylistCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        // Use real KVS installation with test database
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new PlaylistCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);

        // Setup test database connection
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

    public function testPlaylistListBasic(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 5
        ]);

        $output = $this->tester->getDisplay();
        // Either shows playlists or empty result (both are valid)
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testPlaylistListWithStatusFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 1,
            '--limit' => 5
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testPlaylistListWithUserFilter(): void
    {
        // Get a user ID that exists
        $stmt = $this->db->query("SELECT user_id FROM ktvs_users LIMIT 1");
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            $this->markTestSkipped('No users in database');
        }

        $this->tester->execute([
            'action' => 'list',
            '--user' => $userId,
            '--limit' => 5
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testPlaylistListPublicFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--public' => true,
            '--limit' => 5
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testPlaylistListPrivateFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--private' => true,
            '--limit' => 5
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testPlaylistListSearchFilter(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'test',
            '--limit' => 5
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testPlaylistListJsonFormat(): void
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

    public function testPlaylistListCsvFormat(): void
    {
        $testerCsv = new CommandTester($this->command);
        ob_start();
        $testerCsv->execute([
            'action' => 'list',
            '--limit' => 1,
            '--format' => 'csv'
        ]);
        $csvOutput = ob_get_clean();

        $this->assertStringContainsString('playlist_id', $csvOutput);
        $this->assertEquals(0, $testerCsv->getStatusCode());
    }

    public function testPlaylistListCountFormat(): void
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

    public function testPlaylistShow(): void
    {
        // Get first playlist ID
        $stmt = $this->db->query("SELECT playlist_id FROM ktvs_playlists LIMIT 1");
        $playlistId = $stmt->fetchColumn();

        if (!$playlistId) {
            $this->markTestSkipped('No playlists in database');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => $playlistId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Playlist #', $output);
        $this->assertStringContainsString('Title', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testPlaylistShowNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testPlaylistShowMissingId(): void
    {
        $this->tester->execute([
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testPlaylistDeleteMissingId(): void
    {
        $this->tester->execute([
            'action' => 'delete'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('required', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testPlaylistDeleteNotFound(): void
    {
        $this->tester->execute([
            'action' => 'delete',
            'id' => 999999
        ]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testPlaylistCommandMetadata(): void
    {
        $this->assertEquals('content:playlist', $this->command->getName());
        $this->assertStringContainsString('playlist', strtolower($this->command->getDescription()));

        $aliases = $this->command->getAliases();
        $this->assertContains('playlist', $aliases);
        $this->assertContains('playlists', $aliases);
    }

    public function testPlaylistDefaultAction(): void
    {
        // No action specified should show help
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('list', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }
}
