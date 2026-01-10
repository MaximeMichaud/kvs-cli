<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\DvdCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(DvdCommand::class)]
class DvdCommandTest extends TestCase
{
    private Configuration $config;
    private DvdCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new DvdCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);

        try {
            $this->db = TestHelper::getPDO();
        } catch (\PDOException $e) {
            $this->markTestSkipped('Cannot connect to test database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testListDvdsDefault(): void
    {
        $this->tester->execute(['action' => 'list']);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListDvdsWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 5
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListDvdsActiveStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListDvdsDisabledStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'disabled'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListDvdsSearch(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'test'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    #[DataProvider('provideOutputFormats')]
    public function testListDvdsFormats(string $format, string $assertMethod): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--format' => $format,
            '--limit' => 3
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public static function provideOutputFormats(): array
    {
        return [
            'table format' => ['table', 'assertSuccess'],
            'json format' => ['json', 'assertSuccess'],
        ];
    }

    public function testShowDvd(): void
    {
        // Get a DVD ID
        $stmt = $this->db->query("SELECT dvd_id FROM ktvs_dvds LIMIT 1");
        $dvdId = $stmt->fetchColumn();

        if ($dvdId === false) {
            $this->markTestSkipped('No DVDs in database');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => $dvdId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD #' . $dvdId, $output);
    }

    public function testShowDvdNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('not found', $output);
    }

    public function testShowDvdMissingId(): void
    {
        $this->tester->execute([
            'action' => 'show'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('required', $output);
    }

    public function testStats(): void
    {
        $this->tester->execute(['action' => 'stats']);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('DVD Statistics', $output);
    }

    public function testDefaultActionIsList(): void
    {
        $this->tester->execute([]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testInvalidActionFallsBackToList(): void
    {
        $this->tester->execute(['action' => 'invalid']);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }
}
