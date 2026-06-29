<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Migrate\PackageCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PackageCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private PackageCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tempDir = TestHelper::createTempDir('kvs-package-test-');
        mkdir($this->tempDir . '/admin/include', 0755, true);
        mkdir($this->tempDir . '/admin/data', 0755, true);
        mkdir($this->tempDir . '/contents/videos_sources', 0755, true);

        TestHelper::createMockDbConfig($this->tempDir);

        file_put_contents(
            $this->tempDir . '/admin/include/setup.php',
            '<?php $config = ["project_version" => "6.4.0", "project_name" => "Test KVS"];'
        );

        file_put_contents(
            $this->tempDir . '/admin/include/version.php',
            "<?php \$config['project_version'] = '6.4.0';"
        );

        $this->config = new Configuration(['path' => $this->tempDir]);
        $this->command = new PackageCommand($this->config);

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

    public function testPackageCommandShowsTitle(): void
    {
        // This test will fail if zstd/mysqldump not available, which is expected in CI
        $this->tester->execute(['--no-content' => true, '-o' => $this->packagePath(), '--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('KVS Migration Package', $output);
    }

    public function testPackageCommandWithInvalidPath(): void
    {
        $this->tester->execute(['path' => '/nonexistent/path', '--force' => true]);

        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testPackageCommandShowsSource(): void
    {
        $this->tester->execute(['--no-content' => true, '-o' => $this->packagePath(), '--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Source:', $output);
        $this->assertStringContainsString($this->tempDir, $output);
    }

    public function testPackageCommandShowsCompressionLevel(): void
    {
        $this->tester->execute([
            '--no-content' => true,
            '-o' => $this->packagePath(),
            '-c' => '5',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('zstd level 5', $output);
    }

    public function testPackageCommandRejectsInvalidCompressionLevel(): void
    {
        $this->tester->execute([
            '--no-content' => true,
            '-o' => $this->packagePath(),
            '-c' => '25',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Compression level must be between 1 and 19', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testPackageCommandRejectsNonIntegerCompressionBeforeLoadingTargetPath(): void
    {
        $this->tester->execute([
            'path' => '/nonexistent/path',
            '-c' => '1abc',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Compression level must be an integer between 1 and 19', $output);
        $this->assertStringNotContainsString('does not contain a valid KVS installation', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }

    public function testPackageHelpDoesNotAdvertiseChecksums(): void
    {
        $help = $this->command->getHelp();

        $this->assertStringContainsString('metadata.json', $help);
        $this->assertStringContainsString('version, paths, sizes', $help);
        $this->assertStringNotContainsString('checksums', $help);
    }

    public function testPackageCommandRejectsTinyDatabaseDump(): void
    {
        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);

        $dump = $toolsDir . '/mariadb-dump';
        file_put_contents(
            $dump,
            <<<'SH'
#!/bin/sh
echo 'dump connection failed' >&2
exit 1
SH
        );
        chmod($dump, 0755);

        $zstd = $toolsDir . '/zstd';
        file_put_contents(
            $zstd,
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
cat >/dev/null
printf 'tiny-zstd' > "$out"
SH
        );
        chmod($zstd, 0755);

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        $package = $this->packagePath();

        try {
            $this->tester->execute(['--no-content' => true, '-o' => $package, '--force' => true]);

            $output = $this->tester->getDisplay();
            $this->assertSame(1, $this->tester->getStatusCode(), $output);
            $this->assertStringContainsString('empty or invalid dump', $output);
            $this->assertStringContainsString('dump connection failed', $output);
            $this->assertFileDoesNotExist($package);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    public function testCopyContentDoesNotWriteCarriageReturnProgressInPlainOutput(): void
    {
        $toolsDir = $this->tempDir . '/tools';
        mkdir($toolsDir, 0755, true);

        $rsync = $toolsDir . '/rsync';
        file_put_contents(
            $rsync,
            <<<'SH'
#!/bin/sh
previous=''
for arg in "$@"; do
  source="$previous"
  target="$arg"
  previous="$arg"
done
source="${source%/}"
target="${target%/}"
mkdir -p "$target"
cp -R "$source/." "$target/"
printf '          1,024 100%%    1.00kB/s    0:00:00\r'
SH
        );
        chmod($rsync, 0755);

        mkdir($this->tempDir . '/contents/videos_sources/1000', 0755, true);
        file_put_contents($this->tempDir . '/contents/videos_sources/1000/source.mp4', 'video');

        $previousPath = getenv('PATH');
        putenv('PATH=' . $toolsDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));

        try {
            $input = new ArrayInput([]);
            $output = new BufferedOutput(decorated: false);

            $initialize = new \ReflectionMethod($this->command, 'initialize');
            $initialize->invoke($this->command, $input, $output);

            $copyContent = new \ReflectionMethod($this->command, 'copyContent');
            $contentDir = $copyContent->invoke($this->command, $this->config, $this->tempDir . '/package-work');

            $display = $output->fetch();

            $this->assertIsString($contentDir);
            $this->assertFileExists($contentDir . '/videos_sources/1000/source.mp4');
            $this->assertStringNotContainsString("\r", $display);
            $this->assertStringContainsString('Content copied: 1 files', $display);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }
        }
    }

    private function packagePath(): string
    {
        return $this->tempDir . '/test-pkg-' . bin2hex(random_bytes(8)) . '.tar.zst';
    }
}
