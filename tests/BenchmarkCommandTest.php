<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Command\System\BenchmarkCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;

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
}
