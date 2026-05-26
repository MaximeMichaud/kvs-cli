<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\AntispamCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AntispamCommandJsonTest extends TestCase
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

    public function testJsonOmitsUnsupportedMessageAndFeedbackRules(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE ktvs_options (variable TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $db->exec('CREATE TABLE ktvs_users_blocked_domains (domain TEXT, sort_id INTEGER)');
        $db->exec('CREATE TABLE ktvs_users_blocked_ips (ip TEXT, sort_id INTEGER)');

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $db) extends AntispamCommand {
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
            '--force' => true,
            'action' => 'show',
            '--format' => 'json',
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);

        $data = json_decode($display, true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('captcha', $data['sections']['videos']);
        $this->assertArrayHasKey('disable', $data['sections']['videos']);

        foreach (['messages', 'feedbacks'] as $section) {
            $this->assertArrayNotHasKey('captcha', $data['sections'][$section]);
            $this->assertArrayNotHasKey('disable', $data['sections'][$section]);
            $this->assertArrayHasKey('delete', $data['sections'][$section]);
            $this->assertArrayHasKey('error', $data['sections'][$section]);
        }
    }
}
