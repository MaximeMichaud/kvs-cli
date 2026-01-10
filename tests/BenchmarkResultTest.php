<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use KVS\CLI\Benchmark\BenchmarkResult;

#[CoversClass(BenchmarkResult::class)]
class BenchmarkResultTest extends TestCase
{
    private BenchmarkResult $result;

    protected function setUp(): void
    {
        $this->result = new BenchmarkResult();
    }

    public function testRecordHttpStoresResult(): void
    {
        $stats = [
            'avg' => 10.5,
            'min' => 5.0,
            'max' => 20.0,
            'p50' => 10.0,
            'p95' => 18.0,
            'p99' => 19.5,
            'req_sec' => 95.24,
            'samples' => 100,
        ];

        $this->result->recordHttp('homepage', 'Homepage Test', $stats);

        $results = $this->result->getHttpResults();
        $this->assertArrayHasKey('homepage', $results);
        $this->assertEquals('Homepage Test', $results['homepage']['name']);
        $this->assertEquals(10.5, $results['homepage']['avg']);
    }

    public function testRecordDbStoresResult(): void
    {
        $this->result->recordDb('user_lookup', 'User Lookup', 2.5, 400.0, 1000);

        $results = $this->result->getDbResults();
        $this->assertArrayHasKey('user_lookup', $results);
        $this->assertEquals('User Lookup', $results['user_lookup']['name']);
        $this->assertEquals(2.5, $results['user_lookup']['avg_ms']);
        $this->assertEquals(400.0, $results['user_lookup']['queries_sec']);
        $this->assertEquals(1000, $results['user_lookup']['total_queries']);
    }

    public function testRecordCacheStoresResult(): void
    {
        $stats = [
            'avg' => 0.05,
            'min' => 0.02,
            'max' => 0.15,
            'p50' => 0.04,
            'p95' => 0.10,
            'p99' => 0.12,
            'ops_sec' => 20000.0,
            'samples' => 500,
            'hit_ratio' => 0.95,
        ];

        $this->result->recordCache('simple_get', 'Simple Get', $stats);

        $results = $this->result->getCacheResults();
        $this->assertArrayHasKey('simple_get', $results);
        $this->assertEquals('Simple Get', $results['simple_get']['name']);
        $this->assertEquals(20000.0, $results['simple_get']['ops_sec']);
        $this->assertEquals(0.95, $results['simple_get']['hit_ratio']);
    }

    public function testRecordFileIOStoresResult(): void
    {
        $stats = [
            'avg' => 0.1,
            'min' => 0.05,
            'max' => 0.3,
            'ops_sec' => 10000.0,
            'samples' => 200,
        ];

        $this->result->recordFileIO('read_1k', 'Read 1KB', $stats);

        $results = $this->result->getFileIOResults();
        $this->assertArrayHasKey('read_1k', $results);
        $this->assertEquals('Read 1KB', $results['read_1k']['name']);
        $this->assertEquals(10000.0, $results['read_1k']['ops_sec']);
    }

    public function testRecordCpuStoresResult(): void
    {
        $stats = [
            'avg' => 0.001,
            'min' => 0.0005,
            'max' => 0.003,
            'p50' => 0.001,
            'p95' => 0.002,
            'p99' => 0.0025,
            'std_dev' => 0.0003,
            'ops_sec' => 1000000.0,
            'samples' => 1000,
        ];

        $this->result->recordCpu('md5_simple', 'MD5 Simple', $stats);

        $results = $this->result->getCpuResults();
        $this->assertArrayHasKey('md5_simple', $results);
        $this->assertEquals('MD5 Simple', $results['md5_simple']['name']);
        $this->assertEquals(1000000.0, $results['md5_simple']['ops_sec']);
    }

    public function testSetAndGetSystemMetrics(): void
    {
        $metrics = [
            'cpu_usage' => 45.5,
            'memory_used' => 2048,
        ];

        $this->result->setSystemMetrics($metrics);

        $this->assertEquals($metrics, $this->result->getSystemMetrics());
    }

    public function testSetAndGetSystemInfo(): void
    {
        $info = [
            'php_version' => '8.3.0',
            'os' => 'Linux',
        ];

        $this->result->setSystemInfo($info);

        $this->assertEquals($info, $this->result->getSystemInfo());
    }

    public function testSetAndGetTotalTime(): void
    {
        $this->result->setTotalTime(125.5);

        $this->assertEquals(125.5, $this->result->getTotalTime());
    }

    public function testSetAndGetTag(): void
    {
        $this->result->setTag('baseline-2024');

        $this->assertEquals('baseline-2024', $this->result->getTag());
    }

