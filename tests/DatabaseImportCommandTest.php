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

    public function testImportRejectsDirectoryBeforeConfirmation(): void
    {
        $command = new ImportCommand(new Configuration([
            'path' => $this->tempDir,
            'disable_db_env_overrides' => true,
        ]));
        $tester = new CommandTester($command);

        $tester->execute(['file' => $this->tempDir], ['interactive' => false]);

        $display = $tester->getDisplay();
        $this->assertSame(1, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('Import path is a directory', $display);
        $this->assertStringNotContainsString('overwrite existing data', $display);
    }

    public function testImportRejectsUnreadableFileBeforeConfirmation(): void
    {
        $unreadableFile = $this->tempDir . '/unreadable.sql';
        file_put_contents($unreadableFile, "SELECT 1;\n");
        chmod($unreadableFile, 0000);

        if (is_readable($unreadableFile)) {
            chmod($unreadableFile, 0600);
            $this->markTestSkipped('Current user can still read chmod 0000 files.');
        }

        try {
            $command = new ImportCommand(new Configuration([
                'path' => $this->tempDir,
                'disable_db_env_overrides' => true,
            ]));
            $tester = new CommandTester($command);

            $tester->execute(['file' => $unreadableFile], ['interactive' => false]);

            $display = $tester->getDisplay();
            $this->assertSame(1, $tester->getStatusCode(), $display);
            $this->assertStringContainsString('Import file is not readable', $display);
            $this->assertStringNotContainsString('overwrite existing data', $display);
        } finally {
            chmod($unreadableFile, 0600);
        }
    }

    public function testFileArgumentDocumentsAllSupportedExtensions(): void
    {
        $command = new ImportCommand(new Configuration([
            'path' => $this->tempDir,
            'disable_db_env_overrides' => true,
        ]));

        $description = $command->getDefinition()->getArgument('file')->getDescription();

        foreach (['.sql', '.gz', '.gzip', '.zst', '.zstd', '.xz', '.bz2', '.bzip2'] as $extension) {
            $this->assertStringContainsString($extension, $description);
        }
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

    public function testImportStreamsLargeSqlFileUnderLowPhpMemory(): void
    {
        $sqlFile = $this->tempDir . '/large.sql';
        $this->writeLargeFile($sqlFile, 32);

        $toolsDir = $this->tempDir . '/tools-large-import';
        $bytesFile = $this->tempDir . '/large-import.bytes';
        $this->createFakeMysql($toolsDir, $bytesFile);

        [$exitCode, $output] = $this->runImportSubprocess($toolsDir, $sqlFile);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertSame((string) (32 * 1024 * 1024), trim((string) file_get_contents($bytesFile)));
    }

    public function testImportStreamsGzipFileUnderLowPhpMemory(): void
    {
        $gzipFile = $this->tempDir . '/large.sql.gz';
        $this->writeLargeGzipFile($gzipFile, 32);

        $toolsDir = $this->tempDir . '/tools-large-gzip-import';
        $bytesFile = $this->tempDir . '/large-gzip-import.bytes';
        $this->createFakeMysql($toolsDir, $bytesFile);

        [$exitCode, $output] = $this->runImportSubprocess($toolsDir, $gzipFile);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertSame((string) (32 * 1024 * 1024), trim((string) file_get_contents($bytesFile)));
    }

    public function testImportSuppressesMariaDbSslPasswordWarning(): void
    {
        $toolsDir = $this->tempDir . '/tools-mariadb-warning';
        $bytesFile = $this->tempDir . '/mariadb-warning.bytes';
        $this->createFakeMysql($toolsDir, $bytesFile, [
            'WARNING: option --ssl-verify-server-cert is disabled, because of an insecure passwordless login.',
            'real mysql stderr',
        ]);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $command = new ImportCommand(new Configuration([
                'path' => $this->tempDir,
                'disable_db_env_overrides' => true,
            ]));
            $tester = new CommandTester($command);
            $tester->setInputs(['yes']);

            $tester->execute(['file' => $this->sqlFile], ['decorated' => false]);

            $display = $tester->getDisplay();
            $this->assertSame(0, $tester->getStatusCode(), $display);
            $this->assertStringNotContainsString('ssl-verify-server-cert', $display);
            $this->assertStringContainsString('real mysql stderr', $display);
            $this->assertSame((string) strlen("SELECT 1;\n"), trim((string) file_get_contents($bytesFile)));
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    /**
     * @param list<string> $stderrLines
     */
    private function createFakeMysql(string $toolsDir, string $bytesFile, array $stderrLines = []): void
    {
        mkdir($toolsDir, 0755, true);

        $mysql = $toolsDir . '/mysql';
        $stderrScript = '';
        foreach ($stderrLines as $line) {
            $stderrScript .= 'echo ' . escapeshellarg($line) . " >&2\n";
        }

        file_put_contents(
            $mysql,
            "#!/bin/sh\n"
            . $stderrScript
            . 'wc -c < /dev/stdin > ' . escapeshellarg($bytesFile) . "\n"
        );
        chmod($mysql, 0755);
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function runImportSubprocess(string $toolsDir, string $file): array
    {
        $runner = $this->tempDir . '/run-db-import.php';
        file_put_contents(
            $runner,
            str_replace('__AUTOLOAD__', var_export(dirname(__DIR__) . '/vendor/autoload.php', true), <<<'PHP'
<?php

require __AUTOLOAD__;

use KVS\CLI\Command\Database\ImportCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

$command = new ImportCommand(new Configuration([
    'path' => $argv[1],
    'disable_db_env_overrides' => true,
]));
$application = new Application();
$application->add($command);

$tester = new CommandTester($command);
$tester->setInputs(['yes']);
$tester->execute(['file' => $argv[2]], ['decorated' => false]);

echo $tester->getDisplay();

exit($tester->getStatusCode());
PHP)
        );

        $path = $toolsDir . PATH_SEPARATOR . (getenv('PATH') !== false ? getenv('PATH') : '');
        $command = sprintf(
            'PATH=%s %s -d memory_limit=16M %s %s %s 2>&1',
            escapeshellarg($path),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($runner),
            escapeshellarg($this->tempDir),
            escapeshellarg($file)
        );

        $output = [];
        exec($command, $output, $exitCode);

        return [$exitCode, $output];
    }

    private function writeLargeFile(string $file, int $megabytes): void
    {
        $handle = fopen($file, 'wb');
        $this->assertIsResource($handle);

        try {
            $chunk = str_repeat("\0", 1024 * 1024);
            for ($i = 0; $i < $megabytes; $i++) {
                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    private function writeLargeGzipFile(string $file, int $megabytes): void
    {
        $handle = gzopen($file, 'wb');
        $this->assertIsResource($handle);

        try {
            $chunk = str_repeat("\0", 1024 * 1024);
            for ($i = 0; $i < $megabytes; $i++) {
                gzwrite($handle, $chunk);
            }
        } finally {
            gzclose($handle);
        }
    }
}
