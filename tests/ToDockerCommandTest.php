<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Migrate\ToDockerCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class ToDockerCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private ToDockerCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-to-docker-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data', 0755, true);
        mkdir($this->tempDir . '/contents', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);

        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_version" => "6.4.0"];'
        );

        file_put_contents(
            $this->tempDir . '/admin/include/version.php',
            "<?php \$config['project_version'] = '6.4.0';"
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new ToDockerCommand($this->config);

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

    public function testToDockerCommandShowsTitle(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--target' => $this->tempDir . '/new-target',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('KVS Migration to Docker', $output);
    }

    public function testToDockerCommandDryRunShowsSteps(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--target' => $this->tempDir . '/new-target',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Dry run mode', $output);
        $this->assertStringContainsString('Clone KVS-Install', $output);
        $this->assertStringContainsString('Export source database', $output);
        $this->assertStringContainsString('KVS-Install setup (headless)', $output);
        $this->assertStringContainsString('STOP_EXISTING=n', $output);
        $this->assertStringContainsString('Import database', $output);
    }

    public function testToDockerHelpDryRunExamplesIncludeRequiredEmail(): void
    {
        $help = $this->command->getHelp();

        $this->assertStringContainsString(
            'kvs migrate:to-docker --domain=example.com --email=admin@example.com --ssl=1',
            $help
        );
        $this->assertStringContainsString(
            'kvs migrate:to-docker /var/www/site -d example.com -e admin@example.com --dry-run',
            $help
        );
        $this->assertStringNotContainsString('kvs migrate:to-docker --domain=example.com --ssl=1', $help);
        $this->assertStringNotContainsString('kvs migrate:to-docker /var/www/site --dry-run', $help);
    }

    public function testToDockerDryRunUsesProvidedEmail(): void
    {
        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'myemail@mycompany.com',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString("EMAIL='myemail@mycompany.com'", $output);
        $this->assertStringNotContainsString("EMAIL='admin@test.example.com'", $output);
    }

    public function testToDockerDryRunShellQuotesTargetPaths(): void
    {
        $targetDir = $this->tempDir . '/target with spaces';

        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'test@test.com',
            '--target' => $targetDir,
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('git clone ', $output);
        $this->assertStringContainsString(escapeshellarg($targetDir), $output);
        $this->assertStringContainsString('cd ' . escapeshellarg($targetDir . '/docker') . ' &&', $output);
        $this->assertStringNotContainsString('git clone https://github.com/MaximeMichaud/KVS-install.git ' . $targetDir, $output);
        $this->assertStringNotContainsString('cd ' . $targetDir . '/docker &&', $output);
    }

    public function testToDockerDryRunShowsGitPullForExistingTargetRepository(): void
    {
        $targetDir = $this->tempDir . '/existing-target';
        mkdir($targetDir . '/.git', 0755, true);

        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'test@test.com',
            '--target' => $targetDir,
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('Update existing KVS-Install', $output);
        $this->assertStringContainsString('cd ' . escapeshellarg($targetDir) . ' && git pull', $output);
        $this->assertStringNotContainsString('git clone ', $output);
    }

    public function testToDockerRejectsInvalidProvidedDomainBeforeDryRun(): void
    {
        foreach (['', 'bad_domain'] as $domain) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--domain' => $domain,
                '--email' => 'test@test.com',
                '--ssl' => '1',
                '--dry-run' => true,
                '--force' => true,
            ]);

            $output = $tester->getDisplay();
            $this->assertSame(1, $tester->getStatusCode(), $output);
            $this->assertStringContainsString('Invalid --domain value', $output);
            $this->assertStringNotContainsString('Migration Plan', $output);
        }
    }

    public function testToDockerRejectsInvalidProvidedEmailBeforeDryRun(): void
    {
        foreach (['', 'not-an-email'] as $email) {
            $tester = new CommandTester($this->command);
            $tester->execute([
                '--domain' => 'test.example.com',
                '--email' => $email,
                '--ssl' => '1',
                '--dry-run' => true,
                '--force' => true,
            ]);

            $output = $tester->getDisplay();
            $this->assertSame(1, $tester->getStatusCode(), $output);
            $this->assertStringContainsString('Invalid --email value', $output);
            $this->assertStringNotContainsString('Migration Plan', $output);
        }
    }

    public function testToDockerRejectsEmptyTargetBeforeDryRun(): void
    {
        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'test@test.com',
            '--target' => '',
            '--ssl' => '1',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();
        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('The --target option cannot be empty', $output);
        $this->assertStringNotContainsString('Migration Plan', $output);
    }

    public function testToDockerDryRunUsesKvsInstallDefaultSitePrefix(): void
    {
        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'test@test.com',
            '--target' => $this->tempDir . '/default-prefix-target',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString("docker exec -i 'kvs-test-example-mariadb'", $output);
        $this->assertStringNotContainsString('kvs-test-example-com-mariadb', $output);
    }

    public function testToDockerDryRunUsesDomainDatabaseName(): void
    {
        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'test@test.com',
            '--target' => $this->tempDir . '/domain-database-target',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString("mariadb -u root 'test.example.com' < /tmp/kvs-migration.sql", $output);
        $this->assertStringNotContainsString("mariadb -u root 'test_example_com' < /tmp/kvs-migration.sql", $output);
        $this->assertStringNotContainsString('mariadb -u root kvs < /tmp/kvs-migration.sql', $output);
    }

    public function testToDockerDryRunUsesExistingKvsInstallEnvForImportCommand(): void
    {
        $targetDir = $this->tempDir . '/existing-target-env';
        mkdir($targetDir . '/.git', 0755, true);
        mkdir($targetDir . '/docker', 0755, true);
        file_put_contents(
            $targetDir . '/docker/.env',
            "SITE_PREFIX=custom-prefix\nMARIADB_DATABASE=custom_db\nDOMAIN=env.example\n"
        );

        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'test@test.com',
            '--target' => $targetDir,
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString(
            "docker exec -i 'custom-prefix-mariadb' mariadb -u root 'custom_db' < /tmp/kvs-migration.sql",
            $output
        );
        $this->assertStringNotContainsString('kvs-test-example-mariadb', $output);
        $this->assertStringNotContainsString("mariadb -u root 'test.example.com' < /tmp/kvs-migration.sql", $output);
    }

    public function testToDockerDryRunShowsSourceDatabaseConnectionOptions(): void
    {
        $this->tester->execute([
            '--domain' => 'test.example.com',
            '--email' => 'test@test.com',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();
        $dbConfig = $this->config->getDatabaseConfig();
        $host = $dbConfig['host'];
        $port = 3306;
        if (str_contains($host, ':')) {
            [$host, $portString] = explode(':', $host, 2);
            $port = (int) $portString;
        }

        $this->assertSame(0, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString("MYSQL_PWD='<DB_PASS>' mariadb-dump", $output);
        $this->assertStringContainsString('--host=' . escapeshellarg($host), $output);
        $this->assertStringContainsString('--port=' . $port, $output);
        $this->assertStringContainsString('--user=' . escapeshellarg($dbConfig['user']), $output);
        $this->assertStringContainsString(escapeshellarg($dbConfig['database']) . ' > /tmp/kvs-migration.sql', $output);
        $this->assertStringNotContainsString($dbConfig['password'], $output);
    }

    public function testToDockerDatabaseExportUsesMysqlPwdEnvironment(): void
    {
        $toolsDir = $this->tempDir . '/tools-secure-dump';
        $targetDir = $this->tempDir . '/target-secure-dump';
        mkdir($toolsDir, 0755, true);
        mkdir($targetDir . '/.git', 0755, true);
        mkdir($targetDir . '/docker', 0755, true);
        file_put_contents($targetDir . '/docker/setup.sh', "#!/bin/sh\nexit 0\n");
        chmod($targetDir . '/docker/setup.sh', 0755);

        $argsFile = $this->tempDir . '/to-docker-dump.args';
        $envFile = $this->tempDir . '/to-docker-dump.env';

        file_put_contents($toolsDir . '/git', "#!/bin/sh\nexit 0\n");
        file_put_contents(
            $toolsDir . '/docker',
            <<<'SH'
#!/bin/sh
if [ "$1" = "exec" ]; then
  cat >/dev/null
  exit 0
fi
exit 0
SH
        );
        file_put_contents(
            $toolsDir . '/mariadb-dump',
            "#!/bin/sh\n"
            . 'printf "%s\n" "$@" > ' . escapeshellarg($argsFile) . "\n"
            . 'printf "%s\n" "${MYSQL_PWD-}" > ' . escapeshellarg($envFile) . "\n"
            . "printf '%128s\\n' 'SQL dump'\n"
        );
        chmod($toolsDir . '/git', 0755);
        chmod($toolsDir . '/docker', 0755);
        chmod($toolsDir . '/mariadb-dump', 0755);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute([
                '--domain' => 'test.example.com',
                '--email' => 'test@test.com',
                '--ssl' => '3',
                '--target' => $targetDir,
                '--no-content' => true,
                '--yes' => true,
                '--force' => true,
            ]);

            $display = $this->tester->getDisplay();
            $dbConfig = $this->config->getDatabaseConfig();
            $password = $dbConfig['password'] ?? '';

            $this->assertSame(0, $this->tester->getStatusCode(), $display);
            $this->assertStringContainsString('Migration completed', $display);
            $this->assertStringNotContainsString('--password', (string) file_get_contents($argsFile));
            $this->assertStringNotContainsString($password, (string) file_get_contents($argsFile));
            $this->assertSame($password, trim((string) file_get_contents($envFile)));
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testToDockerRejectsTinyDatabaseDumpBeforeImport(): void
    {
        $toolsDir = $this->tempDir . '/tools-tiny-dump';
        $targetDir = $this->tempDir . '/target-tiny-dump';
        mkdir($toolsDir, 0755, true);
        mkdir($targetDir . '/.git', 0755, true);
        mkdir($targetDir . '/docker', 0755, true);
        file_put_contents($targetDir . '/docker/setup.sh', "#!/bin/sh\nexit 0\n");
        chmod($targetDir . '/docker/setup.sh', 0755);

        $dockerMarker = $this->tempDir . '/docker-import-called';

        file_put_contents($toolsDir . '/git', "#!/bin/sh\nexit 0\n");
        file_put_contents(
            $toolsDir . '/docker',
            "#!/bin/sh\n"
            . 'printf called >> ' . escapeshellarg($dockerMarker) . "\n"
            . "exit 0\n"
        );
        file_put_contents($toolsDir . '/mariadb-dump', "#!/bin/sh\nprintf tiny\n");
        chmod($toolsDir . '/git', 0755);
        chmod($toolsDir . '/docker', 0755);
        chmod($toolsDir . '/mariadb-dump', 0755);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute([
                '--domain' => 'test.example.com',
                '--email' => 'test@test.com',
                '--ssl' => '3',
                '--target' => $targetDir,
                '--no-content' => true,
                '--yes' => true,
                '--force' => true,
            ]);

            $display = $this->tester->getDisplay();

            $this->assertSame(1, $this->tester->getStatusCode(), $display);
            $this->assertStringContainsString('Database export failed: dump is empty or incomplete', $display);
            $this->assertStringNotContainsString('Database imported', $display);
            $this->assertStringNotContainsString('Migration completed', $display);
            $this->assertFileDoesNotExist($dockerMarker);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testToDockerDryRunShowsVersionWhenSourceConfigIsLoadedTwice(): void
    {
        $sourceDir = TestHelper::createTempDir('kvs-to-docker-source-version-');
        mkdir($sourceDir . '/admin/include', 0755, true);
        mkdir($sourceDir . '/contents', 0755, true);
        TestHelper::createMockDbConfig($sourceDir);
        file_put_contents(
            $sourceDir . '/admin/include/version.php',
            '<?php $config["project_version"] = "7.0.0";'
        );
        file_put_contents(
            $sourceDir . '/admin/include/setup.php',
            <<<'PHP'
<?php
include_once 'version.php';
if (!isset($config)) {
    $config = [];
}
$config['project_path'] = __DIR__ . '/../..';
PHP
        );

        try {
            $command = new ToDockerCommand(new Configuration(['path' => $sourceDir]));
            $application = new Application();
            $application->add($command);
            $tester = new CommandTester($command);

            $tester->execute([
                'source' => $sourceDir,
                '--domain' => 'test.example.com',
                '--email' => 'test@test.com',
                '--dry-run' => true,
                '--force' => true,
                '--no-content' => true,
            ]);

            $output = $tester->getDisplay();
            $this->assertSame(0, $tester->getStatusCode(), $output);
            $this->assertStringContainsString('KVS Version', $output);
            $this->assertStringContainsString('7.0.0', $output);
            $this->assertStringNotContainsString('KVS Version       Unknown', $output);
        } finally {
            TestHelper::removeDir($sourceDir);
        }
    }

    public function testToDockerDryRunNoContentDoesNotShowContentCopy(): void
    {
        mkdir($this->tempDir . '/contents/videos_sources/1000', 0755, true);
        file_put_contents($this->tempDir . '/contents/videos_sources/1000/source.mp4', 'video');

        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--dry-run' => true,
            '--no-content' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Include Content', $output);
        $this->assertStringNotContainsString('Copy content', $output);
        $this->assertStringNotContainsString('rsync -av', $output);
    }

    public function testToDockerNoInteractionFailsWithoutConfirmation(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--target' => $this->tempDir . '/target',
            '--no-content' => true,
            '--force' => true,
        ], ['interactive' => false]);

        $output = $this->tester->getDisplay();

        $this->assertSame(1, $this->tester->getStatusCode(), $output);
        $this->assertStringContainsString('confirmation was not provided', $output);
        $this->assertDirectoryDoesNotExist($this->tempDir . '/target');
    }

    public function testToDockerCommandShowsMigrationPlan(): void
    {
        $this->tester->execute([
            '--domain' => 'example.com',
            '--email' => 'admin@example.com',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Migration Plan', $output);
        $this->assertStringContainsString('Source Path', $output);
        $this->assertStringContainsString('Target Domain', $output);
        $this->assertStringContainsString('example.com', $output);
    }

    public function testToDockerCommandWithInvalidSource(): void
    {
        $this->tester->execute([
            'source' => '/nonexistent/path',
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testToDockerCommandShowsMariaDbChoice(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--db' => '3',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('MariaDB 10.11 LTS', $output);
    }

    public function testToDockerCommandShowsDbChoice2(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--db' => '2',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('MariaDB 11.4 LTS', $output);
    }

    public function testToDockerCommandShowsSslProvider(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--ssl' => '1',
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString("Let's Encrypt", $output);
    }

    public function testToDockerCommandNoContentOption(): void
    {
        $this->tester->execute([
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--no-content' => true,
            '--dry-run' => true,
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Include Content', $output);
        $this->assertStringContainsString('No', $output);
    }

    public function testToDockerSetupDoesNotAutoStopExistingContainers(): void
    {
        $rootDir = TestHelper::createTempDir('kvs-to-docker-setup-env-');
        $targetDir = $rootDir . '/target';
        $dockerDir = $targetDir . '/docker';
        mkdir($dockerDir, 0755, true);

        file_put_contents(
            $dockerDir . '/setup.sh',
            <<<'SH'
#!/bin/sh
printf '%s\n' "$STOP_EXISTING" > captured-stop-existing
exit 0
SH
        );
        chmod($dockerDir . '/setup.sh', 0755);

        try {
            $output = new BufferedOutput();
            $ioProperty = new \ReflectionProperty(\KVS\CLI\Command\BaseCommand::class, 'io');
            $ioProperty->setValue($this->command, new SymfonyStyle(new ArrayInput([]), $output));

            $method = new \ReflectionMethod($this->command, 'runKvsInstallSetup');
            $result = $method->invoke($this->command, $targetDir, 'example.com', 'admin@example.com', '1', '1');

            $this->assertTrue($result, $output->fetch());
            $this->assertSame('n', trim((string) file_get_contents($dockerDir . '/captured-stop-existing')));
        } finally {
            TestHelper::removeDir($rootDir);
        }
    }

    public function testImportDataFailsWhenChownFails(): void
    {
        $rootDir = TestHelper::createTempDir('kvs-to-docker-chown-');
        $targetDir = $rootDir . '/target';
        $toolsDir = $rootDir . '/tools';
        mkdir($targetDir . '/docker', 0755, true);
        mkdir($toolsDir, 0755, true);

        file_put_contents($targetDir . '/docker/.env', "MARIADB_DATABASE=test_db\n");
        $dumpFile = $rootDir . '/dump.sql';
        file_put_contents($dumpFile, 'CREATE TABLE test(id int);');
        file_put_contents($this->tempDir . '/contents/video.mp4', 'video');

        file_put_contents($toolsDir . '/docker', "#!/bin/sh\nexit 0\n");
        chmod($toolsDir . '/docker', 0755);
        file_put_contents($toolsDir . '/cp', "#!/bin/sh\nexit 0\n");
        chmod($toolsDir . '/cp', 0755);
        file_put_contents($toolsDir . '/chown', "#!/bin/sh\necho 'permission denied' >&2\nexit 1\n");
        chmod($toolsDir . '/chown', 0755);

        $previousPath = getenv('PATH');
        $previousServerPath = $_SERVER['PATH'] ?? null;
        $previousEnvPath = $_ENV['PATH'] ?? null;
        putenv('PATH=' . $toolsDir);
        $_SERVER['PATH'] = $toolsDir;
        $_ENV['PATH'] = $toolsDir;

        try {
            $output = new BufferedOutput();
            $ioProperty = new \ReflectionProperty(\KVS\CLI\Command\BaseCommand::class, 'io');
            $ioProperty->setValue($this->command, new SymfonyStyle(new ArrayInput([]), $output));

            $method = new \ReflectionMethod($this->command, 'importData');
            $domain = '../../' . ltrim($rootDir, '/') . '/site';
            $result = $method->invoke($this->command, $targetDir, $domain, $dumpFile, $this->config, false);

            $this->assertFalse($result);
            $this->assertStringContainsString('Failed to set content permissions', $output->fetch());
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
            if ($previousServerPath === null) {
                unset($_SERVER['PATH']);
            } else {
                $_SERVER['PATH'] = $previousServerPath;
            }
            if ($previousEnvPath === null) {
                unset($_ENV['PATH']);
            } else {
                $_ENV['PATH'] = $previousEnvPath;
            }
            TestHelper::removeDir($rootDir);
        }
    }
}
