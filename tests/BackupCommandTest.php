<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use KVS\CLI\Command\System\BackupCommand;
use KVS\CLI\Config\Configuration;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class BackupCommandTest extends TestCase
{
    private string $rootDir;
    private string $tempDir;
    private Configuration $config;
    private BackupCommand $command;
    private CommandTester $tester;
    private mixed $originalEnvPath;

    protected function setUp(): void
    {
        $this->originalEnvPath = $_ENV['PATH'] ?? null;
        unset($_ENV['PATH']);
        // Create mock KVS installation
        $this->rootDir = TestHelper::createTempDir('kvs-backup-test-');
        $this->tempDir = $this->rootDir . '/kvs';
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data', 0755, true);
        mkdir($this->tempDir . '/backups', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new BackupCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        if ($this->originalEnvPath === null) {
            unset($_ENV['PATH']);
        } else {
            $_ENV['PATH'] = $this->originalEnvPath;
        }
        if (is_dir($this->rootDir)) {
            TestHelper::removeDir($this->rootDir);
        }
    }

    public function testBackupCreate(): void
    {
        try {
            $this->tester->execute(['--create' => true]);
            $output = $this->tester->getDisplay();
            // May fail without mysqldump, but should show attempt
            $this->assertStringContainsString('backup', strtolower($output));
        } catch (\Exception $e) {
            // Expected if mysqldump not available
            $this->assertStringContainsString('backup', strtolower($e->getMessage()));
        }
    }

    public function testDatabaseBackupFailsWhenDumpCommandFails(): void
    {
        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);
        $mysqldump = $toolsDir . '/mysqldump';
        file_put_contents(
            $mysqldump,
            <<<'SH'
#!/bin/sh
echo dump failed >&2
exit 1
SH
        );
        chmod($mysqldump, 0755);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir);

        try {
            $this->tester->execute([
                '--create' => true,
                '--type' => 'db',
                '--output' => $this->tempDir . '/backups',
            ]);

            $output = $this->tester->getDisplay();
            $this->assertSame(1, $this->tester->getStatusCode(), $output);
            $this->assertStringContainsString('Database backup failed', $output);
            $this->assertStringNotContainsString('Backup created', $output);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    #[DataProvider('dumpClientAvailabilityProvider')]
    public function testDatabaseBackupHandlesDumpClientAvailability(bool $available): void
    {
        $toolsDir = $this->tempDir . '/dump-client-tools';
        mkdir($toolsDir);
        if ($available) {
            $this->writeTool($toolsDir, 'mariadb-dump', <<<'SH'
#!/bin/sh
printf '%128s\n' 'Synthetic MariaDB dump'
SH);
        }

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir);
        try {
            $this->tester->execute([
                '--create' => true,
                '--type' => 'db',
                '--output' => $this->tempDir . '/backups',
            ]);

            $output = $this->tester->getDisplay();
            $this->assertSame($available ? 0 : 1, $this->tester->getStatusCode(), $output);
            $archives = glob($this->tempDir . '/backups/*_db.sql.gz') ?: [];
            $this->assertCount($available ? 1 : 0, $archives);
            $this->assertSame([], glob($this->tempDir . '/backups/.kvs-backup-*') ?: []);
            if ($available) {
                $this->assertSame(sprintf('%128s', 'Synthetic MariaDB dump') . "\n", gzdecode(file_get_contents($archives[0])));
            } else {
                $this->assertStringContainsString('Database dump command not found', $output);
                $this->assertStringNotContainsString('Backup created', $output);
            }
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function dumpClientAvailabilityProvider(): iterable
    {
        yield 'MariaDB client only' => [true];
        yield 'no dump client' => [false];
    }

    public function testBackupCreateRejectsInvalidTypeBeforeCreatingOutputDirectory(): void
    {
        $outputDir = $this->rootDir . '/invalid-type-output';

        $this->tester->execute([
            '--create' => true,
            '--type' => 'bogus',
            '--output' => $outputDir,
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Invalid value for --type "bogus"', $output);
        $this->assertDirectoryDoesNotExist($outputDir);
    }

    public function testBackupCreateAndListCannotBeCombined(): void
    {
        $outputDir = $this->rootDir . '/conflicting-mode-output';

        $this->tester->execute([
            '--create' => true,
            '--list' => true,
            '--output' => $outputDir,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Options --create and --list cannot be used together', $output);
        $this->assertDirectoryDoesNotExist($outputDir);
    }

    public function testBackupDefaultRejectsActionSpecificOptions(): void
    {
        foreach (['type' => 'db', 'output' => $this->rootDir . '/ignored-output', 'format' => 'json'] as $option => $value) {
            $tester = new CommandTester($this->command);
            $tester->execute(['--' . $option => $value]);

            $output = $tester->getDisplay();

            $this->assertSame(1, $tester->getStatusCode(), $output);
            $this->assertStringContainsString("default action does not support --{$option}", $output);
            $this->assertStringNotContainsString('Available options', $output);
        }
    }

    public function testBackupCreateRejectsListOnlyFormatBeforeCreatingOutputDirectory(): void
    {
        $outputDir = $this->rootDir . '/create-format-output';

        $this->tester->execute([
            '--create' => true,
            '--format' => 'json',
            '--output' => $outputDir,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('create action does not support --format', $output);
        $this->assertDirectoryDoesNotExist($outputDir);
    }

    public function testDatabaseBackupSplitsHostPortForDumpCommand(): void
    {
        $previousHost = getenv('KVS_DB_HOST');
        $previousPort = getenv('KVS_DB_PORT');
        putenv('KVS_DB_HOST');
        putenv('KVS_DB_PORT');

        TestHelper::createMockDbConfig($this->tempDir, [
            'host' => '127.0.0.1',
            'port' => 3308,
        ]);
        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new BackupCommand($this->config);
        $this->tester = new CommandTester($this->command);

        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);
        $argsFile = $this->tempDir . '/mysqldump.args';
        $mysqldump = $toolsDir . '/mysqldump';
        file_put_contents(
            $mysqldump,
            '#!/bin/sh' . "\n"
            . 'for arg in "$@"; do echo "$arg"; done > ' . escapeshellarg($argsFile) . "\n"
            . "printf '%128s\\n' 'SQL dump'\n"
        );
        chmod($mysqldump, 0755);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute([
                '--create' => true,
                '--type' => 'db',
                '--output' => $this->tempDir . '/backups',
            ]);

            $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $args = file($argsFile, FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($args);
            $this->assertContains('-h', $args);
            $this->assertContains('127.0.0.1', $args);
            $this->assertContains('-P', $args);
            $this->assertContains('3308', $args);
            $this->assertNotContains('127.0.0.1:3308', $args);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
            if ($previousHost === false) {
                putenv('KVS_DB_HOST');
            } else {
                putenv('KVS_DB_HOST=' . $previousHost);
            }
            if ($previousPort === false) {
                putenv('KVS_DB_PORT');
            } else {
                putenv('KVS_DB_PORT=' . $previousPort);
            }
        }
    }

    public function testDatabaseBackupCreatesRestrictedFilePermissions(): void
    {
        $toolsDir = $this->tempDir . '/tools-secure-output';
        mkdir($toolsDir, 0755, true);

        $mysqldump = $toolsDir . '/mysqldump';
        file_put_contents(
            $mysqldump,
            <<<'SH'
#!/bin/sh
printf '%128s\n' 'SQL dump'
SH
        );
        chmod($mysqldump, 0755);

        $previousPath = getenv('PATH');
        $previousUmask = umask(0000);
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute([
                '--create' => true,
                '--type' => 'db',
                '--output' => $this->tempDir . '/backups',
            ]);

            $this->assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
            $files = glob($this->tempDir . '/backups/*_db.sql.gz') ?: [];
            $this->assertCount(1, $files);
            $permissions = fileperms($files[0]);
            $this->assertIsInt($permissions);
            $this->assertSame(0640, $permissions & 0777);
        } finally {
            umask($previousUmask);
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testDatabaseBackupRejectsEmptyDumpOutput(): void
    {
        $toolsDir = $this->tempDir . '/tools-empty-db-backup';
        mkdir($toolsDir, 0755, true);
        $this->writeTool($toolsDir, 'mysqldump', <<<'SH'
#!/bin/sh
exit 0
SH);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute([
                '--create' => true,
                '--type' => 'db',
                '--output' => $this->tempDir . '/backups',
            ]);

            $output = $this->tester->getDisplay();
            $this->assertSame(1, $this->tester->getStatusCode(), $output);
            $this->assertStringContainsString('SQL dump is empty or incomplete', $output);
            $this->assertSame([], glob($this->tempDir . '/backups/*_db.sql.gz') ?: []);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testDatabaseBackupStreamsLargeDumpUnderLowPhpMemory(): void
    {
        $toolsDir = $this->tempDir . '/tools-large-db-backup';
        mkdir($toolsDir, 0755, true);
        $this->writeTool($toolsDir, 'mysqldump', <<<'SH'
#!/bin/sh
dd if=/dev/zero bs=1M count=32 2>/dev/null | tr '\000' 'A'
SH);

        $runner = $this->tempDir . '/slow-pipe-reader.php';
        $reader = <<<'PHP'
<?php
namespace Symfony\Component\Process\Pipes {
    function fread($stream, $length)
    {
        // Give a fast producer time to refill the real pipe between reads.
        usleep(1000);
        return \fread($stream, $length);
    }
}
namespace {
PHP;
        file_put_contents(
            $runner,
            $reader . "\nrequire " . var_export(dirname(__DIR__) . '/bin/kvs', true) . ";\n}\n"
        );
        $path = $toolsDir . PATH_SEPARATOR . (getenv('PATH') !== false ? getenv('PATH') : '');
        $command = sprintf(
            'PATH=%s %s -d memory_limit=16M %s --path=%s system:backup --create --type=db --output=%s --no-ansi 2>&1',
            escapeshellarg($path),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($runner),
            escapeshellarg($this->tempDir),
            escapeshellarg($this->tempDir . '/backups')
        );

        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $files = glob($this->tempDir . '/backups/*_db.sql.gz') ?: [];
        $this->assertCount(1, $files);
        $this->assertSame(32 * 1024 * 1024, $this->countGzipUncompressedBytes($files[0]));
        $this->assertSame([], glob($this->tempDir . '/backups/.kvs-backup-*') ?: []);
    }

    public function testDatabaseBackupSuccessMessageShowsActualFilePath(): void
    {
        $toolsDir = $this->tempDir . '/tools-message-db';
        mkdir($toolsDir, 0755, true);
        $this->writeTool($toolsDir, 'mysqldump', <<<'SH'
#!/bin/sh
printf '%128s\n' 'SQL dump'
SH);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute([
                '--create' => true,
                '--type' => 'db',
                '--output' => $this->tempDir . '/backups',
            ]);

            $output = $this->tester->getDisplay();
            $this->assertSame(0, $this->tester->getStatusCode(), $output);
            $this->assertStringEndsWith('_db.sql.gz', $this->extractSuccessBackupPath($output));
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testFilesBackupSuccessMessageShowsActualFilePath(): void
    {
        $toolsDir = $this->tempDir . '/tools-message-files';
        mkdir($toolsDir, 0755, true);
        $this->writeTool($toolsDir, 'tar', <<<'SH'
#!/bin/sh
printf 'archive' > "$2"
SH);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute([
                '--create' => true,
                '--type' => 'files',
                '--output' => $this->tempDir . '/backups',
            ]);

            $output = $this->tester->getDisplay();
            $this->assertSame(0, $this->tester->getStatusCode(), $output);
            $this->assertStringEndsWith('_files.tar.gz', $this->extractSuccessBackupPath($output));
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testFullBackupSuccessMessageShowsActualFilePath(): void
    {
        $toolsDir = $this->tempDir . '/tools-message-full';
        mkdir($toolsDir, 0755, true);
        $this->writeTool($toolsDir, 'mysqldump', <<<'SH'
#!/bin/sh
printf '%128s\n' 'SQL dump'
SH);
        $this->writeTool($toolsDir, 'tar', <<<'SH'
#!/bin/sh
printf 'archive' > "$2"
SH);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute([
                '--create' => true,
                '--type' => 'full',
                '--output' => $this->tempDir . '/backups',
            ]);

            $output = $this->tester->getDisplay();
            $this->assertSame(0, $this->tester->getStatusCode(), $output);
            $this->assertStringEndsWith('_full.tar.gz', $this->extractSuccessBackupPath($output));
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    #[DataProvider('slowFullBackupStageProvider')]
    public function testFullBackupAllowsArchiveStagesLongerThanOneMinute(string $stage): void
    {
        $finder = new ExecutableFinder();
        $tar = $finder->find('tar');
        $gzip = $finder->find('gzip');
        $sleep = $finder->find('sleep');
        $this->assertNotNull($tar);
        $this->assertNotNull($gzip);
        $this->assertNotNull($sleep);

        $toolsDir = $this->tempDir . '/slow-archive-tools';
        mkdir($toolsDir);
        $this->writeTool($toolsDir, 'mysqldump', "#!/bin/sh\nprintf '%128s\\n' 'Synthetic SQL dump'\n");
        foreach (['tar' => $tar, 'gzip' => $gzip] as $name => $executable) {
            $delay = $stage === $name
                ? 'if [ "$1" = ' . ($name === 'tar' ? 'cf' : '-9') . ' ]; then '
                    . escapeshellarg($sleep) . " 1; fi\n"
                : '';
            $this->writeTool($toolsDir, $name, "#!/bin/sh\n" . $delay . 'exec ' . escapeshellarg($executable) . ' "$@"');
        }

        // Advance only the child process clock; tar and gzip still produce real archives.
        $bootstrap = <<<'PHP'
<?php
namespace Symfony\Component\Process {
    function microtime(bool $asFloat = false): string|float
    {
        return $asFloat ? \microtime(true) * 100.0 : \microtime(false);
    }
}
namespace {
PHP;
        $runner = $this->tempDir . '/backup-clock.php';
        file_put_contents(
            $runner,
            $bootstrap . "\nrequire " . var_export(dirname(__DIR__) . '/bin/kvs', true) . ";\n}\n"
        );
        $outputDir = $this->rootDir . '/slow-backup';
        $process = new Process([
            PHP_BINARY, $runner, '--path=' . $this->tempDir,
            'system:backup', '--create', '--type=full', '--output=' . $outputDir, '--no-ansi',
        ], null, ['PATH' => $toolsDir]);
        $process->setTimeout(15);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        $archives = glob($outputDir . '/*_full.tar.gz') ?: [];
        $this->assertCount(1, $archives);
        $listing = new Process([$tar, 'tzf', $archives[0]]);
        $listing->mustRun();
        $this->assertStringContainsString('_db.sql.gz', $listing->getOutput());
        $this->assertStringContainsString('_files.tar.gz', $listing->getOutput());
        $this->assertSame([], glob($outputDir . '/.kvs-backup-*') ?: []);
    }

    /** @return iterable<string, array{string}> */
    public static function slowFullBackupStageProvider(): iterable
    {
        yield 'tar assembly' => ['tar'];
        yield 'gzip compression' => ['gzip'];
    }

    public function testFilesBackupTreatsTarExitOneWithArchiveAsWarning(): void
    {
        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);
        $tar = $toolsDir . '/tar';
        file_put_contents(
            $tar,
            <<<'SH'
#!/bin/sh
printf 'archive' > "$2"
echo 'tar: kvs: file changed as we read it' >&2
exit 1
SH
        );
        chmod($tar, 0755);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute([
                '--create' => true,
                '--type' => 'files',
                '--output' => $this->tempDir . '/backups',
            ]);

            $output = $this->tester->getDisplay();
            $this->assertSame(0, $this->tester->getStatusCode(), $output);
            $this->assertStringContainsString('Some files changed during archival', $output);
            $this->assertStringContainsString('Files backup created', $output);
            $this->assertNotFalse(glob($this->tempDir . '/backups/*_files.tar.gz'));
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testFullBackupCleansPartialFilesWhenFilesStepFails(): void
    {
        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);

        $mysqldump = $toolsDir . '/mysqldump';
        file_put_contents(
            $mysqldump,
            <<<'SH'
#!/bin/sh
printf '%128s\n' 'SQL dump'
SH
        );
        chmod($mysqldump, 0755);

        $tar = $toolsDir . '/tar';
        file_put_contents(
            $tar,
            <<<'SH'
#!/bin/sh
echo 'fatal tar failure' >&2
exit 2
SH
        );
        chmod($tar, 0755);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute([
                '--create' => true,
                '--type' => 'full',
                '--output' => $this->tempDir . '/backups',
            ]);

            $output = $this->tester->getDisplay();
            $this->assertSame(1, $this->tester->getStatusCode(), $output);
            $this->assertStringContainsString('Files backup failed', $output);
            $this->assertStringContainsString('Removed partial backup files', $output);
            $this->assertSame([], glob($this->tempDir . '/backups/*_db.sql.gz') ?: []);
            $this->assertSame([], glob($this->tempDir . '/backups/*_files.tar.gz') ?: []);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testBackupList(): void
    {
        // BackupCommand looks in dirname(kvsPath)/backups
        $backupsDir = dirname($this->tempDir) . '/backups';
        if (!is_dir($backupsDir)) {
            mkdir($backupsDir, 0755, true);
        }

        // Create mock backup files matching kvs_backup_*.{tar.gz,sql.gz} pattern
        file_put_contents($backupsDir . '/kvs_backup_full_2024-01-01.tar.gz', 'test');
        file_put_contents($backupsDir . '/kvs_backup_db_2024-01-02.sql.gz', 'test');

        $this->tester->execute(['--list' => true]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('kvs_backup_full_2024-01-01.tar.gz', $output);
        $this->assertStringContainsString('kvs_backup_db_2024-01-02.sql.gz', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());

        // Cleanup
        unlink($backupsDir . '/kvs_backup_full_2024-01-01.tar.gz');
        unlink($backupsDir . '/kvs_backup_db_2024-01-02.sql.gz');
    }

    public function testBackupListUsesOutputDirectory(): void
    {
        $customBackupsDir = $this->rootDir . '/custom-backups';
        mkdir($customBackupsDir, 0755, true);
        file_put_contents($customBackupsDir . '/kvs_backup_db_2026-05-27.sql.gz', 'test');

        $this->tester->execute([
            '--list' => true,
            '--output' => $customBackupsDir,
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('kvs_backup_db_2026-05-27.sql.gz', $output);
        $this->assertStringNotContainsString('No backups directory found', $output);
    }

    public function testBackupListRejectsInvalidExplicitOutputDirectory(): void
    {
        $filePath = $this->rootDir . '/backup-output-file';
        file_put_contents($filePath, 'not a directory');

        $cases = [
            'empty output' => ['', 'The --output option cannot be empty'],
            'file output' => [$filePath, 'Output path is not a directory'],
            'missing output' => [$this->rootDir . '/missing-backups', 'Backup directory does not exist'],
        ];

        foreach ($cases as $case => [$outputDir, $expectedMessage]) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--list' => true,
                '--output' => $outputDir,
                '--format' => 'count',
            ]);

            $display = $tester->getDisplay();
            $this->assertSame(1, $tester->getStatusCode(), $case . ': ' . $display);
            $this->assertStringContainsString($expectedMessage, $display);
            $this->assertNotSame("0\n", $display);
        }
    }

    public function testBackupListIncludesNativeKvsBackupDirectory(): void
    {
        $nativeBackupDir = $this->tempDir . '/admin/data/backup';
        mkdir($nativeBackupDir, 0755, true);
        file_put_contents(
            $nativeBackupDir . '/example-site-backup-auto-dwpsc-2026-06-29-123456.tar.gz',
            'test'
        );

        $this->tester->execute([
            '--list' => true,
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $output);

        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $files = array_column($decoded, 'file');
        $this->assertContains('example-site-backup-auto-dwpsc-2026-06-29-123456.tar.gz', $files);
    }

    public function testBackupListSupportsJsonFormat(): void
    {
        $customBackupsDir = $this->rootDir . '/custom-backups-json';
        mkdir($customBackupsDir, 0755, true);
        file_put_contents($customBackupsDir . '/kvs_backup_db_2026-05-27.sql.gz', 'test');

        $this->tester->execute([
            '--list' => true,
            '--output' => $customBackupsDir,
            '--format' => 'json',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $output);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('kvs_backup_db_2026-05-27.sql.gz', $decoded[0]['file']);
        $this->assertSame('4.00 B', $decoded[0]['size']);
        $this->assertArrayHasKey('created', $decoded[0]);
    }

    public function testBackupListRejectsExplicitEmptyFormat(): void
    {
        $this->tester->execute([
            '--list' => true,
            '--format' => '',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Invalid value for --format ""', $output);
    }

    public function testBackupListSupportsCountFormat(): void
    {
        $customBackupsDir = $this->rootDir . '/custom-backups-count';
        mkdir($customBackupsDir, 0755, true);
        file_put_contents($customBackupsDir . '/kvs_backup_db_2026-05-27.sql.gz', 'test');
        file_put_contents($customBackupsDir . '/kvs_backup_full_2026-05-27.tar.gz', 'test');

        $this->tester->execute([
            '--list' => true,
            '--output' => $customBackupsDir,
            '--format' => 'count',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertSame("2\n", $output);
    }

    public function testBackupListRejectsCreateOnlyType(): void
    {
        $customBackupsDir = $this->rootDir . '/custom-backups-type';
        mkdir($customBackupsDir, 0755, true);
        file_put_contents($customBackupsDir . '/kvs_backup_db_2026-05-27.sql.gz', 'test');

        $this->tester->execute([
            '--list' => true,
            '--output' => $customBackupsDir,
            '--type' => 'db',
            '--format' => 'count',
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('list action does not support --type', $output);
        $this->assertNotSame("1\n", $output);
    }

    public function testBackupListRejectsUnknownFormat(): void
    {
        $this->tester->execute([
            '--list' => true,
            '--format' => 'xml',
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Invalid value for --format "xml"', $output);
    }

    public function testBackupRestoreIsNotAdvertisedUntilImplemented(): void
    {
        $this->assertFalse($this->command->getDefinition()->hasOption('restore'));
        $this->assertStringNotContainsString('restore', strtolower($this->command->getHelp()));
    }

    public function testBackupNoAction(): void
    {
        // Running without any option shows help
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('--create', $output);
        $this->assertStringContainsString('--list', $output);
        $this->assertStringNotContainsString('--restore', $output);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    #[DataProvider('backupCollisionCases')]
    public function testBackupPreservesExistingArchivesDuringFilenameCollision(string $type, bool $dumpFails): void
    {
        $toolsDir = $this->rootDir . '/collision-tools';
        mkdir($toolsDir, 0755);
        $this->writeTool($toolsDir, 'mysqldump', $dumpFails
            ? "#!/bin/sh\necho 'dump failed' >&2\nexit 1\n"
            : "#!/bin/sh\nprintf '%128s\\n' 'synthetic SQL dump'\n");

        $outputDir = $this->rootDir . '/collision-backups';
        mkdir($outputDir, 0755);
        $suffix = match ($type) {
            'db' => '_db.sql.gz',
            'files' => '_files.tar.gz',
            default => '_full.tar.gz',
        };
        $existing = [];
        $now = time();
        for ($second = -2; $second < 60; $second++) {
            $name = 'kvs_backup_' . $type . '_' . date('Y-m-d_H-i-s', $now + $second) . $suffix;
            $path = $outputDir . '/' . $name;
            file_put_contents($path, 'Previously created archive: ' . $name);
            chmod($path, 0600);
            $existing[$name] = [file_get_contents($path), 0600];
        }

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        try {
            $this->tester->execute(['--create' => true, '--type' => $type, '--output' => $outputDir]);
        } finally {
            $previousPath === false ? putenv('PATH') : putenv('PATH=' . $previousPath);
        }

        clearstatcache();
        $observed = [];
        foreach ($existing as $name => $expected) {
            $path = $outputDir . '/' . $name;
            $observed[$name] = is_file($path) ? [file_get_contents($path), fileperms($path) & 0777] : null;
        }
        $this->assertSame($existing, $observed, 'Existing archive bytes and permissions must be preserved.');
        $this->assertSame($dumpFails ? 1 : 0, $this->tester->getStatusCode(), $this->tester->getDisplay());

        $newFiles = array_values(array_diff(scandir($outputDir) ?: [], ['.', '..'], array_keys($existing)));
        $this->assertCount($dumpFails ? 0 : 1, $newFiles);
        if ($dumpFails) {
            $this->assertStringNotContainsString('Backup created:', $this->tester->getDisplay());
            return;
        }

        $newArchive = $outputDir . '/' . $newFiles[0];
        $this->assertSame($newArchive, $this->extractSuccessBackupPath($this->tester->getDisplay()));
        $this->assertSame(0640, fileperms($newArchive) & 0777);
        if ($type === 'db') {
            $this->assertStringContainsString('synthetic SQL dump', gzdecode(file_get_contents($newArchive)));
        } else {
            $process = new Process(['tar', 'tzf', $newArchive]);
            $this->assertSame(0, $process->run(), $process->getErrorOutput());
            $this->assertStringContainsString(
                $type === 'full' ? '_db.sql.gz' : 'admin/include/setup.php',
                $process->getOutput()
            );
            if ($type === 'full') {
                $this->assertStringContainsString('_files.tar.gz', $process->getOutput());
            }
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function backupCollisionCases(): iterable
    {
        yield 'database success' => ['db', false];
        yield 'database failure' => ['db', true];
        yield 'files success' => ['files', false];
        yield 'full success' => ['full', false];
        yield 'full database failure' => ['full', true];
    }

    public function testBackupListFailsWhenDirectoryCannotBeRead(): void
    {
        $root = vfsStream::setup('backup-list');
        $directory = vfsStream::newDirectory('archives', 0000)->at($root);
        vfsStream::newFile('existing.sql.gz')->withContent('preserved archive')->at($directory);

        @$this->tester->execute(['--list' => true, '--format' => 'json', '--output' => $directory->url()]);

        $this->assertSame(1, $this->tester->getStatusCode(), $this->tester->getDisplay());
        $this->assertStringContainsString('Cannot read backup directory', $this->tester->getDisplay());
        $this->assertNotSame("[]\n", $this->tester->getDisplay());

        $directory->chmod(0755);
        $this->tester->execute(['--list' => true, '--format' => 'json', '--output' => $directory->url()]);
        $this->assertSame(0, $this->tester->getStatusCode());
        $decoded = json_decode($this->tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('existing.sql.gz', $decoded[0]['file']);
    }

    private function writeTool(string $toolsDir, string $name, string $script): void
    {
        $path = $toolsDir . '/' . $name;
        file_put_contents($path, $script);
        chmod($path, 0755);
    }

    private function countGzipUncompressedBytes(string $path): int
    {
        $handle = gzopen($path, 'rb');
        $this->assertIsResource($handle);

        $bytes = 0;
        try {
            while (!gzeof($handle)) {
                $chunk = gzread($handle, 1024 * 1024);
                $this->assertNotFalse($chunk);
                $bytes += strlen($chunk);
            }
        } finally {
            gzclose($handle);
        }

        return $bytes;
    }

    private function extractSuccessBackupPath(string $output): string
    {
        $captureNextPathLine = false;
        $pathParts = [];

        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, '[OK] Backup created:')) {
                $path = trim(substr($line, strpos($line, 'Backup created:') + strlen('Backup created:')));
                if ($path !== '') {
                    return $path;
                }
                $captureNextPathLine = true;
                continue;
            }

            if ($captureNextPathLine) {
                $pathPart = trim($line);
                if ($pathPart === '') {
                    if ($pathParts !== []) {
                        return implode('', $pathParts);
                    }
                    continue;
                }
                $pathParts[] = $pathPart;
            }
        }

        if ($pathParts !== []) {
            return implode('', $pathParts);
        }

        $this->fail('Success backup path was not found in output: ' . $output);
    }

    /** @return iterable<string, array{string, string}> */
    public static function directBackupPayloads(): iterable
    {
        foreach (FormatterTest::exportContractPayloads() as $name => [$payload, $format, $decorated]) {
            if ($format !== 'field' && $decorated) {
                yield $name => [str_replace('/', '_', $payload) . '.gz', $format];
            }
        }
    }

    #[DataProvider('directBackupPayloads')]
    public function testDirectExportPreservesBackupFilename(string $filename, string $format): void
    {
        $directory = $this->tempDir . '/backups';
        file_put_contents($directory . '/' . $filename, '');
        $row = TestHelper::readCommandExportRow(
            $this->tester,
            ['--list' => true, '--output' => $directory],
            $format
        );
        $this->assertSame($filename, $row['file']);
        $this->assertSame(['file', 'size', 'created'], array_keys($row));
    }
    #[DataProvider('linkedBackupRoots')]
    public function testLinkedBackupRootsRestoreTheirFiles(string $type, string $links): void
    {
        $content = $this->rootDir . '/actual-content';
        mkdir($content . '/videos_sources', 0755, true);
        file_put_contents($this->tempDir . '/site-marker.txt', "Synthetic site\n");
        file_put_contents($content . '/videos_sources/media-marker.txt', "Synthetic media\n");
        symlink('site-marker.txt', $this->tempDir . '/internal-link');
        symlink('.', $this->tempDir . '/internal-cycle');
        $siteRoot = $this->tempDir;
        $contentRoot = $content;
        if (in_array($links, ['site', 'both', 'relative', 'trailing', 'chain'], true)) {
            $siteRoot = $this->rootDir . '/linked-site';
            $target = $links === 'relative' ? 'kvs' : $this->tempDir;
            if ($links === 'chain') {
                symlink($this->tempDir, $this->rootDir . '/site-hop');
                $target = 'site-hop';
            }
            symlink($target, $siteRoot);
            if ($links === 'trailing') {
                $siteRoot .= '/';
            }
        }
        if (in_array($links, ['content', 'both', 'relative', 'trailing', 'chain'], true)) {
            $contentRoot = $this->rootDir . '/linked-content';
            $target = $links === 'relative' ? 'actual-content' : $content;
            if ($links === 'chain') {
                symlink($content, $this->rootDir . '/content-hop');
                $target = 'content-hop';
            }
            symlink($target, $contentRoot);
        }
        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config[\'content_path_videos_sources\'] = '
                . var_export($contentRoot . '/videos_sources', true) . ';'
        );
        $toolsDir = $this->rootDir . '/archive-tools';
        mkdir($toolsDir);
        $this->writeTool($toolsDir, 'mysqldump', "#!/bin/sh\nprintf '%128s\\n' 'Synthetic SQL dump'\n");
        $outputDir = $this->rootDir . '/archives';
        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        try {
            $config = new Configuration(['path' => $siteRoot, 'disable_db_env_overrides' => true]);
            $tester = new CommandTester(new BackupCommand($config));
            $tester->execute(['--create' => true, '--type' => $type, '--output' => $outputDir]);
            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        } finally {
            $previousPath === false ? putenv('PATH') : putenv('PATH=' . $previousPath);
        }
        $archives = glob($outputDir . '/*_' . $type . '.tar.gz');
        $this->assertNotFalse($archives);
        $this->assertCount(1, $archives);
        $archive = $archives[0];
        if ($type === 'full') {
            $outer = $this->rootDir . '/outer';
            mkdir($outer);
            (new Process(['tar', 'xzf', $archive, '-C', $outer]))->mustRun();
            $inner = glob($outer . '/*_files.tar.gz');
            $this->assertNotFalse($inner);
            $this->assertCount(1, $inner);
            $archive = $inner[0];
        }
        // Restoration must work after the original source directories are unavailable.
        rename($this->tempDir, $this->tempDir . '-offline');
        rename($content, $content . '-offline');
        $restored = $this->rootDir . '/restored';
        mkdir($restored);
        (new Process(['tar', 'xzf', $archive, '-C', $restored]))->mustRun();
        $restoredSite = $restored . '/' . basename($siteRoot);
        $restoredContent = $restored . '/' . basename($contentRoot);
        $this->assertFalse(is_link($restoredSite), 'The site archive root must restore as a directory.');
        $this->assertFalse(is_link($restoredContent), 'The content archive root must restore as a directory.');
        $this->assertSame("Synthetic site\n", file_get_contents($restoredSite . '/site-marker.txt'));
        $this->assertSame("Synthetic media\n", file_get_contents($restoredContent . '/videos_sources/media-marker.txt'));
        $this->assertTrue(is_link($restoredSite . '/internal-link'));
        $this->assertSame('site-marker.txt', readlink($restoredSite . '/internal-link'));
        $this->assertTrue(is_link($restoredSite . '/internal-cycle'));
        $this->assertSame('.', readlink($restoredSite . '/internal-cycle'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function linkedBackupRoots(): iterable
    {
        foreach (['files', 'full'] as $type) {
            foreach (['none', 'site', 'content', 'both', 'relative', 'trailing', 'chain'] as $links) {
                yield $type . ' ' . $links => [$type, $links];
            }
        }
    }
}
