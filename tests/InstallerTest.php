<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class InstallerTest extends TestCase
{
    private string $directory;
    private const PHAR = "#!/usr/bin/env php\n<?php echo 'synthetic PHAR';\n";

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kvs-installer-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/bin', 0700, true);
        mkdir($this->directory . '/payload', 0700);
        foreach (['bash', 'mktemp', 'rm', 'install', 'mv', 'dirname', 'mkdir', 'sha256sum'] as $command) {
            $which = new Process(['which', $command]);
            $which->mustRun();
            symlink(trim($which->getOutput()), $this->directory . '/bin/' . $command);
        }
        file_put_contents($this->directory . '/payload/kvs.phar', self::PHAR);
        file_put_contents($this->directory . '/payload/kvs.phar.sha256', hash('sha256', self::PHAR) . "  kvs.phar\n");
        copy(__DIR__ . '/../bin/kvs-docker', $this->directory . '/payload/kvs-docker');

        $this->writeExecutable('curl', <<<'PHP'
foreach ($argv as $argument) {
    if (str_starts_with($argument, 'https://')) {
        $url = $argument;
    }
}
file_put_contents(getenv('MOCK_LOG'), $url . "\n", FILE_APPEND);
if (getenv('MOCK_CURL_FAILURE') === '1' ||
    (getenv('MOCK_LAUNCHER_MISSING') === '1' && $url === 'https://fixture.invalid/release/kvs-docker')) {
    exit(22);
}
$destination = $argv[array_search('-o', $argv, true) + 1];
copy(getenv('MOCK_PAYLOAD') . '/' . basename($url), $destination);
PHP);
        $this->writeExecutable('docker', 'exit(0);');
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->directory);
    }

    public function testPipedInstallerAutomaticallyInstallsDockerWithoutHostPhp(): void
    {
        $process = $this->launch();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('docker runtime', $process->getOutput());
        $this->assertSame(self::PHAR, file_get_contents($this->directory . '/installed/kvs.phar'));
        $this->assertFileEquals(__DIR__ . '/../bin/kvs-docker', $this->directory . '/installed/kvs');
        $this->assertSame(0755, fileperms($this->directory . '/installed/kvs') & 0777);
        $this->assertSame(0644, fileperms($this->directory . '/installed/kvs.phar') & 0777);
        $this->assertSame([], glob($this->directory . '/installed/.kvs-install.*'));
    }

    public function testSuitablePhpAutomaticallyInstallsNativeExecutable(): void
    {
        $this->addPhp();
        $process = $this->launch();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('native runtime', $process->getOutput());
        $this->assertSame(self::PHAR, file_get_contents($this->directory . '/installed/kvs'));
        $this->assertFileDoesNotExist($this->directory . '/installed/kvs.phar');
        $this->assertSame(0755, fileperms($this->directory . '/installed/kvs') & 0777);
    }

    public function testOldOrIncompletePhpFallsBackToDocker(): void
    {
        $this->addPhp();
        $process = $this->launch([], ['MOCK_BAD_PHP' => '1']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('docker runtime', $process->getOutput());
    }

    public function testDockerCanBeForcedWhenPhpIsInstalled(): void
    {
        $this->addPhp();
        $process = $this->launch(['--docker']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileEquals(__DIR__ . '/../bin/kvs-docker', $this->directory . '/installed/kvs');
    }

    public function testExistingReleaseWithoutLauncherUsesRepositoryFallback(): void
    {
        $process = $this->launch([], ['MOCK_LAUNCHER_MISSING' => '1']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('https://fixture.invalid/repository/kvs-docker', file_get_contents($this->directory . '/calls'));
        $this->assertFileEquals(__DIR__ . '/../bin/kvs-docker', $this->directory . '/installed/kvs');
    }

    public function testChecksumMismatchPreservesExistingInstallation(): void
    {
        $this->existingInstallation();
        file_put_contents($this->directory . '/payload/kvs.phar', 'corrupted download');
        $process = $this->launch();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('checksum verification failed', $process->getErrorOutput());
        $this->assertExistingInstallation();
    }

    public function testDownloadFailurePreservesExistingInstallation(): void
    {
        $this->existingInstallation();
        $process = $this->launch([], ['MOCK_CURL_FAILURE' => '1']);

        $this->assertSame(22, $process->getExitCode());
        $this->assertExistingInstallation();
    }

    public function testInvalidLauncherPreservesExistingInstallation(): void
    {
        $this->existingInstallation();
        file_put_contents($this->directory . '/payload/kvs-docker', "#!/bin/bash\nif\n");
        $process = $this->launch();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertExistingInstallation();
    }

    public function testForcedNativeWithoutPhpFailsBeforeAnyDownload(): void
    {
        $process = $this->launch(['--native']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Native installation requires PHP', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory . '/calls');
    }

    public function testCustomInstallationDirectoryDoesNotRequireSudo(): void
    {
        $process = $this->launch(['--install-dir', $this->directory . '/user-bin']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileExists($this->directory . '/user-bin/kvs');
        $this->assertFileDoesNotExist($this->directory . '/installed/kvs');
    }

    private function addPhp(): void
    {
        $this->writeExecutable('php', <<<'PHP'
if (($argv[1] ?? '') === '-r') {
    exit((int) getenv('MOCK_BAD_PHP'));
}
echo "KVS CLI fixture version\n";
PHP);
    }

    private function writeExecutable(string $name, string $php): void
    {
        file_put_contents($this->directory . '/bin/' . $name, '#!' . PHP_BINARY . "\n<?php\n" . $php);
        chmod($this->directory . '/bin/' . $name, 0700);
    }

    private function existingInstallation(): void
    {
        mkdir($this->directory . '/installed', 0700);
        file_put_contents($this->directory . '/installed/kvs', 'original launcher');
        file_put_contents($this->directory . '/installed/kvs.phar', 'original PHAR');
    }

    private function assertExistingInstallation(): void
    {
        $this->assertSame('original launcher', file_get_contents($this->directory . '/installed/kvs'));
        $this->assertSame('original PHAR', file_get_contents($this->directory . '/installed/kvs.phar'));
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $environment
     */
    private function launch(array $args = [], array $environment = []): Process
    {
        $process = new Process(array_merge(['bash', '-s', '--'], $args), $this->directory, array_merge([
            'PATH' => $this->directory . '/bin',
            'KVS_INSTALL_DIR' => $this->directory . '/installed',
            'KVS_RELEASE_URL' => 'https://fixture.invalid/release',
            'KVS_LAUNCHER_URL' => 'https://fixture.invalid/repository/kvs-docker',
            'MOCK_LOG' => $this->directory . '/calls',
            'MOCK_PAYLOAD' => $this->directory . '/payload',
            'MOCK_BAD_PHP' => '0',
        ], $environment));
        $process->setInput(file_get_contents(__DIR__ . '/../install.sh'));
        $process->run();
        return $process;
    }
}
