<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\AntispamCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AntispamCommandAtomicUpdateTest extends TestCase
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

    public function testDomainReplacementRollsBackOnInsertFailure(): void
    {
        $db = $this->createDatabase();
        $db->exec("INSERT INTO ktvs_users_blocked_domains (domain, sort_id) VALUES ('existing.test', 0)");

        $tester = new CommandTester($this->createCommand($db));
        $tester->execute([
            '--force' => true,
            'action' => 'set',
            '--domains' => 'duplicate.test,duplicate.test',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('UNIQUE constraint failed:', $tester->getDisplay());
        $this->assertStringContainsString('ktvs_users_blocked_domains.domain', $tester->getDisplay());
        $this->assertSame(['existing.test'], $this->fetchColumn($db, 'ktvs_users_blocked_domains', 'domain'));
    }

    public function testIpReplacementRollsBackOnInsertFailure(): void
    {
        $db = $this->createDatabase();
        $db->exec("INSERT INTO ktvs_users_blocked_ips (ip, sort_id) VALUES ('192.0.2.10', 0)");

        $tester = new CommandTester($this->createCommand($db));
        $tester->execute([
            '--force' => true,
            'action' => 'set',
            '--ips' => '198.51.100.20,198.51.100.20',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('UNIQUE constraint failed:', $tester->getDisplay());
        $this->assertStringContainsString('ktvs_users_blocked_ips.ip', $tester->getDisplay());
        $this->assertSame(['192.0.2.10'], $this->fetchColumn($db, 'ktvs_users_blocked_ips', 'ip'));
    }

    public function testSetPreservesOriginalListWhenLaterRuleIsInvalid(): void
    {
        $db = $this->createDatabase();
        $db->exec("INSERT INTO ktvs_users_blocked_domains VALUES ('existing.test', 0)");
        $tester = new CommandTester($this->createCommand($db));

        $tester->execute([
            '--force' => true,
            'action' => 'set',
            '--domains' => 'replacement.test',
            '--comments-captcha' => 'invalid',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Invalid rule format', $tester->getDisplay());
        $this->assertSame(['existing.test'], $this->fetchColumn($db, 'ktvs_users_blocked_domains', 'domain'));
        $this->assertFalse($db->inTransaction());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blacklistMutations')]
    public function testFailedLaterListRollsBackWholeMutation(string $action): void
    {
        $db = $this->createDatabase();
        $db->exec("INSERT INTO ktvs_users_blocked_domains VALUES ('existing.test', 0)");
        $db->exec("INSERT INTO ktvs_users_blocked_ips VALUES ('192.0.2.10', 0)");
        $db->exec(<<<'SQL'
CREATE TRIGGER reject_ip_insert BEFORE INSERT ON ktvs_users_blocked_ips
BEGIN SELECT RAISE(ABORT, 'Synthetic IP write failure'); END
SQL
        );
        $db->exec(<<<'SQL'
CREATE TRIGGER reject_ip_delete BEFORE DELETE ON ktvs_users_blocked_ips
BEGIN SELECT RAISE(ABORT, 'Synthetic IP write failure'); END
SQL
        );
        $tester = new CommandTester($this->createCommand($db));
        $tester->execute([
            '--force' => true,
            'action' => $action,
            '--domains' => $action === 'remove' ? 'existing.test' : 'replacement.test',
            '--ips' => $action === 'remove' ? '192.0.2.10' : '198.51.100.20',
        ]);

        $this->assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Synthetic IP write failure', $tester->getDisplay());
        $this->assertSame(['existing.test'], $this->fetchColumn($db, 'ktvs_users_blocked_domains', 'domain'));
        $this->assertSame(['192.0.2.10'], $this->fetchColumn($db, 'ktvs_users_blocked_ips', 'ip'));
        $this->assertFalse($db->inTransaction());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blacklistMutations')]
    public function testValidMultipleListMutationCommitsTogether(string $action): void
    {
        $db = $this->createDatabase();
        $tester = new CommandTester($this->createCommand($db));
        foreach (['set', 'add', 'remove'] as $step) {
            if ($step === 'remove' && $action !== 'remove') {
                break;
            }
            $tester->execute([
                '--force' => true,
                'action' => $step,
                '--domains' => 'example.test',
                '--ips' => '192.0.2.10',
            ]);
            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            if ($step === $action) {
                break;
            }
        }
        $this->assertSame($action === 'remove' ? [] : ['example.test'], $this->fetchColumn($db, 'ktvs_users_blocked_domains', 'domain'));
        $this->assertSame($action === 'remove' ? [] : ['192.0.2.10'], $this->fetchColumn($db, 'ktvs_users_blocked_ips', 'ip'));
        $this->assertFalse($db->inTransaction());
    }

    /** @return list<array{string}> */
    public static function blacklistMutations(): array
    {
        return [['set'], ['add'], ['remove']];
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_options (variable TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $db->exec('CREATE TABLE ktvs_users_blocked_domains (domain TEXT UNIQUE, sort_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users_blocked_ips (ip TEXT UNIQUE, sort_id INTEGER)');

        return $db;
    }

    private function createCommand(PDO $db): AntispamCommand
    {
        $config = TestHelper::createTestConfiguration($this->kvsPath);

        return new class ($config, $db) extends AntispamCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
    }

    /**
     * @return array<string>
     */
    private function fetchColumn(PDO $db, string $table, string $column): array
    {
        $stmt = $db->query("SELECT $column FROM $table ORDER BY sort_id");
        $this->assertNotFalse($stmt);

        /** @var array<string> $values */
        $values = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $values;
    }
}
