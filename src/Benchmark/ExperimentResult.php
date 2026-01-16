<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

/**
 * Experiment result with unique ID and enhanced metadata
 *
 * Generates shareable benchmark IDs and formats results for dashboard compatibility.
 */
class ExperimentResult
{
    private const ID_PREFIX = 'kvs-';
    private const ID_LENGTH = 12;
    private const ID_CHARSET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private string $id;
    private string $timestamp;
    private BenchmarkResult $benchmarkResult;

    /** @var array<string, mixed> */
    private array $systemDetection;

    /** @var array<string, bool> */
    private array $confirmations = [];

    public function __construct(BenchmarkResult $result)
    {
        $this->id = $this->generateId();
        $this->timestamp = date('c');
        $this->benchmarkResult = $result;
        $this->systemDetection = [];
    }

    /**
     * Generate a unique experiment ID
     *
     * Format: kvs-XXXXXXXXXXXX (12 chars base62)
     * Total combinations: 62^12 = 3.2 × 10^21
     */
    private function generateId(): string
    {
        $id = '';
        $charsetLength = strlen(self::ID_CHARSET);

        for ($i = 0; $i < self::ID_LENGTH; $i++) {
            $id .= self::ID_CHARSET[random_int(0, $charsetLength - 1)];
        }

        return self::ID_PREFIX . $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTimestamp(): string
    {
        return $this->timestamp;
    }

    public function getBenchmarkResult(): BenchmarkResult
    {
        return $this->benchmarkResult;
    }

    /**
     * Set system detection results
     *
     * @param array<string, mixed> $detection
     */
    public function setSystemDetection(array $detection): void
    {
        $this->systemDetection = $detection;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystemDetection(): array
    {
        return $this->systemDetection;
    }

    /**
     * Set user confirmations for detected values
     *
     * @param array<string, bool> $confirmations
     */
    public function setConfirmations(array $confirmations): void
    {
        $this->confirmations = $confirmations;
    }

    /**
     * @return array<string, bool>
     */
    public function getConfirmations(): array
    {
        return $this->confirmations;
    }

    /**
     * Check if all detected values were confirmed
     */
    public function isFullyConfirmed(): bool
    {
        if ($this->confirmations === []) {
            return false;
        }

        foreach ($this->confirmations as $confirmed) {
            if (!$confirmed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Export to array for JSON serialization (dashboard-ready format)
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $benchData = $this->benchmarkResult->toArray();

        // Build system info from detection
        $systemInfo = [];

        if (isset($this->systemDetection['cpu']) && is_array($this->systemDetection['cpu'])) {
            $cpu = $this->systemDetection['cpu'];
            $systemInfo['cpu_vendor'] = (string) ($cpu['vendor'] ?? 'Unknown');
            $systemInfo['cpu_model'] = (string) ($cpu['model'] ?? 'Unknown');
            $systemInfo['cpu_generation'] = (string) ($cpu['generation'] ?? 'Unknown');
            $systemInfo['cpu_family'] = (string) ($cpu['family'] ?? 'Unknown');
            $systemInfo['cpu_cores'] = (int) ($cpu['cores'] ?? 1);
            $systemInfo['cpu_threads'] = (int) ($cpu['threads'] ?? 1);
        }

        if (isset($this->systemDetection['architecture']) && is_array($this->systemDetection['architecture'])) {
            $arch = $this->systemDetection['architecture'];
            $systemInfo['arch'] = (string) ($arch['name'] ?? php_uname('m'));
            $systemInfo['arch_bits'] = (int) ($arch['bits'] ?? 64);
            $systemInfo['arch_family'] = (string) ($arch['family'] ?? 'Unknown');
        }

        if (isset($this->systemDetection['device_type']) && is_array($this->systemDetection['device_type'])) {
            $device = $this->systemDetection['device_type'];
            $systemInfo['device_type'] = (string) ($device['type'] ?? 'unknown');
            $systemInfo['device_technology'] = (string) ($device['technology'] ?? '');
            $systemInfo['device_confidence'] = (string) ($device['confidence'] ?? 'low');
        }

        if (isset($this->systemDetection['storage']) && is_array($this->systemDetection['storage'])) {
            $storage = $this->systemDetection['storage'];
            $systemInfo['storage_type'] = (string) ($storage['type'] ?? 'unknown');
            $systemInfo['storage_device'] = (string) ($storage['device'] ?? '');
            $systemInfo['storage_confidence'] = (string) ($storage['confidence'] ?? 'low');
        }

        // Merge with existing system info from benchmark
        $existingInfo = $benchData['system_info'] ?? [];
        if (is_array($existingInfo)) {
            $systemInfo = array_merge($existingInfo, $systemInfo);
        }

        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'version' => '1.0',
            'score' => $this->benchmarkResult->calculateScore(),
            'rating' => $this->benchmarkResult->getRating(),
            'system' => $systemInfo,
            'confirmations' => $this->confirmations,
            'confirmed' => $this->isFullyConfirmed(),
            'results' => [
                'cpu' => $benchData['cpu_results'] ?? [],
                'database' => $benchData['db_results'] ?? [],
                'cache' => $benchData['cache_results'] ?? [],
                'fileio' => $benchData['fileio_results'] ?? [],
                'http' => $benchData['http_results'] ?? [],
            ],
            'metrics' => $benchData['system_metrics'] ?? [],
            'warnings' => $benchData['warnings'] ?? [],
            'total_time' => $benchData['total_time'] ?? 0,
        ];
    }

    /**
     * Get filename for export
     */
    public function getFilename(): string
    {
        return "benchmark-{$this->id}.json";
    }

    /**
     * Get short summary for display
     */
    public function getSummary(): string
    {
        $parts = [];

        // CPU
        if (isset($this->systemDetection['cpu']) && is_array($this->systemDetection['cpu'])) {
            $cpu = $this->systemDetection['cpu'];
            $cpuStr = (string) ($cpu['vendor'] ?? 'Unknown');
            $generation = $cpu['generation'] ?? 'Unknown';
            if ($generation !== 'Unknown') {
                $cpuStr .= ' ' . (string) $generation;
            }
            $parts[] = $cpuStr;
        }

        // Architecture
        if (isset($this->systemDetection['architecture']) && is_array($this->systemDetection['architecture'])) {
            $arch = $this->systemDetection['architecture'];
            if (isset($arch['name'])) {
                $parts[] = (string) $arch['name'];
            }
        }

        // Device type
        if (isset($this->systemDetection['device_type']) && is_array($this->systemDetection['device_type'])) {
            $deviceInfo = $this->systemDetection['device_type'];
            if (isset($deviceInfo['type'])) {
                $type = (string) $deviceInfo['type'];
                $tech = isset($deviceInfo['technology']) ? (string) $deviceInfo['technology'] : null;
                $deviceStr = match ($type) {
                    'vm' => 'VPS',
                    'container' => 'Container',
                    'bare_metal' => 'Bare Metal',
                    default => ucfirst($type),
                };
                if ($tech !== null && $tech !== '') {
                    $deviceStr .= " ({$tech})";
                }
                $parts[] = $deviceStr;
            }
        }

        // Storage
        if (isset($this->systemDetection['storage']) && is_array($this->systemDetection['storage'])) {
            $storageInfo = $this->systemDetection['storage'];
            if (isset($storageInfo['type'])) {
                $storageType = (string) $storageInfo['type'];
                if ($storageType !== 'unknown') {
                    $parts[] = strtoupper($storageType);
                }
            }
        }

        return implode(' | ', $parts);
    }
}
