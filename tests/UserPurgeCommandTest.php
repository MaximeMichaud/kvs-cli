<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\UserPurgeCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

class UserPurgeCommandTest extends TestCase
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

    #[DataProvider('contentProvider')]
    public function testNoContentExcludesActualContentDespiteZeroCounters(string $table, int $status, int $privacy = 0): void
    {
        $db = $this->createContentDatabase();
        $db->exec('INSERT INTO ' . TestHelper::table($table) . " (user_id, status_id, is_private) VALUES (3, $status, $privacy)");
        $command = $this->createContentCommand($db);
        $tester = new CommandTester($command);

        $tester->execute(['--removal-requested' => true, '--no-content' => true, '--limit' => '1']);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringNotContainsString('content-owner', $display);
        $this->assertStringContainsString('empty-account', $display);
        $this->assertStringContainsString('Total: 1 user', $display);
        $this->assertSame(0, $command->cleanupCalls);

        $tester->execute([
            '--removal-requested' => true,
            '--no-content' => true,
            '--confirm' => true,
            '--yes' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame([4], $command->deletedUserIds);
        $this->assertFalse($command->withContent);
        $this->assertSame([3], $db->query('SELECT user_id FROM ' . TestHelper::table('users'))->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM ' . TestHelper::table($table))->fetchColumn());
    }

    public static function contentProvider(): iterable
    {
        foreach ([0, 1, 2, 3, 4, 5] as $status) {
            yield 'video status ' . $status => ['videos', $status];
        }
        yield 'private video' => ['videos', 1, 1];
        yield 'premium video' => ['videos', 1, 2];
        yield 'album' => ['albums', 0];
        yield 'unapproved comment' => ['comments', 0];
        yield 'post' => ['posts', 0];
        yield 'public playlist' => ['playlists', 0];
        yield 'private playlist' => ['playlists', 0, 1];
    }

    #[DataProvider('contentProvider')]
    public function testNoContentStopsIfContentAppearsDuringConfirmation(string $table, int $status, int $privacy = 0): void
    {
        $db = $this->createContentDatabase();
        $command = $this->createContentCommand($db);
        $style = $this->createMock(SymfonyStyle::class);
        $style->expects($this->once())->method('confirm')->willReturnCallback(
            static function () use ($db, $table, $status, $privacy): bool {
                $db->exec('INSERT INTO ' . TestHelper::table($table) . " (user_id, status_id, is_private) VALUES (3, $status, $privacy)");
                return true;
            }
        );
        $style->expects($this->once())->method('error')->with($this->stringContains('no longer match --no-content'));
        $command->confirmationStyle = $style;
        $tester = new CommandTester($command);

        $tester->execute(['--no-content' => true, '--confirm' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame(0, $command->cleanupCalls);
        $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM ' . TestHelper::table('users'))->fetchColumn());
    }

    public function testNoContentFailsClosedWhenContentCannotBeChecked(): void
    {
        $db = $this->createContentDatabase();
        $db->exec('DROP TABLE ' . TestHelper::table('videos'));
        $command = $this->createContentCommand($db);
        $tester = new CommandTester($command);

        $tester->execute(['--no-content' => true, '--confirm' => true, '--yes' => true]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(0, $command->cleanupCalls);
        $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM ' . TestHelper::table('users'))->fetchColumn());
    }

    public function testPurgeWithoutNoContentKeepsNativeContentDeletion(): void
    {
        $db = $this->createContentDatabase();
        $db->exec('INSERT INTO ' . TestHelper::table('videos') . ' (user_id) VALUES (3)');
        $command = $this->createContentCommand($db);
        $tester = new CommandTester($command);

        $tester->execute(['--removal-requested' => true, '--confirm' => true, '--yes' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame([3, 4], $command->deletedUserIds);
        $this->assertTrue($command->withContent);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNativeCleanupReceivesContentDeletionMode(): void
    {
        file_put_contents($this->kvsPath . '/admin/include/functions_base.php', '<?php');
        file_put_contents($this->kvsPath . '/admin/include/functions.php', <<<'PHP'
<?php
function delete_users($userIds, $withContent, $context)
{
    file_put_contents(
        $GLOBALS['config']['project_path'] . '/cleanup.json',
        json_encode([$userIds, $withContent, $context])
    );
}
PHP);
        $command = new class (TestHelper::createTestConfiguration($this->kvsPath)) extends UserPurgeCommand {
            public function invokeCleanup(bool $withContent): void
            {
                $this->deleteUsersWithKvs([3], $withContent);
            }
        };

        foreach ([false, true] as $withContent) {
            $command->invokeCleanup($withContent);
            $result = json_decode(file_get_contents($this->kvsPath . '/cleanup.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([[3], $withContent, 'ap'], $result);
        }
    }

    private function createContentDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('CREATE TABLE ' . TestHelper::table('users') . " (
            user_id INTEGER PRIMARY KEY, username TEXT, email TEXT,
            status_id INTEGER DEFAULT 2, is_removal_requested INTEGER DEFAULT 1,
            total_videos_count INTEGER DEFAULT 0, comments_total_count INTEGER DEFAULT 0,
            last_login_date TEXT DEFAULT '2020-01-01 00:00:00',
            added_date TEXT DEFAULT '2020-01-01 00:00:00', removal_reason TEXT DEFAULT ''
        )");
        $this->createContentTables($db);
        $db->exec('INSERT INTO ' . TestHelper::table('users') . " (user_id, username, email, added_date) VALUES
            (3, 'content-owner', 'owner@example.test', '2019-01-01 00:00:00'),
            (4, 'empty-account', 'empty@example.test', '2020-01-01 00:00:00')");

        return $db;
    }

    private function createContentTables(PDO $db): void
    {
        foreach (['videos', 'albums', 'comments', 'posts', 'playlists'] as $table) {
            $db->exec('CREATE TABLE ' . TestHelper::table($table) . ' (
                user_id INTEGER, status_id INTEGER DEFAULT 0, is_private INTEGER DEFAULT 0, is_approved INTEGER DEFAULT 0
            )');
        }
    }

    private function createContentCommand(PDO $db): UserPurgeCommand
    {
        return new class (TestHelper::createTestConfiguration($this->kvsPath), $db) extends UserPurgeCommand {
            public int $cleanupCalls = 0;
            public ?bool $withContent = null;
            public ?SymfonyStyle $confirmationStyle = null;

            /** @var list<int> */
            public array $deletedUserIds = [];

            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }

            protected function initialize(InputInterface $input, OutputInterface $output): void
            {
                parent::initialize($input, $output);
                if ($this->confirmationStyle !== null) {
                    $this->io = $this->confirmationStyle;
                }
            }

            protected function deleteUsersWithKvs(array $userIds, bool $withContent = true): void
            {
                $this->cleanupCalls++;
                $this->withContent = $withContent;
                $this->deletedUserIds = $userIds;
                $stmt = $this->testDb->prepare('DELETE FROM ' . TestHelper::table('users') . ' WHERE user_id = ?');
                foreach ($userIds as $id) {
                    $stmt->execute([$id]);
                }
            }
        };
    }

    public function testRejectsInvalidPositiveIntegerFiltersBeforeSql(): void
    {
        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $db = new PDO('sqlite::memory:');
        $command = new class ($config, $db) extends UserPurgeCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };

        foreach (['inactive-days', 'min-age'] as $option) {
            foreach (['abc', '1.5', '0', '-1'] as $value) {
                $tester = new CommandTester($command);
                $tester->execute([
                    '--no-content' => true,
                    '--' . $option => $value,
                ]);

                $display = $tester->getDisplay();
                $this->assertSame(1, $tester->getStatusCode(), "$option=$value: $display");
                $this->assertStringContainsString("Invalid value for --$option", $display, "$option=$value");
                $this->assertStringNotContainsString('no such table', strtolower($display), "$option=$value");
            }
        }
    }

    public function testDryRunExcludesSystemAccounts(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createContentTables($db);
        $db->exec(
            'CREATE TABLE ktvs_users (
                user_id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                status_id INTEGER NOT NULL,
                is_removal_requested INTEGER NOT NULL DEFAULT 0,
                total_videos_count INTEGER NOT NULL DEFAULT 0,
                comments_total_count INTEGER NOT NULL DEFAULT 0,
                last_login_date TEXT NOT NULL,
                added_date TEXT NOT NULL,
                removal_reason TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $stmt = $db->prepare(
            'INSERT INTO ktvs_users (
                user_id, username, email, status_id, total_videos_count,
                comments_total_count, last_login_date, added_date
            ) VALUES (?, ?, ?, ?, 0, 0, ?, ?)'
        );
        $stmt->execute([1, 'Admin', 'admin@example.test', 3, '0000-00-00 00:00:00', '2026-01-01 00:00:00']);
        $stmt->execute([2, 'Anonymous', 'anonymous@example.test', 4, '0000-00-00 00:00:00', '2026-01-01 00:00:00']);
        $stmt->execute([3, 'regular-user', 'regular@example.test', 2, '2026-05-01 00:00:00', '2026-01-01 00:00:00']);
        $stmt->execute([4, 'anonymous-copy', 'anon-copy@example.test', 4, '2026-05-01 00:00:00', '2026-01-01 00:00:00']);

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $db) extends UserPurgeCommand {
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

        $tester->execute(['--no-content' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('regular-user', $display);
        $this->assertStringContainsString('Total: 1 user', $display);
        $this->assertStringNotContainsString('Admin', $display);
        $this->assertStringNotContainsString('Anonymous', $display);
        $this->assertStringNotContainsString('anonymous-copy', $display);
    }

    public function testDryRunDisplaysZeroLastLoginAsNever(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createContentTables($db);
        $db->exec(
            'CREATE TABLE ktvs_users (
                user_id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                status_id INTEGER NOT NULL,
                is_removal_requested INTEGER NOT NULL DEFAULT 0,
                total_videos_count INTEGER NOT NULL DEFAULT 0,
                comments_total_count INTEGER NOT NULL DEFAULT 0,
                last_login_date TEXT NOT NULL,
                added_date TEXT NOT NULL,
                removal_reason TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $stmt = $db->prepare(
            'INSERT INTO ktvs_users (
                user_id, username, email, status_id, total_videos_count,
                comments_total_count, last_login_date, added_date
            ) VALUES (?, ?, ?, 2, 0, 0, ?, ?)'
        );
        $stmt->execute([3, 'zero-login-user', 'zero@example.test', '0000-00-00 00:00:00', '2026-01-01 00:00:00']);

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $db) extends UserPurgeCommand {
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

        $tester->execute(['--no-content' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('zero-login-user', $display);
        $this->assertStringContainsString('Never', $display);
        $this->assertStringNotContainsString('-0001-11-30', $display);
    }

    public function testDryRunDisplaysRemovalReasonWhenFilteringRemovalRequests(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createContentTables($db);
        $db->exec(
            'CREATE TABLE ktvs_users (
                user_id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                status_id INTEGER NOT NULL,
                is_removal_requested INTEGER NOT NULL DEFAULT 0,
                total_videos_count INTEGER NOT NULL DEFAULT 0,
                comments_total_count INTEGER NOT NULL DEFAULT 0,
                last_login_date TEXT NOT NULL,
                added_date TEXT NOT NULL,
                removal_reason TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $stmt = $db->prepare(
            'INSERT INTO ktvs_users (
                user_id, username, email, status_id, is_removal_requested,
                total_videos_count, comments_total_count, last_login_date,
                added_date, removal_reason
            ) VALUES (?, ?, ?, 2, 1, 0, 0, ?, ?, ?)'
        );
        $stmt->execute([
            3,
            'removal-user',
            'removal@example.test',
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00',
            'Please delete my account',
        ]);

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $db) extends UserPurgeCommand {
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

        $tester->execute(['--removal-requested' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('Removal Reason', $display);
        $this->assertStringContainsString('Please delete my account', $display);
    }

    public function testConfirmFailsWhenKvsCleanupDoesNotDeleteSelectedUsers(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createContentTables($db);
        $db->exec(
            'CREATE TABLE ktvs_users (
                user_id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                status_id INTEGER NOT NULL,
                is_removal_requested INTEGER NOT NULL DEFAULT 0,
                total_videos_count INTEGER NOT NULL DEFAULT 0,
                comments_total_count INTEGER NOT NULL DEFAULT 0,
                last_login_date TEXT NOT NULL,
                added_date TEXT NOT NULL,
                removal_reason TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $stmt = $db->prepare(
            'INSERT INTO ktvs_users (
                user_id, username, email, status_id, is_removal_requested,
                total_videos_count, comments_total_count, last_login_date,
                added_date, removal_reason
            ) VALUES (?, ?, ?, 2, 1, 0, 0, ?, ?, ?)'
        );
        $stmt->execute([
            3,
            'removal-user',
            'removal@example.test',
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00',
            'Please delete my account',
        ]);

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $db) extends UserPurgeCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }

            protected function deleteUsersWithKvs(array $userIds, bool $withContent = true): void
            {
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([
            '--removal-requested' => true,
            '--no-content' => true,
            '--confirm' => true,
            '--yes' => true,
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('Total: 1 user', $display);
        $this->assertStringContainsString('Deleting 1 user...', $display);
        $this->assertStringContainsString('KVS did not delete 1 selected user.', $display);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM ktvs_users')->fetchColumn());
    }

    public function testConfirmNoInteractionFailsWithoutConfirmation(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createContentTables($db);
        $db->exec(
            'CREATE TABLE ktvs_users (
                user_id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                status_id INTEGER NOT NULL,
                is_removal_requested INTEGER NOT NULL DEFAULT 0,
                total_videos_count INTEGER NOT NULL DEFAULT 0,
                comments_total_count INTEGER NOT NULL DEFAULT 0,
                last_login_date TEXT NOT NULL,
                added_date TEXT NOT NULL,
                removal_reason TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $stmt = $db->prepare(
            'INSERT INTO ktvs_users (
                user_id, username, email, status_id, is_removal_requested,
                total_videos_count, comments_total_count, last_login_date,
                added_date, removal_reason
            ) VALUES (?, ?, ?, 2, 1, 0, 0, ?, ?, ?)'
        );
        $stmt->execute([
            3,
            'removal-user',
            'removal@example.test',
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00',
            'Please delete my account',
        ]);

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $db) extends UserPurgeCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }

            protected function deleteUsersWithKvs(array $userIds, bool $withContent = true): void
            {
                throw new \RuntimeException('delete should not run');
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([
            '--removal-requested' => true,
            '--no-content' => true,
            '--confirm' => true,
        ], ['interactive' => false]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('confirmation was not provided', $display);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM ktvs_users')->fetchColumn());
    }

    public function testConfirmUsesSingularUserLabelWhenDeletingOneUser(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createContentTables($db);
        $db->exec(
            'CREATE TABLE ktvs_users (
                user_id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                status_id INTEGER NOT NULL,
                is_removal_requested INTEGER NOT NULL DEFAULT 0,
                total_videos_count INTEGER NOT NULL DEFAULT 0,
                comments_total_count INTEGER NOT NULL DEFAULT 0,
                last_login_date TEXT NOT NULL,
                added_date TEXT NOT NULL,
                removal_reason TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $stmt = $db->prepare(
            'INSERT INTO ktvs_users (
                user_id, username, email, status_id, is_removal_requested,
                total_videos_count, comments_total_count, last_login_date,
                added_date, removal_reason
            ) VALUES (?, ?, ?, 2, 1, 0, 0, ?, ?, ?)'
        );
        $stmt->execute([
            3,
            'removal-user',
            'removal@example.test',
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00',
            'Please delete my account',
        ]);

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $db) extends UserPurgeCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }

            protected function deleteUsersWithKvs(array $userIds, bool $withContent = true): void
            {
                foreach ($userIds as $userId) {
                    $stmt = $this->testDb->prepare('DELETE FROM ktvs_users WHERE user_id = ?');
                    $stmt->execute([$userId]);
                }
            }
        };
        $tester = new CommandTester($command);

        $tester->execute([
            '--removal-requested' => true,
            '--no-content' => true,
            '--confirm' => true,
            '--yes' => true,
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('Total: 1 user', $display);
        $this->assertStringContainsString('Deleting 1 user...', $display);
        $this->assertStringContainsString('Successfully deleted 1 user.', $display);
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_users')->fetchColumn());
    }
}
