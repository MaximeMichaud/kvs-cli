<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\PlaylistCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PlaylistCommandCreateTest extends TestCase
{
    public function testPlaylistCreateInsertsPlaylistWithProvidedUser(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER PRIMARY KEY, username TEXT)');
        $db->exec('CREATE TABLE ktvs_playlists (
            playlist_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            dir TEXT NOT NULL,
            description TEXT NOT NULL,
            status_id INTEGER NOT NULL,
            is_private INTEGER NOT NULL,
            is_review_needed INTEGER NOT NULL DEFAULT 0,
            is_locked INTEGER NOT NULL,
            rating INTEGER NOT NULL,
            rating_amount INTEGER NOT NULL,
            playlist_viewed INTEGER NOT NULL DEFAULT 0,
            comments_count INTEGER NOT NULL DEFAULT 0,
            subscribers_count INTEGER NOT NULL DEFAULT 0,
            total_videos INTEGER NOT NULL DEFAULT 0,
            last_content_date TEXT NOT NULL,
            added_date TEXT NOT NULL
        )');
        $db->exec("INSERT INTO ktvs_users (user_id, username) VALUES (7, 'owner')");

        $command = new class (TestHelper::createTestConfiguration(TestHelper::createTestKvsInstallation()), $db) extends PlaylistCommand {
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
            'action' => 'create',
            'id' => 'Codex Playlist',
            '--user' => '7',
            '--description' => 'Created by regression test',
            '--private' => true,
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('Playlist created successfully with ID: 1', $display);

        $row = $db->query('SELECT * FROM ktvs_playlists WHERE playlist_id = 1')->fetch();
        $this->assertIsArray($row);
        $this->assertSame('Codex Playlist', $row['title']);
        $this->assertSame('codex-playlist', $row['dir']);
        $this->assertSame(7, (int) $row['user_id']);
        $this->assertSame(1, (int) $row['is_private']);
        $this->assertSame(1, (int) $row['status_id']);
        $this->assertSame(0, (int) $row['is_locked']);
        $this->assertSame('Created by regression test', $row['description']);
    }
}
