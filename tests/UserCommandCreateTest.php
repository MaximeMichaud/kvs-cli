<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\UserCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UserCommandCreateTest extends TestCase
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
        TestHelper::removeDir($this->tempDir);
    }

    public function testCreateUserLeavesLoginAndIpDefaultsUntouched(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->createSchema($db);

        $command = $this->createCommand($db);

        $tester = new CommandTester($command);
        $tester->setInputs([
            'codex_create_user',
            'codex_create_user@example.invalid',
            'CodexTempPass123',
            'Codex Create User',
        ]);
        $tester->execute(['action' => 'create']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('User created successfully with ID: 1', $tester->getDisplay());

        $stmt = $db->prepare('SELECT * FROM ktvs_users WHERE username = :username');
        $stmt->execute(['username' => 'codex_create_user']);
        $user = $stmt->fetch();

        $this->assertIsArray($user);
        $this->assertSame('codex_create_user@example.invalid', $user['email']);
        $this->assertSame('Codex Create User', $user['display_name']);
        $this->assertSame(2, (int) $user['status_id']);
        $this->assertNotSame('', $user['added_date']);
        $this->assertSame('0000-00-00 00:00:00', $user['last_login_date']);
        $this->assertSame(0, (int) $user['ip']);
    }

    public function testCreateUserRejectsDuplicateDisplayName(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->createSchema($db);

        $db->exec(
            "INSERT INTO ktvs_users (username, email, pass, display_name, status_id, added_date) " .
            "VALUES ('existing_user', 'existing@example.invalid', 'hash', 'Existing Display', 2, '2026-01-01')"
        );

        $tester = new CommandTester($this->createCommand($db));
        $tester->setInputs([
            'new_unique_user',
            'new_unique_user@example.invalid',
            'CodexTempPass123',
            'Existing Display',
        ]);
        $tester->execute(['action' => 'create']);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Username, email, or display name already exists', $tester->getDisplay());
        $this->assertSame(
            1,
            (int) $db->query("SELECT COUNT(*) FROM ktvs_users WHERE display_name = 'Existing Display'")->fetchColumn()
        );
    }

    public function testCreateUserRejectsInvalidEmail(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->createSchema($db);

        $tester = new CommandTester($this->createCommand($db));
        $tester->setInputs([
            'invalid_email_user',
            'not-an-email',
            'CodexTempPass123',
            'Invalid Email User',
        ]);
        $tester->execute(['action' => 'create']);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Invalid email address', $tester->getDisplay());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_users')->fetchColumn());
    }

    /**
     * @dataProvider unsupportedCreateInputs
     * @param array<string, string|bool> $arguments
     */
    public function testCreateRejectsUnsupportedInputsBeforePromptingOrInserting(array $arguments): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createSchema($db);
        $tester = new CommandTester($this->createCommand($db));
        $tester->setInputs(['fixture-new', 'fixture-new@example.invalid', 'SyntheticPassword1', 'Fixture New']);

        $tester->execute(['action' => 'create'] + $arguments);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('create action does not support', $tester->getDisplay());
        $this->assertStringNotContainsString('Create New User', $tester->getDisplay());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ktvs_users')->fetchColumn());
    }

    /** @return iterable<string, array{array<string, string|bool>}> */
    public static function unsupportedCreateInputs(): iterable
    {
        $options = [
            '--status' => 'disabled',
            '--search' => 'unrelated-user',
            '--country' => '1',
            '--gender' => 'female',
            '--ip' => '127.0.0.2',
            '--activity' => 'no/logins',
            '--field-filter' => 'filled/avatar',
            '--banned-status' => 'permanent',
            '--removal-requested' => true,
            '--trusted' => true,
            '--untrusted' => true,
            '--limit' => '20',
            '--format' => 'json',
            '--fields' => 'username',
            '--field' => 'username',
            '--no-truncate' => true,
            '--yes' => true,
        ];
        foreach ($options as $option => $value) {
            yield $option => [[$option => $value]];
        }
        yield 'positional identifier' => [['id' => 'unrelated-user']];
        yield 'invalid format' => [['--format' => 'invalid']];
        yield 'explicit default format' => [['--format' => 'table']];
        yield 'unknown fields' => [['--fields' => 'nonexistent_field']];
        yield 'conflicting trust' => [['--trusted' => true, '--untrusted' => true]];
    }

    private function createSchema(\PDO $db): void
    {
        $db->exec(
            'CREATE TABLE ktvs_users (' .
            'user_id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, pass TEXT, display_name TEXT, ' .
            'status_id INTEGER, added_date TEXT, ' .
            "last_login_date TEXT NOT NULL DEFAULT '0000-00-00 00:00:00', " .
            'ip INTEGER NOT NULL DEFAULT 0)'
        );
    }

    private function createCommand(\PDO $db): UserCommand
    {
        return new class (new Configuration(['path' => $this->tempDir]), $db) extends UserCommand {
            public function __construct(Configuration $config, private \PDO $testDb)
            {
                parent::__construct($config);
                $this->setName('content:user');
            }

            protected function getDatabaseConnection(bool $quiet = false): ?\PDO
            {
                return $this->testDb;
            }
        };
    }
}