    public function testAddAndGetWarnings(): void
    {
        $this->assertFalse($this->result->hasWarnings());
        $this->assertEmpty($this->result->getWarnings());

        $this->result->addWarning('Database', 'Slow query detected');
        $this->result->addWarning('Cache', 'High miss rate');

        $this->assertTrue($this->result->hasWarnings());
        $warnings = $this->result->getWarnings();
        $this->assertCount(2, $warnings);
        $this->assertEquals('Database', $warnings[0]['category']);
        $this->assertEquals('Slow query detected', $warnings[0]['message']);
    }

    public function testSetAndGetDataVolume(): void
    {
        $dataVolume = [
            'videos' => 10000,
            'users' => 5000,
        ];

        $this->result->setDataVolume($dataVolume);

        $this->assertEquals($dataVolume, $this->result->getDataVolume());
    }

    public function testHasResultsMethods(): void
    {
        $this->assertFalse($this->result->hasHttpResults());
        $this->assertFalse($this->result->hasDbResults());
        $this->assertFalse($this->result->hasCacheResults());
        $this->assertFalse($this->result->hasFileIOResults());
        $this->assertFalse($this->result->hasCpuResults());

        // Add some results
        $this->result->recordHttp('test', 'Test', [
            'avg' => 1.0, 'min' => 0.5, 'max' => 2.0,
            'p50' => 1.0, 'p95' => 1.8, 'p99' => 1.9,
            'req_sec' => 1000.0, 'samples' => 10,
        ]);
        $this->result->recordDb('test', 'Test', 1.0, 1000.0, 100);

        $this->assertTrue($this->result->hasHttpResults());
        $this->assertTrue($this->result->hasDbResults());
        $this->assertFalse($this->result->hasCacheResults());
    }

    public function testCalculateScoreReturnsZeroWithNoResults(): void
    {
        $this->assertEquals(0, $this->result->calculateScore());
    }

    public function testCalculateScoreWithDbResults(): void
    {
        // Add DB results that match baseline
        $this->result->recordDb('user_lookup', 'User Lookup', 0.2, 5000.0, 1000);
        $this->result->recordDb('insert', 'Insert', 0.1, 10000.0, 1000);
        $this->result->recordDb('update', 'Update', 0.1, 10000.0, 1000);

        $score = $this->result->calculateScore();
        // Score should be around 1000 since we're at baseline
        $this->assertGreaterThan(0, $score);
    }

    public function testGetRatingReturnsNAWithNoResults(): void
    {
        $this->assertStringContainsString('N/A', $this->result->getRating());
    }

    public function testGetRatingReturnsProperRating(): void
    {
        // Add good results (above baseline)
        $this->result->recordDb('user_lookup', 'User Lookup', 0.1, 10000.0, 1000);
        $this->result->recordDb('insert', 'Insert', 0.05, 20000.0, 1000);
        $this->result->recordDb('update', 'Update', 0.05, 20000.0, 1000);

        $rating = $this->result->getRating();
        // Should get a rating with stars
        $this->assertStringContainsString('★', $rating);
    }

    public function testToArrayReturnsCompleteStructure(): void
    {
        $this->result->setTag('test-run');
        $this->result->setTotalTime(60.0);
        $this->result->setSystemInfo(['php_version' => '8.3.0']);
        $this->result->addWarning('Test', 'Test warning');

        $array = $this->result->toArray();

        $this->assertArrayHasKey('timestamp', $array);
        $this->assertArrayHasKey('system_info', $array);
        $this->assertArrayHasKey('http_results', $array);
        $this->assertArrayHasKey('db_results', $array);
        $this->assertArrayHasKey('cache_results', $array);
        $this->assertArrayHasKey('fileio_results', $array);
        $this->assertArrayHasKey('cpu_results', $array);
        $this->assertArrayHasKey('system_metrics', $array);
        $this->assertArrayHasKey('warnings', $array);
        $this->assertArrayHasKey('data_volume', $array);
        $this->assertArrayHasKey('total_time', $array);
        $this->assertArrayHasKey('score', $array);
        $this->assertArrayHasKey('rating', $array);
        $this->assertArrayHasKey('tag', $array);
        $this->assertEquals('test-run', $array['tag']);
    }

