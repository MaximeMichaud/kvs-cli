<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UserCommandDateTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-user-date-test-');
        TestHelper::createMockKvsInstallation($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            TestHelper::removeDir($this->tempDir);
        }
    }

    public function testZeroDatesUseFallback(): void
    {
        $command = new UserCommand(new Configuration(['path' => $this->tempDir]));
        $method = new \ReflectionMethod($command, 'formatDate');

        $this->assertSame('Never', $method->invoke($command, '0000-00-00 00:00:00'));
        $this->assertSame('Unknown', $method->invoke($command, '0000-00-00', 'Y-m-d H:i:s', 'Unknown'));
    }

    public function testShowUserFormatsZeroBirthDateAsNotAvailable(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $db->exec(
            'CREATE TABLE ktvs_users (
                user_id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                status_id INTEGER,
                display_name TEXT,
                country_id TEXT,
                gender_id INTEGER,
                birth_date TEXT,
                added_date TEXT,
                last_login_date TEXT,
                ip TEXT,
                profile_viewed INTEGER,
                logins_count INTEGER,
                tokens_available INTEGER,
                tokens_required INTEGER
            )'
        );
        $db->exec('CREATE TABLE ktvs_videos (video_id INTEGER, user_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums (album_id INTEGER, user_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (comment_id INTEGER, user_id INTEGER)');
        $db->exec(
            "INSERT INTO ktvs_users (
                user_id, username, email, status_id, display_name, country_id, gender_id,
                birth_date, added_date, last_login_date, ip, profile_viewed, logins_count,
                tokens_available, tokens_required
            ) VALUES (
                3, 'member', 'member@example.test', 2, '', '', 0,
                '0000-00-00', '2026-01-01 00:00:00', '0000-00-00 00:00:00',
                '', 0, 0, 0, 0
            )"
        );

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends UserCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
        $tester = new CommandTester($command);

        $tester->execute(['action' => 'show', 'id' => '3']);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('Birth Date', $display);
        $this->assertStringContainsString('N/A', $display);
        $this->assertStringNotContainsString('0000-00-00', $display);
    }
}
