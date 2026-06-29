<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\System\BackupCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class BackupCommandTest extends TestCase
{
    private string $rootDir;
    private string $tempDir;
    private Configuration $config;
    private BackupCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
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
            . "echo 'SQL dump'\n"
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
echo 'SQL dump'
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

    public function testDatabaseBackupSuccessMessageShowsActualFilePath(): void
    {
        $toolsDir = $this->tempDir . '/tools-message-db';
        mkdir($toolsDir, 0755, true);
        $this->writeTool($toolsDir, 'mysqldump', <<<'SH'
#!/bin/sh
echo 'SQL dump'
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
echo 'SQL dump'
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
echo 'SQL dump'
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

    private function writeTool(string $toolsDir, string $name, string $script): void
    {
        $path = $toolsDir . '/' . $name;
        file_put_contents($path, $script);
        chmod($path, 0755);
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
}
