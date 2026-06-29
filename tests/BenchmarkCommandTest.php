<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\BenchmarkCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class BenchmarkCommandTest extends TestCase
{
    private string $kvsPath;

    protected function setUp(): void
    {
        $this->kvsPath = TestHelper::createTempDir('kvs-benchmark-test-');
        mkdir($this->kvsPath . '/admin/include', 0755, true);
        mkdir($this->kvsPath . '/admin/data', 0755, true);
        mkdir($this->kvsPath . '/content', 0755, true);

        file_put_contents(
            $this->kvsPath . '/admin/include/setup_db.php',
            "<?php\ndefine('DB_HOST', '');\ndefine('DB_DEVICE', '');\n"
        );
        file_put_contents(
            $this->kvsPath . '/admin/include/setup.php',
            '<?php $config = ["project_version" => "7.0.0", "tables_prefix" => "ktvs_"];'
        );
    }

    protected function tearDown(): void
    {
        TestHelper::removeDir($this->kvsPath);
    }

    public function testWarnsWhenBenchmarkIterationsAreBelowRecommendedDefaults(): void
    {
        $command = new class (new Configuration(['path' => $this->kvsPath])) extends BenchmarkCommand {
            /**
             * @return list<string>
             */
            public function lowIterationWarningsForTest(
                int $dbIterations,
                int $cacheIterations,
                int $fileIterations,
                int $cpuIterations
            ): array {
                return $this->getLowBenchmarkIterationWarnings(
                    $dbIterations,
                    $cacheIterations,
                    $fileIterations,
                    $cpuIterations
                );
            }
        };

        $warnings = $command->lowIterationWarningsForTest(1, 1, 1, 1);

        $this->assertSame(
            [
                'db=1 (recommended >=10)',
                'cache=1 (recommended >=100)',
                'file=1 (recommended >=100)',
                'cpu=1 (recommended >=1000)',
            ],
            $warnings
        );
        $this->assertSame([], $command->lowIterationWarningsForTest(10, 100, 100, 1000));
    }

    /**
     * @dataProvider provideMalformedNumericOptions
     */
    public function testRejectsMalformedNumericOptionsBeforeRunningBenchmark(string $option, string $value): void
    {
        $command = new BenchmarkCommand(new Configuration(['path' => $this->kvsPath]));
        $tester = new CommandTester($command);

        $tester->execute([
            '--skip-version-check' => true,
            '--cli' => true,
            '--' . $option => $value,
        ]);

        $output = $tester->getDisplay();

        $this->assertSame(1, $tester->getStatusCode(), $output);
        $this->assertStringContainsString("Invalid value for --$option", $output);
        $this->assertStringNotContainsString('Testing CPU performance', $output);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideMalformedNumericOptions(): array
    {
        return [
            'samples suffix' => ['samples', '1abc'],
            'samples decimal' => ['samples', '1.9'],
            'db iterations' => ['db-iterations', '1abc'],
            'cache iterations' => ['cache-iterations', '1abc'],
            'file iterations' => ['file-iterations', '1abc'],
            'cpu iterations' => ['cpu-iterations', '1abc'],
            'memcached port' => ['memcached-port', '11211.5'],
            'runs' => ['runs', '1.9'],
            'remote timeout' => ['remote-timeout', '30.5'],
        ];
    }
}
