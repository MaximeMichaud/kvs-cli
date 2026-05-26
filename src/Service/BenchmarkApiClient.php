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
    private string $apiUrl;

    public function __construct(?string $apiUrl = null)
    {
        $this->apiUrl = $apiUrl ?? self::getConfiguredApiUrl();
    }

    public static function getConfiguredApiUrl(): string
    {
        $envUrl = getenv('KVS_BENCHMARK_API_URL');
        if (is_string($envUrl)) {
            return trim($envUrl);
        }

        return Constants::BENCHMARK_API_URL;
    }

    /**
     * Submit benchmark results to the API.
     */
    public function submit(ExperimentResult $result): SubmitResponse
    {
        $url = $this->apiUrl;

        if ($url === '') {
            return SubmitResponse::error('Benchmark API URL not configured');
        }

        $payload = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
        $applicationVersion = Application::VERSION;

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'User-Agent: ' . Application::NAME . '/' . $applicationVersion,
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
        // @phpstan-ignore nullCoalesce.variable ($http_response_header is set by file_get_contents)
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
     */
    private function parseSuccessResponse(string $response, string $benchmarkId): SubmitResponse
    {
        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                // Fallback: construct URL from benchmark ID
                $fallbackUrl = $this->constructDashboardUrl($benchmarkId);
                return SubmitResponse::success('Benchmark submitted successfully', $fallbackUrl);
            }

            $url = null;
            if (isset($data['url']) && is_string($data['url'])) {
                $url = $data['url'];
            } elseif (isset($data['dashboard_url']) && is_string($data['dashboard_url'])) {
                $url = $data['dashboard_url'];
            } elseif (isset($data['id']) && is_string($data['id'])) {
                // API returned an ID, construct URL from it
                $url = $this->constructDashboardUrl($data['id']);
            }

            // If still no URL, use benchmark ID as fallback
            if ($url === null) {
                $url = $this->constructDashboardUrl($benchmarkId);
            }

            $message = 'Benchmark submitted successfully';
            if (isset($data['message']) && is_string($data['message'])) {
                $message = $data['message'];
            }

            return SubmitResponse::success($message, $url);
        } catch (\JsonException $e) {
            // Non-JSON response but still successful - construct URL from ID
            $fallbackUrl = $this->constructDashboardUrl($benchmarkId);
            return SubmitResponse::success('Benchmark submitted successfully', $fallbackUrl);
        }
    }

    /**
     * Construct dashboard URL from benchmark ID.
     */
    private function constructDashboardUrl(string $benchmarkId): string
    {
        // Extract base URL from API URL
        $apiUrl = $this->apiUrl;
        $baseUrl = preg_replace('#/api/.*$#', '', $apiUrl);

        if ($baseUrl === null || $baseUrl === '') {
            $baseUrl = $apiUrl;
        }

        return $baseUrl . '/' . $benchmarkId;
    }
}
