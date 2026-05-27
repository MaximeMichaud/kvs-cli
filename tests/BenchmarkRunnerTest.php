<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Benchmark\BenchmarkRunner;
use KVS\CLI\Benchmark\RemoteBenchmarkClient;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;

class BenchmarkRunnerTest extends TestCase
{
    public function testRemoteRunImportsServerSideBenchmarkResults(): void
    {
        $kvsPath = TestHelper::createTestKvsInstallation([
            'project_url' => 'https://example.test',
        ]);
        $config = TestHelper::createTestConfiguration($kvsPath);

        $remoteClient = new class ($config, $this->remotePayload()) extends RemoteBenchmarkClient {
            /**
             * @param array<string, mixed> $payload
             */
            public function __construct(Configuration $config, private array $payload)
            {
                parent::__construct($config);
            }

            /**
             * @return array<string, mixed>
             */
            public function run(): array
            {
                return $this->payload;
            }
        };

        $runner = new BenchmarkRunner(
            db: null,
            tablePrefix: 'ktvs_',
            baseUrl: '',
            httpSamples: 1,
            dbIterations: 1,
            memcachedConfig: [],
            cacheIterations: 1,
            fileIterations: 1,
            cpuIterations: 1,
            httpRuns: 1
        );
        $runner->setRemoteExecution($remoteClient);

        $progressStages = [];
        $result = $runner->run(static function (string $stage) use (&$progressStages): void {
            $progressStages[] = $stage;
        });

        $systemInfo = $result->getSystemInfo();
        $this->assertSame('fpm', $systemInfo['benchmark_mode']);
        $this->assertSame('Server (PHP-FPM)', $systemInfo['source']);
        $this->assertSame('8.1.34', $systemInfo['php_version']);
        $this->assertSame('fpm-fcgi', $systemInfo['php_sapi']);
        $this->assertSame('dragonfly', $systemInfo['cache_backend']);
        $this->assertSame('1.36.0', $systemInfo['memcached_version']);
        $this->assertSame('mariadb', $systemInfo['db_type']);
        $this->assertSame('11.8.3', $systemInfo['db_version']);
        $this->assertSame(0.123, $systemInfo['remote_execution_time']);

        $cpuResults = $result->getCpuResults();
        $this->assertSame('Remote Hash', $cpuResults['hash']['name']);
        $this->assertSame(2500.0, $cpuResults['hash']['ops_sec']);

        $cacheResults = $result->getCacheResults();
        $this->assertSame('Remote Cache Set', $cacheResults['set']['name']);
        $this->assertSame(15000.0, $cacheResults['set']['ops_sec']);

        $fileResults = $result->getFileIOResults();
        $this->assertSame('Remote File Read', $fileResults['read']['name']);
        $this->assertSame(2000.0, $fileResults['read']['ops_sec']);

        $dbResults = $result->getDbResults();
        $this->assertSame('Remote User Lookup', $dbResults['user_lookup']['name']);
        $this->assertSame(1.1, $dbResults['user_lookup']['avg_ms']);
        $this->assertSame(5, $dbResults['user_lookup']['total_queries']);

        $this->assertContains('remote', $progressStages);
        $this->assertContains('cpu', $progressStages);
        $this->assertContains('cache', $progressStages);
        $this->assertContains('fileio', $progressStages);
        $this->assertContains('db', $progressStages);
    }

    /**
     * @return array<string, mixed>
     */
    private function remotePayload(): array
    {
        return [
            'success' => true,
            'php_info' => [
                'version' => '8.1.34',
                'sapi' => 'fpm-fcgi',
                'opcache_enabled' => true,
                'jit_enabled' => false,
                'memory_limit' => '512M',
                'max_execution_time' => '60',
                'server_software' => 'nginx',
            ],
            'cpu' => [
                'hash' => [
                    'name' => 'Remote Hash',
                    'avg' => 0.4,
                    'min' => 0.3,
                    'max' => 0.5,
                    'p50' => 0.4,
                    'p95' => 0.48,
                    'p99' => 0.5,
                    'std_dev' => 0.02,
                    'ops_sec' => 2500.0,
                    'samples' => 10,
                ],
            ],
            'cache' => [
                'connected' => true,
                'type' => 'dragonfly',
                'version' => '1.36.0',
                'results' => [
                    'set' => [
                        'name' => 'Remote Cache Set',
                        'avg' => 0.06,
                        'min' => 0.04,
                        'max' => 0.08,
                        'p50' => 0.06,
                        'p95' => 0.07,
                        'p99' => 0.08,
                        'ops_sec' => 15000.0,
                        'samples' => 20,
                    ],
                ],
            ],
            'fileio' => [
                'read' => [
                    'name' => 'Remote File Read',
                    'avg' => 0.5,
                    'min' => 0.4,
                    'max' => 0.6,
                    'ops_sec' => 2000.0,
                    'samples' => 5,
                ],
            ],
            'database' => [
                'connected' => true,
                'type' => 'mariadb',
                'version' => '11.8.3',
                'results' => [
                    'user_lookup' => [
                        'name' => 'Remote User Lookup',
                        'avg_ms' => 1.1,
                        'queries_sec' => 900.0,
                        'samples' => 5,
                    ],
                ],
            ],
            'execution_time' => 0.123,
        ];
    }
}
