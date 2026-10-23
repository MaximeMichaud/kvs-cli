<?php

namespace KVS\CLI\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CodeStyleConfigurationTest extends TestCase
{
    public function testPhpFilesContainingJsonAreLintedWhileAssetsAreExcluded(): void
    {
        $directory = TestHelper::createTempDir('phpcs-config-');
        $phpFile = $directory . '/JsonFixture.php';

        try {
            file_put_contents($phpFile, "<?php\nnamespace AuditFixture;\nclass JsonFixture{public function value(){}}\n");
            file_put_contents($directory . '/ignored.js', "function invalid( {\n");
            file_put_contents($directory . '/ignored.css', "body { invalid:\n");

            $process = $this->runCodeSniffer($directory);
            $this->assertJson(
                $process->getOutput(),
                'PHPCS must inspect PHP files whose names contain Json: ' . $process->getOutput()
                    . $process->getErrorOutput()
            );
            $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($report);
            $this->assertIsArray($report['files']);
            $this->assertSame([realpath($phpFile)], array_keys($report['files']));
            $this->assertIsArray($report['totals']);
            $this->assertGreaterThan(0, $report['totals']['errors']);
            $this->assertNotSame(0, $process->getExitCode());

            file_put_contents($phpFile, <<<'PHP'
<?php

namespace AuditFixture;

class JsonFixture
{
    public function value(): void
    {
    }
}
PHP
                . "\n");

            $process = $this->runCodeSniffer($directory);
            $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $this->assertJson($process->getOutput());
            $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($report);
            $this->assertIsArray($report['files']);
            $this->assertSame([realpath($phpFile)], array_keys($report['files']));
        } finally {
            TestHelper::removeDir($directory);
        }
    }

    private function runCodeSniffer(string $directory): Process
    {
        $root = dirname(__DIR__);
        $process = new Process([
            PHP_BINARY,
            $root . '/vendor/bin/phpcs',
            '--standard=' . $root . '/phpcs.xml',
            '--parallel=1',
            '--extensions=php,js,css',
            '--report=json',
            '--no-colors',
            '-q',
            '-n',
            $directory . '/JsonFixture.php',
            $directory . '/ignored.js',
            $directory . '/ignored.css',
        ], $root);
        $process->run();

        return $process;
    }
}
