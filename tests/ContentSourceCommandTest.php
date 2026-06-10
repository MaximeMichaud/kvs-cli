<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Content\ContentSourceCommand;
use KVS\CLI\Config\Configuration;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ContentSourceCommandTest extends TestCase
{
    private string $tempDir;
    private PDO $pdo;
    private ContentSourceCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-source-test-');
        TestHelper::createMockKvsInstallation($this->tempDir);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();

        $config = new Configuration(['path' => $this->tempDir]);
        $this->command = new class ($config, $this->pdo) extends ContentSourceCommand {
            private PDO $pdo;

            public function __construct(Configuration $config, PDO $pdo)
            {
                $this->pdo = $pdo;
                parent::__construct($config);
            }

            protected function getDatabaseConnection(bool $quiet = false): ?PDO
            {
                return $this->pdo;
            }
        };

        $app = new Application();
        $app->add($this->command);
        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->tempDir);
    }

    public function testCommandMetadata(): void
    {
        $this->assertSame('content:source', $this->command->getName());
        $this->assertContains('source', $this->command->getAliases());
        $this->assertContains('site', $this->command->getAliases());
        $this->assertTrue($this->command->getDefinition()->hasOption('dir'));
        $this->assertTrue($this->command->getDefinition()->hasOption('url'));
        $this->assertTrue($this->command->getDefinition()->hasOption('description'));
    }

    public function testCreateRequiresTitle(): void
    {
        $exitCode = $this->tester->execute(['action' => 'create']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('title is required', $this->tester->getDisplay());
    }

    public function testCreateContentSource(): void
    {
        $exitCode = $this->tester->execute([
            'action' => 'create',
            'identifier' => 'Sample Source',
            '--dir' => 'sample-source',
            '--url' => 'https://sample-source.example/',
            '--description' => 'Random chat source.',
            '--sort' => '10',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Content source created: Sample Source', $this->tester->getDisplay());
        $this->assertSame(1, $this->countRows('ktvs_content_sources'));
        $this->assertSame('Sample Source', $this->fetchValue('SELECT title FROM ktvs_content_sources'));
        $this->assertSame('sample-source', $this->fetchValue('SELECT dir FROM ktvs_content_sources'));
        $this->assertSame(10, (int) $this->fetchValue('SELECT sort_id FROM ktvs_content_sources'));
    }

    public function testCreateGeneratesDirAndRejectsDuplicate(): void
    {
        $firstExitCode = $this->tester->execute([
            'action' => 'create',
            'identifier' => 'Dirty Roulette',
        ]);
        $secondExitCode = $this->tester->execute([
            'action' => 'create',
            'identifier' => 'Dirty Roulette',
        ]);

        $this->assertSame(0, $firstExitCode);
        $this->assertSame(1, $secondExitCode);
        $this->assertSame('dirty-roulette', $this->fetchValue('SELECT dir FROM ktvs_content_sources'));
        $this->assertStringContainsString('already exists', $this->tester->getDisplay());
    }

    public function testListAndShowCreatedSource(): void
    {
        $this->tester->execute([
            'action' => 'create',
            'identifier' => 'Flingster',
            '--dir' => 'flingster',
            '--status' => 'inactive',
        ]);

        $listExitCode = $this->tester->execute([
            'action' => 'list',
            '--search' => 'Flingster',
        ]);
        $listOutput = $this->tester->getDisplay();

        $showExitCode = $this->tester->execute([
            'action' => 'show',
            'identifier' => 'flingster',
        ]);
        $showOutput = $this->tester->getDisplay();

        $this->assertSame(0, $listExitCode);
        $this->assertStringContainsString('Flingster', $listOutput);
        $this->assertSame(0, $showExitCode);
        $this->assertStringContainsString('Content source: Flingster', $showOutput);
        $this->assertStringContainsString('Inactive', $showOutput);
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE ktvs_content_sources (
            content_source_id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            dir TEXT NOT NULL,
            description TEXT DEFAULT "",
            url TEXT DEFAULT "",
            status_id INTEGER DEFAULT 1,
            rating INTEGER DEFAULT 0,
            rating_amount INTEGER DEFAULT 0,
            sort_id INTEGER DEFAULT 0,
            added_date TEXT DEFAULT "",
            last_content_date TEXT DEFAULT "",
            total_videos INTEGER DEFAULT 0,
            total_albums INTEGER DEFAULT 0
        )');
    }

    private function countRows(string $table): int
    {
        return (int) $this->fetchValue("SELECT COUNT(*) FROM $table");
    }

    private function fetchValue(string $sql): mixed
    {
        $result = $this->pdo->query($sql);
        if ($result === false) {
            return null;
        }
        return $result->fetchColumn();
    }
}
