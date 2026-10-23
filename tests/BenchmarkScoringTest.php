<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Benchmark\BenchmarkResult;
use KVS\CLI\Benchmark\CpuBench;
use KVS\CLI\Benchmark\ExperimentResult;
use KVS\CLI\Benchmark\FileIOBench;
use KVS\CLI\Service\TempFileManager;
use PHPUnit\Framework\TestCase;

class BenchmarkScoringTest extends TestCase
{
    protected function tearDown(): void
    {
        TempFileManager::cleanupAll();
    }

    public function testAllProducedCpuAndFileTestsHaveScoringReferences(): void
    {
        $result = new BenchmarkResult();
        (new CpuBench(10))->run($result);
        (new FileIOBench(10))->run($result);

        $spec = json_decode((string) file_get_contents(__DIR__ . '/../resources/benchmark-scoring-v2.json'), true);
        $this->assertEqualsCanonicalizing(array_keys($spec['categories']['cpu']), array_keys($result->getCpuResults()));
        $this->assertEqualsCanonicalizing(array_keys($spec['categories']['fileio']), array_keys($result->getFileIOResults()));
        $missing = $result->getScoringDetails()['missing_tests'];
        $this->assertSame([], array_values(array_filter(
            $missing,
            static fn(string $key): bool => str_starts_with($key, 'cpu.') || str_starts_with($key, 'fileio.')
        )));
        $this->assertFalse($result->getScoringDetails()['comparable']);
        $this->assertContains('cpu.md5_simple', $result->getScoringDetails()['insufficient_samples']);
    }

    public function testDefaultCpuAndFileIterationsMeetThePublicationRequirements(): void
    {
        $result = new BenchmarkResult();
        (new CpuBench())->run($result);
        (new FileIOBench())->run($result);
        $this->assertSame([], $result->getScoringDetails()['insufficient_samples']);
    }

    public function testMissingCacheDoesNotRedistributeItsWeight(): void
    {
        $result = $this->completeResult();
        $data = $result->toArray();
        $data['cache_results'] = [];
        $partial = BenchmarkResult::fromArray($data);

        $this->assertLessThan($result->calculateScore(), $partial->calculateScore());
        $this->assertFalse($partial->getScoringDetails()['complete']);
        $this->assertFalse($partial->getScoringDetails()['comparable']);
        $this->assertContains('cache.bulk_get', $partial->getScoringDetails()['missing_tests']);
        $expected = $result->calculateScore() - 0.25 * $result->getScoringDetails()['category_scores']['cache'];
        $this->assertEqualsWithDelta($expected, $partial->calculateScore(), 1);
    }

    public function testPreviouslyIgnoredOperationsNowAffectTheScore(): void
    {
        $result = $this->completeResult();
        foreach (['fileio_results' => 'write_sync', 'cpu_results' => 'md5_file_1kb'] as $category => $key) {
            $data = $result->toArray();
            $data[$category][$key]['ops_sec'] *= 10;
            $this->assertGreaterThan($result->calculateScore(), BenchmarkResult::fromArray($data)->calculateScore());
        }
    }

    public function testCompleteSuiteWithEnoughSamplesIsComparable(): void
    {
        $result = $this->completeResult();
        $scoring = $result->getScoringDetails();
        $this->assertSame(1000, $result->calculateScore());
        $this->assertSame(2, $scoring['version']);
        $this->assertTrue($scoring['complete']);
        $this->assertTrue($scoring['comparable']);
        $this->assertSame([], $scoring['missing_tests']);
        $this->assertSame([], $scoring['insufficient_samples']);

        $export = (new ExperimentResult($result))->toArray();
        $this->assertSame($scoring, $export['results']['scoring']);
        $this->assertArrayNotHasKey('efficiency_score', $export);
        $this->assertSame($result->calculateScore(), BenchmarkResult::fromArray($export)->calculateScore());
    }

    public function testSingleBulkSampleMakesAnOtherwiseCompleteSuiteProvisional(): void
    {
        $result = $this->completeResult();
        $data = $result->toArray();
        $data['cache_results']['bulk_get']['samples'] = 1;
        $scoring = BenchmarkResult::fromArray($data)->getScoringDetails();
        $this->assertTrue($scoring['complete']);
        $this->assertFalse($scoring['comparable']);
        $this->assertSame(['cache.bulk_get'], $scoring['insufficient_samples']);
    }

    public function testLegacyImportRetainsOriginalScoringMethod(): void
    {
        $result = new BenchmarkResult();
        $result->recordDb('user_lookup', 'Lookup', 0.2, 5000, 100);
        $result->recordDb('insert', 'Insert', 0.1, 10000, 100);
        $result->recordDb('update', 'Update', 0.1, 10000, 100);
        $data = $result->toArray();
        unset($data['scoring']);

        $legacy = BenchmarkResult::fromArray($data);
        $this->assertSame(1000, $legacy->calculateScore());
        $this->assertSame(350, $result->calculateScore());
        $this->assertSame(1, $legacy->getScoringVersion());
        $this->assertFalse($legacy->getScoringDetails()['comparable']);
    }

    public function testHttpTimingAndCpuCountDoNotChangeTheScore(): void
    {
        $result = $this->completeResult();
        $score = $result->calculateScore();
        $result->setSystemInfo(['cpu_cores' => 96]);
        $result->recordHttp('homepage', 'Homepage', [
            'avg' => 10000, 'min' => 10000, 'max' => 10000,
            'p50' => 10000, 'p95' => 10000, 'p99' => 10000, 'req_sec' => 0.1, 'samples' => 100,
        ]);
        $this->assertSame($score, $result->calculateScore());
    }

    private function completeResult(): BenchmarkResult
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/../resources/benchmark-scoring-v2.json'), true);
        $result = new BenchmarkResult();
        foreach ($spec['categories'] as $category => $tests) {
            foreach ($tests as $key => $reference) {
                $rate = $reference['baseline'];
                $stats = [
                    'avg' => 1000 / $rate, 'min' => 0.0, 'max' => 1.0,
                    'p50' => 0.1, 'p95' => 0.1, 'p99' => 0.1, 'std_dev' => 0.0,
                    'ops_sec' => $rate, 'samples' => 1000,
                ];
                match ($category) {
                    'db' => $result->recordDb($key, $key, 1000 / $rate, $rate, 1000),
                    'cpu' => $result->recordCpu($key, $key, $stats),
                    'cache' => $result->recordCache($key, $key, $stats),
                    'fileio' => $result->recordFileIO($key, $key, $stats),
                };
            }
        }
        return $result;
    }
}
