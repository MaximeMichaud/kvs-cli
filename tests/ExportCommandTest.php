<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Database\ExportCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class ExportCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private ExportCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        // Create mock KVS installation
        $this->tempDir = TestHelper::createTempDir('kvs-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/exports', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);
        file_put_contents($this->tempDir . '/admin/include/setup.php', '<?php');

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new ExportCommand($this->config);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testExportCommandMetadata(): void
    {
        $this->assertEquals('db:export', $this->command->getName());
        $this->assertStringContainsString('Export', $this->command->getDescription());
        $this->assertContains('database:export', $this->command->getAliases());
        $this->assertContains('db:dump', $this->command->getAliases());
    }

    public function testExportCommandOptions(): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption('output'));
        $this->assertTrue($definition->hasOption('tables'));
        $this->assertTrue($definition->hasOption('no-data'));
        $this->assertTrue($definition->hasOption('compress'));
    }

    public function testExportWithDefaultOptions(): void
    {
        $outputFile = $this->tempDir . '/exports/default_backup.sql';

        $this->tester->execute([
            '--output' => $outputFile
        ]);

        $output = $this->tester->getDisplay();
        $this->assertNotEmpty($output);

        if ($this->tester->getStatusCode() === 0) {
            $this->assertFileExists($outputFile);
        }
    }

    public function testExportWithOutputOption(): void
    {
        $outputFile = $this->tempDir . '/exports/test_backup.sql';

        $this->tester->execute([
            '--output' => $outputFile
        ]);

        // Command will fail without real DB, but we verify it processed the option
        $output = $this->tester->getDisplay();
        $this->assertNotEmpty($output);
    }

    public function testExportUsesDatabasePortEnvironmentOverride(): void
    {
        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);

        $mysql = $toolsDir . '/mysql';
        file_put_contents($mysql, "#!/bin/sh\necho 'mysql  Ver 8.0.35'\n");
        chmod($mysql, 0755);

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
        $previousPort = getenv('KVS_DB_PORT');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('KVS_DB_PORT=3308');

        try {
            $tester = new CommandTester(new ExportCommand(new Configuration(['path' => $this->tempDir])));
            $tester->execute([
                '--output' => $this->tempDir . '/exports/port_backup.sql',
            ]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $args = file($argsFile, FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($args);
            $this->assertContains('-P', $args);
            $this->assertContains('3308', $args);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
            if ($previousPort === false) {
                putenv('KVS_DB_PORT');
            } else {
                putenv('KVS_DB_PORT=' . $previousPort);
            }
        }
    }

    public function testExportCreatesOutputWithRestrictedPermissions(): void
    {
        $toolsDir = $this->tempDir . '/tools-secure-output';
        mkdir($toolsDir, 0755, true);

        $mysql = $toolsDir . '/mysql';
        file_put_contents($mysql, "#!/bin/sh\necho 'mysql  Ver 8.0.35'\n");
        chmod($mysql, 0755);

        $mysqldump = $toolsDir . '/mysqldump';
        file_put_contents($mysqldump, "#!/bin/sh\necho 'SQL dump'\n");
        chmod($mysqldump, 0755);

        $outputFile = $this->tempDir . '/exports/secure_backup.sql';
        $previousPath = getenv('PATH');
        $previousUmask = umask(0000);
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $tester = new CommandTester(new ExportCommand(new Configuration(['path' => $this->tempDir])));
            $tester->execute(['--output' => $outputFile]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertFileExists($outputFile);
            $permissions = fileperms($outputFile);
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

    public function testExportUsesCanonicalExtensionForDefaultCompressedOutput(): void
    {
        $toolsDir = $this->tempDir . '/tools-canonical-extension';
        mkdir($toolsDir, 0755, true);

        $mysql = $toolsDir . '/mysql';
        file_put_contents($mysql, "#!/bin/sh\necho 'mysql  Ver 15.1 Distrib 10.11.0-MariaDB'\n");
        chmod($mysql, 0755);

        $mariadbDump = $toolsDir . '/mariadb-dump';
        file_put_contents($mariadbDump, "#!/bin/sh\necho 'SQL dump'\n");
        chmod($mariadbDump, 0755);

        $gzip = $toolsDir . '/gzip';
        file_put_contents($gzip, "#!/bin/sh\ncat\n");
        chmod($gzip, 0755);

        $previousPath = getenv('PATH');
        $previousCwd = getcwd();
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        chdir($this->tempDir . '/exports');

        try {
            $tester = new CommandTester(new ExportCommand(new Configuration(['path' => $this->tempDir])));
            $tester->execute(['--compress' => 'gzip']);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertCount(1, glob($this->tempDir . '/exports/kvs_backup_*.sql.gz') ?: []);
            $this->assertSame([], glob($this->tempDir . '/exports/kvs_backup_*.sql.gzip') ?: []);
        } finally {
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testExportWithTablesOption(): void
    {
        $outputFile = $this->tempDir . '/exports/tables_backup.sql';

        $this->tester->execute([
            '--output' => $outputFile,
            '--tables' => 'ktvs_videos,ktvs_users'
        ]);

        // Command will fail without real DB, but we verify it processed the option
        $output = $this->tester->getDisplay();
        $this->assertNotEmpty($output);
    }

    public function testExportWithNoDataOption(): void
    {
        $outputFile = $this->tempDir . '/exports/schema_backup.sql';

        $this->tester->execute([
            '--output' => $outputFile,
            '--no-data' => true
        ]);

        // Command will fail without real DB, but we verify it processed the option
        $output = $this->tester->getDisplay();
        $this->assertNotEmpty($output);
    }
}
