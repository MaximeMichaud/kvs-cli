<?php

declare(strict_types=1);

namespace KVS\CLI\Service;

use KVS\CLI\Application;
use KVS\CLI\Benchmark\ExperimentResult;
use KVS\CLI\Constants;

/**
 * Client for submitting benchmark results to the remote API.
 */
final class BenchmarkApiClient
{
    /**
     * Submit benchmark results to the API.
     */
    public function submit(ExperimentResult $result): SubmitResponse
    {
        $url = Constants::BENCHMARK_API_URL;

        // @phpstan-ignore identical.alwaysTrue (placeholder until API is configured)
        if ($url === '') {
            return SubmitResponse::error('Benchmark API URL not configured');
        }

        /** @phpstan-ignore-next-line deadCode.unreachable (code is reachable once API URL is set) */
        $payload = json_encode($result->toArray(), JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'User-Agent: ' . Application::NAME . '/' . Application::VERSION,
                    'Content-Length: ' . strlen($payload),
                ]),
                'content' => $payload,
                'timeout' => Constants::BENCHMARK_API_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            $message = $error['message'] ?? 'Unknown error';

            if (str_contains($message, 'Connection refused')) {
                return SubmitResponse::error('Connection refused - API server may be down');
            }

            if (str_contains($message, 'timed out')) {
                return SubmitResponse::error('Connection timed out');
            }

            return SubmitResponse::error($message);
        }

        // Check HTTP status from response headers
        $statusCode = $this->getHttpStatusCode($http_response_header ?? []);

        if ($statusCode >= 400) {
            return $this->handleErrorResponse($statusCode, $response);
        }

        return $this->parseSuccessResponse($response, $result->getId());
    }

    /**
     * Extract HTTP status code from response headers.
     *
     * @param array<string> $headers
     * @phpstan-ignore method.unused (used once API URL is configured)
     */
    private function getHttpStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 200;
    }

    /**
     * Handle error HTTP response.
     *
     * @phpstan-ignore method.unused (used once API URL is configured)
     */
    private function handleErrorResponse(int $statusCode, string $response): SubmitResponse
    {
        $message = match ($statusCode) {
            400 => 'Bad request - invalid benchmark data',
            401 => 'Unauthorized - authentication required',
            403 => 'Forbidden - access denied',
            404 => 'API endpoint not found',
            429 => 'Rate limited - too many requests',
            500 => 'Server error - please try again later',
            502, 503 => 'Service unavailable - please try again later',
            default => "HTTP error {$statusCode}",
        };

        // Try to extract error message from JSON response
        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($data) && isset($data['error']) && is_string($data['error'])) {
                $message = $data['error'];
            }
        } catch (\JsonException $e) {
            // Use default message
        }

        return SubmitResponse::error($message);
    }

    /**
     * Parse successful API response.
     *
     * @phpstan-ignore method.unused (used once API URL is configured)
     */
    private function parseSuccessResponse(string $response, string $benchmarkId): SubmitResponse
    {
        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return SubmitResponse::success('Benchmark submitted successfully');
            }

            $url = null;
            if (isset($data['url']) && is_string($data['url'])) {
                $url = $data['url'];
            } elseif (isset($data['dashboard_url']) && is_string($data['dashboard_url'])) {
                $url = $data['dashboard_url'];
            }

            $message = 'Benchmark submitted successfully';
            if (isset($data['message']) && is_string($data['message'])) {
                $message = $data['message'];
            }

            return SubmitResponse::success($message, $url);
        } catch (\JsonException $e) {
            // Non-JSON response but still successful
            return SubmitResponse::success('Benchmark submitted successfully');
        }
    }
}
