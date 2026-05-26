<?php

namespace KVS\CLI\Tests;

use KVS\CLI\Command\Database\ImportCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DatabaseImportCommandTest extends TestCase
{
    private string $tempDir;
    private string $sqlFile;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-db-import-test-');
        TestHelper::createMockKvsInstallation($this->tempDir);

        $this->sqlFile = $this->tempDir . '/import.sql';
        file_put_contents($this->sqlFile, "SELECT 1;\n");
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->tempDir);
    }

    public function testNoInteractionCancelsWithFailureBeforeImport(): void
    {
        $command = new ImportCommand(new Configuration([
            'path' => $this->tempDir,
            'disable_db_env_overrides' => true,
        ]));
        $tester = new CommandTester($command);

        $tester->execute(['file' => $this->sqlFile], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('cancelled in non-interactive mode', $tester->getDisplay());
        $this->assertStringNotContainsString('Database imported successfully', $tester->getDisplay());
    }

    public function testMissingZstdReportsActionableError(): void
    {
        $zstdFile = $this->tempDir . '/import.sql.zstd';
        file_put_contents($zstdFile, 'not really zstd');

        $emptyBin = $this->tempDir . '/empty-bin';
        mkdir($emptyBin);
        $previousPath = getenv('PATH');
        putenv('PATH=' . $emptyBin);

        try {
            $command = new ImportCommand(new Configuration([
                'path' => $this->tempDir,
                'disable_db_env_overrides' => true,
            ]));
            $tester = new CommandTester($command);
            $tester->setInputs(['yes']);

            $tester->execute(['file' => $zstdFile]);

            $display = $tester->getDisplay();
            $this->assertSame(1, $tester->getStatusCode(), $display);
            $this->assertStringContainsString("Required decompression tool 'zstd'", $display);
            $this->assertStringContainsString('Install zstd', $display);
            $this->assertStringNotContainsString('Database imported successfully', $display);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }
}
