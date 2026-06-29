<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Settings\OptionsCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class OptionsCommandTest extends TestCase
{
    private string $kvsPath;
    private PDO $db;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTestKvsInstallation();
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE ' . TestHelper::table('options') . ' (variable TEXT PRIMARY KEY, value TEXT)');
        $this->db->exec(
            "INSERT INTO " . TestHelper::table('options') .
            " (variable, value) VALUES ('ENABLE_DVD_FIELD_1', '1'), ('SCREENSHOTS_SIZE', '320x180')"
        );

        $config = TestHelper::createTestConfiguration($this->kvsPath);
        $command = new class ($config, $this->db) extends OptionsCommand {
            public function __construct(Configuration $config, private PDO $testDb)
            {
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->testDb;
            }
        };
        $this->tester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testGetOptionSupportsJsonFormat(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'ENABLE_DVD_FIELD_1',
            '--format' => 'json',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();
        $rows = json_decode($display, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertSame('ENABLE_DVD_FIELD_1', $rows[0]['variable'] ?? null);
        $this->assertSame('1', $rows[0]['value'] ?? null);
        $this->assertSame('System', $rows[0]['category'] ?? null);
        $this->assertSame('Enabled', $rows[0]['status'] ?? null);
        $this->assertStringNotContainsString('Option:', $display);
    }

    public function testGetOptionKeepsTableOutputByDefault(): void
    {
        $this->tester->execute([
            'action' => 'get',
            'name' => 'SCREENSHOTS_SIZE',
            '--force' => true,
        ]);

        $display = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $display);
        $this->assertStringContainsString('Option: SCREENSHOTS_SIZE', $display);
        $this->assertStringContainsString('Dimension (WxH)', $display);
    }
}
