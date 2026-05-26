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
        $this->assertSame(['192.0.2.10'], $this->fetchColumn($db, 'ktvs_users_blocked_ips', 'ip'));
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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
