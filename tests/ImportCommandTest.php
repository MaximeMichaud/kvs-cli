<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Migrate\ImportCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class ImportCommandTest extends TestCase
{
    private ImportCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->command = new ImportCommand();

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    public function testImportCommandValidatesPackageExists(): void
    {
        $this->tester->execute([
            'package' => '/nonexistent/package.tar.zst',
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Package not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testImportCommandWithMissingPackage(): void
    {
        $this->tester->execute([
            'package' => '/nonexistent/package.tar.zst',
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Package not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testImportCommandWithInvalidExtension(): void
    {
        $tempFile = TestHelper::getProjectTempDir() . '/test-package-' . bin2hex(random_bytes(8)) . '.zip';
        touch($tempFile);

        try {
            $this->tester->execute([
                'package' => $tempFile,
                '--domain' => 'test.local',
                '--email' => 'test@test.com',
                '--force' => true,
            ]);

            $output = $this->tester->getDisplay();

            $this->assertStringContainsString('must be a .tar.zst file', $output);
            $this->assertEquals(1, $this->tester->getStatusCode());
        } finally {
            unlink($tempFile);
        }
    }

    public function testImportCommandRejectsInvalidProvidedDomainBeforeReadingPackage(): void
    {
        $tempFile = TestHelper::getProjectTempDir() . '/test-package-' . bin2hex(random_bytes(8)) . '.tar.zst';
        touch($tempFile);

        try {
            foreach (['', 'bad_domain'] as $domain) {
                $tester = new CommandTester($this->command);
                $tester->execute([
                    'package' => $tempFile,
                    '--domain' => $domain,
                    '--email' => 'test@test.com',
                    '--ssl' => '1',
                    '--force' => true,
                ]);

                $output = $tester->getDisplay();

                $this->assertSame(1, $tester->getStatusCode(), $output);
                $this->assertStringContainsString('Invalid --domain value', $output);
                $this->assertStringNotContainsString('Reading package', $output);
            }
        } finally {
            unlink($tempFile);
        }
    }

    public function testImportCommandRejectsInvalidProvidedEmailBeforeReadingPackage(): void
    {
        $tempFile = TestHelper::getProjectTempDir() . '/test-package-' . bin2hex(random_bytes(8)) . '.tar.zst';
        touch($tempFile);

        try {
            foreach (['', 'not-an-email'] as $email) {
                $tester = new CommandTester($this->command);
                $tester->execute([
                    'package' => $tempFile,
                    '--domain' => 'test.local',
                    '--email' => $email,
                    '--ssl' => '1',
                    '--force' => true,
                ]);

                $output = $tester->getDisplay();

                $this->assertSame(1, $tester->getStatusCode(), $output);
                $this->assertStringContainsString('Invalid --email value', $output);
                $this->assertStringNotContainsString('Reading package', $output);
            }
        } finally {
            unlink($tempFile);
        }
    }

    public function testImportCommandRejectsEmptyTargetBeforeReadingPackage(): void
    {
        $tempFile = TestHelper::getProjectTempDir() . '/test-package-' . bin2hex(random_bytes(8)) . '.tar.zst';
        touch($tempFile);

        try {
            $this->tester->execute([
                'package' => $tempFile,
                '--domain' => 'test.local',
                '--email' => 'test@test.com',
                '--target' => '',
                '--ssl' => '1',
                '--force' => true,
            ]);

            $output = $this->tester->getDisplay();

            $this->assertSame(1, $this->tester->getStatusCode(), $output);
            $this->assertStringContainsString('The --target option cannot be empty', $output);
            $this->assertStringNotContainsString('Reading package', $output);
        } finally {
            unlink($tempFile);
        }
    }

    public function testImportCommandRejectsNonexistentWithSslOption(): void
    {
        $this->tester->execute([
            'package' => '/nonexistent/package.tar.zst',
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--ssl' => '1',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Package not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testImportCommandRejectsNonexistentWithDbOption(): void
    {
        $this->tester->execute([
            'package' => '/nonexistent/package.tar.zst',
            '--domain' => 'test.local',
            '--email' => 'test@test.com',
            '--db' => '3',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Package not found', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testImportCommandHelp(): void
    {
        $help = $this->command->getHelp();

        $this->assertStringContainsString('migrate:package', $help);
        $this->assertStringContainsString('migrate:import', $help);
        $this->assertStringContainsString('SSL options', $help);
    }

    public function testImportNoInteractionFailsWithoutConfirmation(): void
    {
        $rootDir = TestHelper::createTempDir('kvs-import-confirm-');
        $toolsDir = $rootDir . '/tools';
        $packageFile = $rootDir . '/package.tar.zst';
        $targetDir = $rootDir . '/target';
        mkdir($toolsDir, 0755, true);
        touch($packageFile);

        file_put_contents($toolsDir . '/docker', "#!/bin/sh\nexit 0\n");
        chmod($toolsDir . '/docker', 0755);
        file_put_contents($toolsDir . '/git', "#!/bin/sh\nexit 0\n");
        chmod($toolsDir . '/git', 0755);
        file_put_contents(
            $toolsDir . '/zstd',
            <<<'SH'
#!/bin/sh
out=''
while [ "$#" -gt 0 ]; do
  if [ "$1" = "-o" ]; then
    shift
    out="$1"
  fi
  shift
done
: > "$out"
SH
        );
        chmod($toolsDir . '/zstd', 0755);
        file_put_contents(
            $toolsDir . '/tar',
            <<<'SH'
#!/bin/sh
dest=''
while [ "$#" -gt 0 ]; do
  if [ "$1" = "-C" ]; then
    shift
    dest="$1"
  fi
  shift
done
mkdir -p "$dest"
cat > "$dest/metadata.json" <<'JSON'
{
  "created_at": "2026-06-29T00:00:00Z",
  "kvs_version": "test",
  "source_path": "/var/www/maximemichaud.ca",
  "database": {"size": 41},
  "content": {"included": false, "size": 0, "files": 0}
}
JSON
printf 'compressed' > "$dest/database.sql.zst"
SH
        );
        chmod($toolsDir . '/tar', 0755);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $this->tester->execute(
                [
                    'package' => $packageFile,
                    '--domain' => 'example.test',
                    '--email' => 'admin@example.test',
                    '--target' => $targetDir,
                    '--ssl' => '1',
                    '--force' => true,
                ],
                ['interactive' => false]
            );

            $output = $this->tester->getDisplay();

            $this->assertSame(1, $this->tester->getStatusCode(), $output);
            $this->assertStringContainsString('Import cancelled because confirmation was not provided', $output);
            $this->assertDirectoryDoesNotExist($targetDir);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
            TestHelper::removeDir($rootDir);
        }
    }

    public function testImportSetupDoesNotAutoStopExistingContainers(): void
    {
        $rootDir = TestHelper::createTempDir('kvs-import-setup-env-');
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
            $ioProperty = new \ReflectionProperty($this->command, 'io');
            $ioProperty->setValue($this->command, new SymfonyStyle(new ArrayInput([]), $output));

            $method = new \ReflectionMethod($this->command, 'runKvsInstallSetup');
            $result = $method->invoke($this->command, $targetDir, 'example.com', 'admin@example.com', '1', '1');

            $this->assertTrue($result, $output->fetch());
            $this->assertSame('n', trim((string) file_get_contents($dockerDir . '/captured-stop-existing')));
        } finally {
            TestHelper::removeDir($rootDir);
        }
    }

    public function testDatabaseImportStreamsDecompressedSqlToDocker(): void
    {
        $rootDir = TestHelper::createTempDir('kvs-import-stream-');
        $extractDir = $rootDir . '/extract';
        $targetDir = $rootDir . '/target';
        $toolsDir = $rootDir . '/tools';
        mkdir($extractDir, 0755, true);
        mkdir($targetDir . '/docker', 0755, true);
        mkdir($toolsDir, 0755, true);

        file_put_contents($extractDir . '/database.sql.zst', 'compressed');
        file_put_contents($targetDir . '/docker/.env', "MARIADB_DATABASE=example_db\n");
        $capturedSql = $rootDir . '/captured.sql';

        file_put_contents(
            $toolsDir . '/zstd',
            <<<'SH'
#!/bin/sh
out=''
while [ "$#" -gt 0 ]; do
  if [ "$1" = "-o" ]; then
    shift
    out="$1"
  fi
  shift
done
printf 'CREATE TABLE streamed(id int);\nINSERT INTO streamed VALUES (1);\n' > "$out"
SH
        );
        chmod($toolsDir . '/zstd', 0755);

        file_put_contents(
            $toolsDir . '/docker',
            '#!/bin/sh' . "\n"
            . 'if [ "$1" = "exec" ] && [ "$2" = "-i" ]; then' . "\n"
            . '  cat > ' . escapeshellarg($capturedSql) . "\n"
            . "  exit 0\n"
            . "fi\n"
            . "exit 0\n"
        );
        chmod($toolsDir . '/docker', 0755);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $command = new ImportCommand();
            $ioProperty = new \ReflectionProperty($command, 'io');
            $ioProperty->setValue($command, new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));

            $method = new \ReflectionMethod($command, 'importDatabase');
            $result = $method->invoke($command, $extractDir, $targetDir, 'example.com');

            $this->assertTrue($result);
            $this->assertFileExists($capturedSql);
            $this->assertStringContainsString('CREATE TABLE streamed', (string) file_get_contents($capturedSql));
            $this->assertFileDoesNotExist($extractDir . '/database.sql');
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
            TestHelper::removeDir($rootDir);
        }
    }

    public function testImportContentFailsWhenChownFails(): void
    {
        $rootDir = TestHelper::createTempDir('kvs-import-chown-');
        $extractDir = $rootDir . '/extract';
        $contentDir = $extractDir . '/content';
        $toolsDir = $rootDir . '/tools';
        mkdir($contentDir, 0755, true);
        mkdir($toolsDir, 0755, true);

        file_put_contents($contentDir . '/video.mp4', 'video');
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
            $command = new ImportCommand();
            $output = new BufferedOutput();
            $ioProperty = new \ReflectionProperty($command, 'io');
            $ioProperty->setValue($command, new SymfonyStyle(new ArrayInput([]), $output));

            $method = new \ReflectionMethod($command, 'importContent');
            $domain = '../../' . ltrim($rootDir, '/') . '/target';
            $result = $method->invoke($command, $extractDir, $domain);

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
