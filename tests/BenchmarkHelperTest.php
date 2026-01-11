<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Benchmark\BenchmarkHelper;
use KVS\CLI\Tests\Fixtures\BenchmarkHelperTestable;

#[CoversClass(BenchmarkHelper::class)]
class BenchmarkHelperTest extends TestCase
{
    private BenchmarkHelperTestable $helper;

    protected function setUp(): void
    {
        $this->helper = new BenchmarkHelperTestable();
    }

    public function testStartTimerReturnsNanosecondValue(): void
    {
        $start = $this->helper->publicStartTimer();

        $this->assertIsInt($start);
        $this->assertGreaterThan(0, $start);
    }

    public function testStopTimerReturnsMilliseconds(): void
    {
        $start = $this->helper->publicStartTimer();

        // Do some work
        usleep(1000); // 1ms

        $elapsed = $this->helper->publicStopTimer($start);

        $this->assertIsFloat($elapsed);
        $this->assertGreaterThan(0, $elapsed);
        // Should be at least close to 1ms (allowing for timing variance)
        $this->assertGreaterThanOrEqual(0.5, $elapsed);
    }

    public function testWarmupExecutesOperation(): void
    {
        $counter = 0;
        $operation = function () use (&$counter): void {
            $counter++;
        };

        $this->helper->publicWarmup($operation);

        // Default warmup is 3 iterations
        $this->assertEquals(3, $counter);
    }

    public function testWarmupRespectsCustomIterations(): void
    {
        $counter = 0;
        $operation = function () use (&$counter): void {
            $counter++;
        };

        $this->helper->setWarmupIterations(5);
        $this->helper->publicWarmup($operation);

        $this->assertEquals(5, $counter);
    }

    public function testRunBenchmarkReturnsTimings(): void
    {
        $operation = function (): void {
            usleep(100); // 0.1ms
        };

        $timings = $this->helper->publicRunBenchmark($operation, 5);

        $this->assertCount(5, $timings);
        foreach ($timings as $timing) {
            $this->assertIsFloat($timing);
            $this->assertGreaterThan(0, $timing);
        }
    }

    public function testRunBenchmarkIncludesWarmup(): void
    {
        $counter = 0;
        $operation = function () use (&$counter): void {
            $counter++;
        };

        $this->helper->publicRunBenchmark($operation, 10);

        // 3 warmup + 10 measured = 13 total
        $this->assertEquals(13, $counter);
    }

    public function testCalculateStatsReturnsCorrectStructure(): void
    {
        $timings = [1.0, 2.0, 3.0, 4.0, 5.0];

        $stats = $this->helper->publicCalculateStats($timings);

        $this->assertArrayHasKey('avg', $stats);
        $this->assertArrayHasKey('min', $stats);
        $this->assertArrayHasKey('max', $stats);
        $this->assertArrayHasKey('p50', $stats);
        $this->assertArrayHasKey('p95', $stats);
        $this->assertArrayHasKey('p99', $stats);
        $this->assertArrayHasKey('std_dev', $stats);
        $this->assertArrayHasKey('ops_sec', $stats);
        $this->assertArrayHasKey('samples', $stats);
    }

    public function testCalculateStatsWithEmptyArray(): void
    {
        $stats = $this->helper->publicCalculateStats([]);

        $this->assertEquals(0.0, $stats['avg']);
        $this->assertEquals(0.0, $stats['min']);
        $this->assertEquals(0.0, $stats['max']);
        $this->assertEquals(0, $stats['samples']);
    }

    public function testCalculateStatsCorrectValues(): void
    {
        $timings = [1.0, 2.0, 3.0, 4.0, 5.0];

        $stats = $this->helper->publicCalculateStats($timings);

        $this->assertEquals(3.0, $stats['avg']);
        $this->assertEquals(1.0, $stats['min']);
        $this->assertEquals(5.0, $stats['max']);
        $this->assertEquals(5, $stats['samples']);
        // ops/sec = 1000 / avg_ms = 1000 / 3 ≈ 333.33
        $this->assertEqualsWithDelta(333.33, $stats['ops_sec'], 0.01);
    }

    public function testCalculateStatsStandardDeviation(): void
    {
        // Values with known std dev
        $timings = [2.0, 4.0, 4.0, 4.0, 5.0, 5.0, 7.0, 9.0];
        // Mean = 5, Variance = 4, StdDev = 2

        $stats = $this->helper->publicCalculateStats($timings);

        $this->assertEquals(5.0, $stats['avg']);
        $this->assertEqualsWithDelta(2.0, $stats['std_dev'], 0.001);
    }

    public function testPercentileWithEmptyArray(): void
    {
        $result = $this->helper->publicPercentile([], 50);

        $this->assertEquals(0.0, $result);
    }

    public function testPercentile50(): void
    {
        $sorted = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];

        $p50 = $this->helper->publicPercentile($sorted, 50);

        $this->assertEquals(5.0, $p50);
    }

    public function testPercentile95(): void
    {
        $sorted = [];
        for ($i = 1; $i <= 100; $i++) {
            $sorted[] = (float) $i;
        }

        $p95 = $this->helper->publicPercentile($sorted, 95);

        $this->assertEquals(95.0, $p95);
    }

    public function testPercentile99(): void
    {
        $sorted = [];
        for ($i = 1; $i <= 100; $i++) {
            $sorted[] = (float) $i;
        }

        $p99 = $this->helper->publicPercentile($sorted, 99);

        $this->assertEquals(99.0, $p99);
    }

    public function testPercentileBoundary(): void
    {
        $sorted = [1.0, 2.0, 3.0];

        // P100 should return the last element
        $p100 = $this->helper->publicPercentile($sorted, 100);
        $this->assertEquals(3.0, $p100);

        // P0 should return the first element
        $p0 = $this->helper->publicPercentile($sorted, 0);
        $this->assertEquals(1.0, $p0);
    }

    public function testCalculateStatsSingleValue(): void
    {
        $timings = [5.0];

        $stats = $this->helper->publicCalculateStats($timings);

        $this->assertEquals(5.0, $stats['avg']);
        $this->assertEquals(5.0, $stats['min']);
        $this->assertEquals(5.0, $stats['max']);
        $this->assertEquals(5.0, $stats['p50']);
        $this->assertEquals(0.0, $stats['std_dev']);
        $this->assertEquals(1, $stats['samples']);
    }

    public function testCalculateStatsLargeDataset(): void
    {
        // Generate 1000 random-ish timings
        $timings = [];
        for ($i = 0; $i < 1000; $i++) {
            $timings[] = 10.0 + ($i % 10) * 0.1;
        }

        $stats = $this->helper->publicCalculateStats($timings);

        $this->assertEquals(1000, $stats['samples']);
        $this->assertGreaterThan(0, $stats['avg']);
        // p50 should be between min and max
        $this->assertGreaterThanOrEqual($stats['min'], $stats['p50']);
        $this->assertLessThanOrEqual($stats['max'], $stats['p50']);
        // p95 should be <= max
        $this->assertLessThanOrEqual($stats['max'], $stats['p95']);
    }
}
