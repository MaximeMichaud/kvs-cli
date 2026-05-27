<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\UserPurgeCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
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

    public function testDryRunExcludesSystemAccounts(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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

            protected function deleteUsersWithKvs(array $userIds): void
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

    public function testConfirmUsesSingularUserLabelWhenDeletingOneUser(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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

            protected function deleteUsersWithKvs(array $userIds): void
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
