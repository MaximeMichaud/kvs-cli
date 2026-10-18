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

    public function testRejectsSubmittingAShortRunBeforeExecutingBenchmarks(): void
    {
        $command = new BenchmarkCommand(new Configuration(['path' => $this->kvsPath]));
        $tester = new CommandTester($command);
        $tester->execute([
            '--skip-version-check' => true,
            '--cli' => true,
            '--submit' => true,
            '--cpu-iterations' => '10',
        ]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Cannot submit a provisional score', $tester->getDisplay());
        $this->assertStringNotContainsString('Testing CPU performance', $tester->getDisplay());
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

    /**
     * @dataProvider provideFailedExportTargets
     */
    public function testFailedExportReturnsFailureAndPreservesExistingFiles(string $kind): void
    {
        $target = $this->kvsPath . '/result.json';
        if ($kind === 'read-only file') {
            file_put_contents($target, 'Synthetic export sentinel');
            chmod($target, 0444);
            if (is_writable($target)) {
                $this->markTestSkipped('The current user can write a read-only export file.');
            }
        } elseif ($kind === 'directory') {
            mkdir($target);
            file_put_contents($target . '/sentinel', 'Synthetic directory sentinel');
        } else {
            $folder = $this->kvsPath . '/output';
            $target = $folder . '/result.json';
            if ($kind !== 'missing parent') {
                mkdir($folder, $kind === 'unreadable parent' ? 0000 : 0555);
                if (is_writable($folder)) {
                    chmod($folder, 0777);
                    $this->markTestSkipped('The current user can write a read-only export directory.');
                }
            }
        }

        try {
            $tester = $this->runOfflineBenchmark(['--export' => $target]);
        } finally {
            if (isset($folder) && is_dir($folder)) {
                chmod($folder, 0777);
            }
        }

        $this->assertSame(1, $tester->getStatusCode(), 'An unwritten export must return failure.');
        $this->assertStringContainsString('Failed to export results', $tester->getDisplay());
        $this->assertStringNotContainsString('Results exported to:', $tester->getDisplay());
        if ($kind === 'read-only file') {
            $this->assertSame('Synthetic export sentinel', file_get_contents($target));
        } elseif ($kind === 'directory') {
            $this->assertDirectoryExists($target);
            $this->assertSame('Synthetic directory sentinel', file_get_contents($target . '/sentinel'));
        } else {
            $this->assertFileDoesNotExist($target);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideFailedExportTargets(): array
    {
        return [
            'read-only file' => ['read-only file'],
            'read-only parent' => ['read-only parent'],
            'unreadable parent' => ['unreadable parent'],
            'directory' => ['directory'],
            'missing parent' => ['missing parent'],
        ];
    }

    public function testWritableExportContainsMeasurementsAndSupportsBaselineComparison(): void
    {
        $target = $this->kvsPath . '/result.json';
        $tester = $this->runOfflineBenchmark(['--export' => $target]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($target);
        $before = file_get_contents($target);
        $data = json_decode($before, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $data['results']['scoring']['version']);
        $this->assertSame('cli', $data['system']['benchmark_mode']);
        $this->assertGreaterThan(0, $data['results']['cpu']['md5_simple']['samples']);
        $this->assertGreaterThan(0, $data['results']['fileio']['config_load']['ops_sec']);

        $comparison = $this->runOfflineBenchmark(['--compare' => $target]);

        $this->assertSame(0, $comparison->getStatusCode(), $comparison->getDisplay());
        $this->assertStringContainsString('vs Baseline:', $comparison->getDisplay());
        $this->assertSame($before, file_get_contents($target));
    }

    /**
     * @param array<string, string> $options
     */
    private function runOfflineBenchmark(array $options): CommandTester
    {
        $previous = [];
        foreach (['HTTPS_PROXY', 'HTTP_PROXY', 'ALL_PROXY', 'NO_PROXY', 'no_proxy'] as $name) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . (str_contains(strtolower($name), 'no_proxy')
                ? '127.0.0.1,localhost'
                : 'http://127.0.0.1:1'));
        }
        try {
            $tester = new CommandTester(new BenchmarkCommand(new Configuration(['path' => $this->kvsPath])));
            $tester->execute($options + [
                '--skip-version-check' => true,
                '--cli' => true,
                '--cpu-iterations' => '1',
                '--cache-iterations' => '1',
                '--file-iterations' => '1',
                '--db-iterations' => '1',
                '--memcached-port' => '1',
                '--php-container' => 'synthetic-unavailable-php',
            ]);
            return $tester;
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }
}
