<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use KVS\CLI\Command\Migrate\PackageCommand;
use KVS\CLI\Config\Configuration;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class PackageCommandTest extends TestCase
{
    private string $tempDir;
    private Configuration $config;
    private PackageCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kvs-package-test-' . uniqid();
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
        $this->tester->execute(['--no-content' => true, '-o' => '/tmp/test-pkg-' . uniqid() . '.tar.zst', '--force' => true]);

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
        $this->tester->execute(['--no-content' => true, '-o' => '/tmp/test-pkg-' . uniqid() . '.tar.zst', '--force' => true]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Source:', $output);
        $this->assertStringContainsString($this->tempDir, $output);
    }

    public function testPackageCommandShowsCompressionLevel(): void
    {
        $this->tester->execute([
            '--no-content' => true,
            '-o' => '/tmp/test-pkg-' . uniqid() . '.tar.zst',
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
            '-o' => '/tmp/test-pkg-' . uniqid() . '.tar.zst',
            '-c' => '25',
            '--force' => true,
        ]);

        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('Compression level must be between 1 and 19', $output);
        $this->assertEquals(1, $this->tester->getStatusCode());
    }
}
