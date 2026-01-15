<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Content\ModelCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

#[CoversClass(ModelCommand::class)]
class ModelCommandTest extends TestCase
{
    private Configuration $config;
    private ModelCommand $command;
    private CommandTester $tester;
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found at ' . $kvsPath);
        }

        $this->config = new Configuration(['path' => $kvsPath]);
        $this->command = new ModelCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);

        try {
            $this->db = TestHelper::getPDO();
            // Check if required tables exist
            $prefix = $this->config->getTablePrefix();
            $this->db->query("SELECT 1 FROM {$prefix}models LIMIT 1");
            $this->db->query("SELECT 1 FROM {$prefix}models_albums LIMIT 1");
        } catch (\PDOException $e) {
            $this->markTestSkipped('Cannot connect to test database or required tables missing: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testListModelsDefault(): void
    {
        $this->tester->execute(['action' => 'list']);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListModelsWithLimit(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--limit' => 5
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListModelsActiveStatus(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--status' => 'active'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListModelsSearch(): void
    {
        $this->tester->execute([
            'action' => 'list',
            '--search' => 'test'
        ]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    #[DataProvider('provideOutputFormats')]
    public function testListModelsFormats(string $format): void
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
            'table format' => ['table'],
            'json format' => ['json'],
        ];
    }

    public function testShowModel(): void
    {
        // Get a model ID
        $table = $this->config->getTablePrefix() . 'models';
        $stmt = $this->db->query("SELECT model_id FROM {$table} LIMIT 1");
        $modelId = $stmt->fetchColumn();

        if ($modelId === false) {
            $this->markTestSkipped('No models in database');
        }

        $this->tester->execute([
            'action' => 'show',
            'id' => $modelId
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Model:', $output);
        $this->assertStringContainsString('Model ID', $output);
    }

    public function testShowModelNotFound(): void
    {
        $this->tester->execute([
            'action' => 'show',
            'id' => '999999999'
        ]);

        $output = $this->tester->getDisplay();
        $this->assertEquals(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('not found', $output);
    }

    public function testShowModelMissingId(): void
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
        $this->assertStringContainsString('Model Statistics', $output);
    }

    public function testDefaultActionIsList(): void
    {
        $this->tester->execute([]);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }
}
