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

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeCleanupOutcomeProvider')]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testNativeCleanupReportsResidualRelationsAndFiles(string $kind, string $outcome): void
    {
        $db = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $prefix = TestHelper::getTablePrefix();
        $db->exec("CREATE TABLE {$prefix}users (
            user_id INTEGER, username TEXT, email TEXT, status_id INTEGER,
            is_removal_requested INTEGER, last_login_date TEXT, added_date TEXT,
            removal_reason TEXT, avatar TEXT, cover TEXT
        )");
        foreach (
            ['videos', 'albums', 'posts', 'playlists', 'comments', 'users_events', 'users_subscriptions',
            'users_ignores', 'users_blocked_passwords', 'fav_videos', 'fav_albums', 'users_blogs', 'friends',
            'messages', 'log_logins_users', 'feedbacks', 'dvds'] as $table
        ) {
            $db->exec("CREATE TABLE {$prefix}{$table} (
                user_id INTEGER, user_target_id INTEGER, subscribed_object_id INTEGER,
                subscribed_object_type_id INTEGER, ignored_user_id INTEGER, friend_id INTEGER, user_from_id INTEGER
            )");
        }
        $db->exec("INSERT INTO {$prefix}users VALUES
            (3, 'cleanup-member', 'member@example.test', 2, 1, '2020-01-01', '2020-01-01', '', 'avatar.png', ''),
            (4, 'untouched-member', 'other@example.test', 2, 0, '2020-01-01', '2020-01-01', '', '', '')");
        $db->exec("INSERT INTO {$prefix}users_subscriptions
            (user_id, subscribed_object_id, subscribed_object_type_id) VALUES (3,4,1),(4,5,1)");
        $avatar = $this->createCleanupAvatar($outcome);
        $neighbor = $db->query("SELECT * FROM {$prefix}users WHERE user_id=4")->fetch();
        file_put_contents($this->tempDir . '/admin/include/functions_base.php', '<?php');
        file_put_contents($this->tempDir . '/admin/include/functions.php', <<<'PHP'
<?php
namespace KVS\CLI\Command {
    // Model ancestor EACCES even when the test runner has root permissions.
    function file_exists($path)
    {
        if (
            in_array($GLOBALS['cleanup_fixture_outcome'], ['ancestor', 'symlink'], true)
            && $path === $GLOBALS['cleanup_fixture_hidden_path']
        ) {
            return false;
        }
        return \file_exists($path);
    }
    function is_dir($path)
    {
        if (
            in_array($GLOBALS['cleanup_fixture_outcome'], ['ancestor', 'symlink'], true)
            && (
                $path === dirname($GLOBALS['cleanup_fixture_hidden_path'])
                || ($GLOBALS['cleanup_fixture_outcome'] === 'symlink'
                    && $path === dirname(dirname($GLOBALS['cleanup_fixture_hidden_path'])))
            )
        ) {
            return false;
        }
        return \is_dir($path);
    }
    function is_executable($path)
    {
        if (
            $GLOBALS['cleanup_fixture_outcome'] === 'ancestor'
            && $path === dirname(dirname($GLOBALS['cleanup_fixture_hidden_path']))
        ) {
            return false;
        }
        return \is_executable($path);
    }
}
namespace {
function delete_users($ids, $withContent, $context)
{
    $db = $GLOBALS['cleanup_fixture_db'];
    $prefix = $GLOBALS['config']['tables_prefix'];
    $GLOBALS['cleanup_fixture_call'] = [$ids, $withContent, $context];
    foreach ($ids as $id) {
        $db->exec("DELETE FROM {$prefix}users WHERE user_id=" . (int) $id);
        if ($GLOBALS['cleanup_fixture_outcome'] !== 'subscriptions') {
            $db->exec("DELETE FROM {$prefix}users_subscriptions WHERE user_id=" . (int) $id);
        }
    }
    if (!in_array($GLOBALS['cleanup_fixture_outcome'], ['avatar', 'ancestor', 'symlink'], true)) {
        unlink($GLOBALS['config']['project_path'] . '/contents/avatars/avatar.png');
        if ($GLOBALS['cleanup_fixture_outcome'] === 'missing-directory') {
            rmdir($GLOBALS['config']['project_path'] . '/contents/avatars');
        }
    }
}
}
PHP);
        $config = new Configuration(['path' => $this->tempDir]);
        if ($kind === 'purge') {
            $command = new class ($config, $db) extends \KVS\CLI\Command\Content\UserPurgeCommand {
                public function __construct(Configuration $config, private \PDO $db)
                {
                    parent::__construct($config);
                }
                protected function getDatabaseConnection(bool $quiet = false): ?\PDO
                {
                    return $this->db;
                }
            };
            $arguments = ['--removal-requested' => true, '--no-content' => true, '--confirm' => true, '--yes' => true];
        } else {
            $command = new class ($config, $db) extends UserCommand {
                public function __construct(Configuration $config, private \PDO $db)
                {
                    parent::__construct($config);
                }
                protected function getDatabaseConnection(bool $quiet = false): ?\PDO
                {
                    return $this->db;
                }
            };
            $arguments = ['action' => 'delete', 'id' => '3', '--yes' => true];
        }
        $GLOBALS['cleanup_fixture_db'] = $db;
        $GLOBALS['cleanup_fixture_outcome'] = $outcome;
        $GLOBALS['cleanup_fixture_hidden_path'] = $avatar;
        $complete = in_array($outcome, ['complete', 'missing-directory', 'healthy-symlink'], true);
        $previousColumns = getenv('COLUMNS');
        putenv('COLUMNS=55');
        try {
            $tester = new CommandTester($command);
            $tester->execute($arguments);
            $this->assertSame($complete ? 0 : 1, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertSame(
                0,
                (int) $db->query("SELECT COUNT(*) FROM {$prefix}users WHERE user_id=3")->fetchColumn()
            );
            $this->assertSame($neighbor, $db->query("SELECT * FROM {$prefix}users WHERE user_id=4")->fetch());
            $this->assertSame([[3], $kind !== 'purge', 'ap'], $GLOBALS['cleanup_fixture_call']);
            $this->assertSame(
                $outcome === 'subscriptions' ? 1 : 0,
                (int) $db->query("SELECT COUNT(*) FROM {$prefix}users_subscriptions WHERE user_id=3")->fetchColumn()
            );
            $this->assertSame(
                1,
                (int) $db->query("SELECT COUNT(*) FROM {$prefix}users_subscriptions WHERE user_id=4")->fetchColumn()
            );
            if (in_array($outcome, ['avatar', 'ancestor', 'symlink'], true)) {
                $this->assertSame('synthetic avatar bytes', file_get_contents($avatar));
                $this->assertStringContainsString('avatar/cover', $tester->getDisplay());
            } else {
                $this->assertFileDoesNotExist($avatar);
            }
            if (!$complete) {
                $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
                $this->assertIsString($display);
                $this->assertStringContainsString('no rollback was performed', $display);
                $this->assertStringNotContainsString('[OK]', $tester->getDisplay());
                if ($outcome === 'subscriptions') {
                    $this->assertStringContainsString('users_subscriptions', $tester->getDisplay());
                }
            }
        } finally {
            $previousColumns === false ? putenv('COLUMNS') : putenv('COLUMNS=' . $previousColumns);
            unset(
                $GLOBALS['cleanup_fixture_db'],
                $GLOBALS['cleanup_fixture_outcome'],
                $GLOBALS['cleanup_fixture_hidden_path'],
                $GLOBALS['cleanup_fixture_call']
            );
        }
    }

    public static function nativeCleanupOutcomeProvider(): iterable
    {
        foreach (['purge', 'single'] as $kind) {
            foreach (
                ['subscriptions', 'avatar', 'ancestor', 'symlink',
                'healthy-symlink', 'missing-directory', 'complete'] as $outcome
            ) {
                yield $kind . ' ' . $outcome => [$kind, $outcome];
            }
        }
    }

    private function createCleanupAvatar(string $outcome): string
    {
        mkdir($this->tempDir . '/contents/avatars', 0755, true);
        $avatar = $this->tempDir . '/contents/avatars/avatar.png';
        file_put_contents($avatar, 'synthetic avatar bytes');
        if (in_array($outcome, ['symlink', 'healthy-symlink'], true)) {
            $this->assertTrue(rename($this->tempDir . '/contents', $this->tempDir . '/linked-contents'));
            $this->assertTrue(symlink($this->tempDir . '/linked-contents', $this->tempDir . '/contents'));
            $this->assertTrue(is_link($this->tempDir . '/contents'));
        }

        return $avatar;
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