    public function testFromArrayLoadsData(): void
    {
        $data = [
            'system_info' => ['php_version' => '8.3.0'],
            'http_results' => [
                'homepage' => [
                    'name' => 'Homepage',
                    'avg' => 10.0,
                    'min' => 5.0,
                    'max' => 20.0,
                    'p50' => 10.0,
                    'p95' => 18.0,
                    'p99' => 19.0,
                    'req_sec' => 100.0,
                    'samples' => 50,
                ],
            ],
            'db_results' => [
                'user_lookup' => [
                    'name' => 'User Lookup',
                    'avg_ms' => 2.0,
                    'queries_sec' => 500.0,
                    'total_queries' => 1000,
                ],
            ],
            'cache_results' => [],
            'fileio_results' => [],
            'cpu_results' => [],
            'system_metrics' => ['cpu' => 50],
            'warnings' => [
                ['category' => 'Test', 'message' => 'Test warning'],
            ],
            'data_volume' => ['videos' => 1000],
            'total_time' => 120.0,
            'tag' => 'imported',
        ];

        $result = BenchmarkResult::fromArray($data);

        $this->assertEquals(['php_version' => '8.3.0'], $result->getSystemInfo());
        $this->assertTrue($result->hasHttpResults());
        $this->assertTrue($result->hasDbResults());
        $this->assertEquals(120.0, $result->getTotalTime());
        $this->assertEquals('imported', $result->getTag());
        $this->assertTrue($result->hasWarnings());
    }

    public function testFromArrayHandlesEmptyData(): void
    {
        $result = BenchmarkResult::fromArray([]);

        $this->assertFalse($result->hasHttpResults());
        $this->assertFalse($result->hasDbResults());
        $this->assertEquals(0.0, $result->getTotalTime());
        $this->assertEquals('', $result->getTag());
    }

    public function testFromArrayHandlesInvalidData(): void
    {
        $data = [
            'http_results' => [
                'invalid' => 'not an array',
                'missing_name' => ['avg' => 1.0],
            ],
            'db_results' => 'not an array',
            'warnings' => [
                'not a proper warning',
                ['category' => 'Valid', 'message' => 'Valid warning'],
            ],
        ];

        $result = BenchmarkResult::fromArray($data);

        // Should not crash, just skip invalid entries
        $this->assertIsArray($result->getHttpResults());
        $this->assertIsArray($result->getDbResults());
    }

    public function testRoundTripSerialization(): void
    {
        // Set up complete result
        $this->result->setTag('round-trip-test');
        $this->result->setTotalTime(100.0);
        $this->result->setSystemInfo(['php' => '8.3']);
        $this->result->setSystemMetrics(['cpu' => 50]);
        $this->result->addWarning('Test', 'Warning');
        $this->result->setDataVolume(['videos' => 500]);

        $this->result->recordHttp('test', 'Test HTTP', [
            'avg' => 5.0, 'min' => 2.0, 'max' => 10.0,
            'p50' => 5.0, 'p95' => 9.0, 'p99' => 9.5,
            'req_sec' => 200.0, 'samples' => 100,
        ]);

        $this->result->recordDb('test', 'Test DB', 1.0, 1000.0, 500);

        // Serialize and deserialize
        $array = $this->result->toArray();
        $restored = BenchmarkResult::fromArray($array);

        // Verify
        $this->assertEquals($this->result->getTag(), $restored->getTag());
        $this->assertEquals($this->result->getTotalTime(), $restored->getTotalTime());
        $this->assertEquals($this->result->getSystemInfo(), $restored->getSystemInfo());
        $this->assertEquals($this->result->hasHttpResults(), $restored->hasHttpResults());
        $this->assertEquals($this->result->hasDbResults(), $restored->hasDbResults());
    }

    public function testCalculateScoreWithCacheResults(): void
    {
        $this->result->recordCache('simple_get', 'Simple Get', [
            'avg' => 0.025, 'min' => 0.01, 'max' => 0.05,
            'p50' => 0.025, 'p95' => 0.04, 'p99' => 0.045,
            'ops_sec' => 40000.0, 'samples' => 1000,
        ]);

        $score = $this->result->calculateScore();
        $this->assertGreaterThan(0, $score);
    }

    public function testCalculateScoreWithCpuResults(): void
    {
        $this->result->recordCpu('md5_simple', 'MD5 Simple', [
            'avg' => 0.033, 'min' => 0.02, 'max' => 0.05,
            'p50' => 0.033, 'p95' => 0.045, 'p99' => 0.048,
            'std_dev' => 0.005, 'ops_sec' => 30000.0, 'samples' => 1000,
        ]);

        $score = $this->result->calculateScore();
        $this->assertGreaterThan(0, $score);
    }

    public function testCalculateScoreWithFileIOResults(): void
    {
        $this->result->recordFileIO('read_1k', 'Read 1KB', [
            'avg' => 0.0083, 'min' => 0.005, 'max' => 0.015,
            'ops_sec' => 120000.0, 'samples' => 500,
        ]);

        $score = $this->result->calculateScore();
        $this->assertGreaterThan(0, $score);
    }
}
