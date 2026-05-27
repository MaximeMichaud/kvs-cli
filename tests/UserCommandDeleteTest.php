<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UserCommandDeleteTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        file_put_contents($this->tempDir . '/admin/include/setup_db.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testDeleteUserDelegatesToKvsCleanupInsteadOfDeletingRowsDirectly(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);

        $db->exec("INSERT INTO ktvs_users (user_id, username, status_id) VALUES (1, 'member', 2)");
        $db->exec('INSERT INTO ktvs_videos (video_id, user_id) VALUES (10, 1)');
        $db->exec('INSERT INTO ktvs_albums (album_id, user_id) VALUES (20, 1)');
        $db->exec('INSERT INTO ktvs_comments (comment_id, user_id) VALUES (30, 1)');

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends UserCommand {
            /** @var list<int> */
            public array $deletedUserIds = [];

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:user');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deleteUsersWithKvs(array $userIds): void
            {
                $this->deletedUserIds = $userIds;
                foreach ($userIds as $userId) {
                    $stmt = $this->testDb->prepare('DELETE FROM ktvs_users WHERE user_id = :id');
                    $stmt->execute(['id' => $userId]);
                }
            }
        };

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $tester->execute(['action' => 'delete', 'id' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([1], $command->deletedUserIds);
        $this->assertSame(0, $this->countRows($db, 'ktvs_users'));
        $this->assertSame(1, $this->countRows($db, 'ktvs_videos'));
        $this->assertSame(1, $this->countRows($db, 'ktvs_albums'));
        $this->assertSame(1, $this->countRows($db, 'ktvs_comments'));
    }

    public function testDeleteUserNoInteractionFailsWithoutCleanup(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createDeleteSchema($db);
        $db->exec("INSERT INTO ktvs_users (user_id, username, status_id) VALUES (1, 'member', 2)");

        $config = new Configuration(['path' => $this->tempDir]);
        $command = new class ($config, $db) extends UserCommand {
            public bool $kvsCleanupCalled = false;

            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:user');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }

            protected function deleteUsersWithKvs(array $userIds): void
            {
                $this->kvsCleanupCalled = true;
            }
        };

        $tester = new CommandTester($command);
        $tester->execute([
            'action' => 'delete',
            'id' => '1',
        ], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertFalse($command->kvsCleanupCalled);
        $this->assertStringContainsString('confirmation was not provided', $tester->getDisplay());
        $this->assertSame(1, $this->countRows($db, 'ktvs_users'));
    }

    private function createDeleteSchema(\PDO $db): void
    {
        $db->exec('CREATE TABLE ktvs_users (user_id INTEGER, username TEXT, status_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_videos (video_id INTEGER, user_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_albums (album_id INTEGER, user_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_comments (comment_id INTEGER, user_id INTEGER)');
    }

    private function countRows(\PDO $db, string $table): int
    {
        $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }
}
