<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

/**
 * HTTP benchmark using curl for reliable response time measurements
 *
 * Uses curl with precise timing to measure real page response times.
 * This is NOT a stress test - it measures actual performance.
 *
 * Supports localhost mode to bypass DNS/CDN/proxy layers (Cloudflare, etc.)
 * by forcing hostname resolution to 127.0.0.1 using CURLOPT_RESOLVE.
 * This maintains HTTPS support while testing the local server directly.
 */
class HttpBench
{
    private string $baseUrl;
    private int $samples;
    private bool $useLocalhost;
    private string $hostHeader;

    /** @var list<string> */
    private array $curlResolve = [];

    /** @var array<string, array{url: string, name: string}> */
    private array $endpoints = [];

    public function __construct(string $baseUrl, int $samples = 5, bool $useLocalhost = false)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->samples = $samples;
        $this->useLocalhost = $useLocalhost;

        // Parse URL for localhost mode
        $parsed = parse_url($this->baseUrl);
        if (!is_array($parsed)) {
            $parsed = [];
        }
        $host = $parsed['host'] ?? 'localhost';
        $scheme = $parsed['scheme'] ?? 'http';
        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

        $this->hostHeader = $host;

        // Prepare CURLOPT_RESOLVE for localhost mode (bypass DNS)
        if ($this->useLocalhost) {
            // Format: "hostname:port:127.0.0.1"
            // Add both HTTP and HTTPS ports to cover all cases
            $this->curlResolve = [
                "{$host}:80:127.0.0.1",
                "{$host}:443:127.0.0.1",
            ];
            // If custom port, add it too
            if ($port !== 80 && $port !== 443) {
                $this->curlResolve[] = "{$host}:{$port}:127.0.0.1";
            }
        }

        // Define KVS endpoints to test
        $this->endpoints = [
            'homepage' => ['url' => '/', 'name' => 'Homepage'],
            'videos' => ['url' => '/videos/', 'name' => 'Video Listing'],
            'categories' => ['url' => '/categories/', 'name' => 'Categories'],
            'search' => ['url' => '/search/?q=test', 'name' => 'Search'],
            'admin' => ['url' => '/admin/', 'name' => 'Admin Panel'],
        ];
    }

    /**
     * Check if we can reach the server
     */
    public function isServerReachable(): bool
    {
        // Check curl extension availability
        if (!extension_loaded('curl')) {
            return false;
        }

        $url = $this->baseUrl . '/';

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_NOBODY => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ];

        // Use CURLOPT_RESOLVE for localhost mode (force DNS to 127.0.0.1)
        if ($this->useLocalhost && $this->curlResolve !== []) {
            $options[CURLOPT_RESOLVE] = $this->curlResolve;
        }

        curl_setopt_array($ch, $options);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $httpCode > 0 && $httpCode < 500;
    }

    /**
     * Run HTTP benchmarks
     */
    public function run(BenchmarkResult $result): void
    {
        if (!$this->isServerReachable()) {
            return;
        }

        foreach ($this->endpoints as $key => $endpoint) {
            $timings = $this->measureEndpoint($endpoint['url']);

            if ($timings !== []) {
                $stats = $this->calculateStats($timings);
                $result->recordHttp($key, $endpoint['name'], $stats);
            }
        }
    }

    /**
     * Measure response time for an endpoint
     *
     * @return array<int, float> Array of response times in milliseconds
     */
    private function measureEndpoint(string $path): array
    {
        $url = $this->baseUrl . $path;
        $timings = [];

        for ($i = 0; $i < $this->samples; $i++) {
            $timing = $this->curlTiming($url);
            if ($timing !== null) {
                $timings[] = $timing;
            }
            // Small delay between requests to be nice to the server
            usleep(100000); // 100ms
        }

        return $timings;
    }

    /**
     * Get detailed timing from curl
     *
     * @return float|null Response time in milliseconds, or null on error
     */
    private function curlTiming(string $url): ?float
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            // @phpstan-ignore function.alreadyNarrowedType
            CURLOPT_USERAGENT => 'kvs-cli/' . (defined('KVS_CLI_VERSION') && is_string(KVS_CLI_VERSION) ? KVS_CLI_VERSION : '1.0'),
            CURLOPT_HEADER => true,
            // CURLOPT_NOBODY must be false to measure full response time including body transfer
            CURLOPT_NOBODY => false,
        ];

        // Use CURLOPT_RESOLVE for localhost mode (force DNS to 127.0.0.1)
        if ($this->useLocalhost && $this->curlResolve !== []) {
            $options[CURLOPT_RESOLVE] = $this->curlResolve;
        }

        curl_setopt_array($ch, $options);

        curl_exec($ch);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode === 0 || $httpCode >= 500) {
            return null;
        }

        // Get total time in milliseconds
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

        return $totalTime * 1000;
    }

    /**
     * Calculate statistics from timing samples
     *
     * @param array<int, float> $timings
     * @return array{
     *     avg: float, min: float, max: float,
     *     p50: float, p95: float, p99: float,
     *     req_sec: float, samples: int
     * }
     */
    private function calculateStats(array $timings): array
    {
        if ($timings === []) {
            return [
                'avg' => 0.0,
                'min' => 0.0,
                'max' => 0.0,
                'p50' => 0.0,
                'p95' => 0.0,
                'p99' => 0.0,
                'req_sec' => 0.0,
                'samples' => 0,
            ];
        }

        sort($timings);
        $count = count($timings);
        $avg = array_sum($timings) / $count;

        return [
            'avg' => $avg,
            'min' => $timings[0],
            'max' => $timings[$count - 1],
            'p50' => $this->percentile($timings, 50),
            'p95' => $this->percentile($timings, 95),
            'p99' => $this->percentile($timings, 99),
            'req_sec' => $avg > 0 ? round(1000 / $avg, 2) : 0.0,
            'samples' => $count,
        ];
    }

    /**
     * Calculate percentile from sorted array
     *
     * @param array<int, float> $sorted
     */
    private function percentile(array $sorted, int $p): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }

        $index = (int) ceil(($p / 100) * $count) - 1;
        $index = max(0, min($count - 1, $index));

        return $sorted[$index];
    }

    /**
     * Get base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Check if localhost mode is enabled
     */
    public function isLocalhostMode(): bool
    {
        return $this->useLocalhost;
    }

    /**
     * Get the host header being used
     */
    public function getHostHeader(): string
    {
        return $this->hostHeader;
    }
}
