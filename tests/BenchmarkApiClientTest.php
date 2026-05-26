<?php

declare(strict_types=1);

namespace KVS\CLI\Tests;

use KVS\CLI\Benchmark\BenchmarkResult;
use KVS\CLI\Benchmark\ExperimentResult;
use KVS\CLI\Service\BenchmarkApiClient;
use PHPUnit\Framework\TestCase;

class BenchmarkApiClientTest extends TestCase
{
    public function testSubmitReturnsErrorWhenApiUrlIsDisabled(): void
    {
        $client = new BenchmarkApiClient('');
        $response = $client->submit(new ExperimentResult(new BenchmarkResult()));

        $this->assertFalse($response->success);
        $this->assertSame('Benchmark API URL not configured', $response->message);
        $this->assertNull($response->url);
    }

    public function testConfiguredApiUrlCanBeDisabledWithEnvironment(): void
    {
        $previous = getenv('KVS_BENCHMARK_API_URL');
        putenv('KVS_BENCHMARK_API_URL=');

        try {
            $this->assertSame('', BenchmarkApiClient::getConfiguredApiUrl());
        } finally {
            if ($previous === false) {
                putenv('KVS_BENCHMARK_API_URL');
            } else {
                putenv('KVS_BENCHMARK_API_URL=' . $previous);
            }
        }
    }
}
