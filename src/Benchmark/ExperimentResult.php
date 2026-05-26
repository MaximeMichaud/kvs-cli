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
    private const ID_PREFIX = '';
    private const ID_LENGTH = 12;
    private const ID_CHARSET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private string $id;
    private string $timestamp;
    private BenchmarkResult $benchmarkResult;

    /** @var array<string, mixed> */
    private array $systemDetection;

    /** @var array<string, bool> */
    private array $confirmations = [];

    private ?int $efficiencyScore = null;

    /** @var array<string, mixed> */
    private array $stackScore = [];

    /** @var array<string, mixed> */
    private array $configScore = [];

    private ?string $commandLine = null;

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
     * Format: XXXXXXXXXXXX (12 chars base62)
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
     * Set efficiency score (performance per CPU core)
     */
    public function setEfficiencyScore(int $score): void
    {
        $this->efficiencyScore = $score;
    }

    /**
     * Set stack score (software freshness)
     *
     * @param array<string, mixed> $score
     */
    public function setStackScore(array $score): void
    {
        $this->stackScore = $score;
    }

    /**
     * Set config score (KVS optimization)
     *
     * @param array<string, mixed> $score
     */
    public function setConfigScore(array $score): void
    {
        $this->configScore = $score;
    }

    /**
     * Set the command line used to run the benchmark
     */
    public function setCommandLine(string $commandLine): void
    {
        $this->commandLine = $commandLine;
    }

    /**
     * Export to array for JSON serialization (dashboard-ready format)
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $benchData = $this->benchmarkResult->toArray();
        $systemInfo = $this->buildSystemInfo($benchData);

        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'version' => '1.0',
            'score' => $this->benchmarkResult->calculateScore(),
            'rating' => $this->benchmarkResult->getRating(),
            'efficiency_score' => $this->efficiencyScore,
            'stack_score' => $this->arrayOrNull($this->stackScore),
            'config_score' => $this->arrayOrNull($this->configScore),
            'system' => $systemInfo,
            'confirmations' => $this->arrayOrObject($this->confirmations),
            'confirmed' => $this->isFullyConfirmed(),
            'results' => $this->buildResults($benchData),
            'metrics' => $this->arrayOrObject($benchData['system_metrics'] ?? []),
            'warnings' => $benchData['warnings'] ?? [],
            'total_time' => $benchData['total_time'] ?? 0,
            'command_line' => $this->commandLine,
        ];
    }

    /**
     * @param array<string, mixed> $benchData
     * @return array<string, mixed>
     */
    private function buildResults(array $benchData): array
    {
        return [
            'cpu' => $this->arrayOrObject($benchData['cpu_results'] ?? []),
            'database' => $this->arrayOrObject($benchData['db_results'] ?? []),
            'cache' => $this->arrayOrObject($benchData['cache_results'] ?? []),
            'fileio' => $this->arrayOrObject($benchData['fileio_results'] ?? []),
            'http' => $this->arrayOrObject($benchData['http_results'] ?? []),
            'weights' => $benchData['weights'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>|null
     */
    private function arrayOrNull(array $value): ?array
    {
        return $value !== [] ? $value : null;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function arrayOrObject(mixed $value): mixed
    {
        return is_array($value) && $value !== [] ? $value : new \stdClass();
    }

    /**
     * @param array<string, mixed> $benchData
     * @return array<string, mixed>
     */
    private function buildSystemInfo(array $benchData): array
    {
        $systemInfo = array_merge(
            $this->buildCpuSystemInfo(),
            $this->buildArchitectureSystemInfo(),
            $this->buildDeviceTypeSystemInfo(),
            $this->buildStorageSystemInfo()
        );

        $existingInfo = $benchData['system_info'] ?? [];
        if (is_array($existingInfo)) {
            return array_merge($this->stringKeyedArray($existingInfo), $systemInfo);
        }

        return $systemInfo;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCpuSystemInfo(): array
    {
        $cpu = $this->getDetectionSection('cpu');
        if ($cpu === []) {
            return [];
        }

        return [
            'cpu_vendor' => (string) ($cpu['vendor'] ?? 'Unknown'),
            'cpu_model' => (string) ($cpu['model'] ?? 'Unknown'),
            'cpu_generation' => (string) ($cpu['generation'] ?? 'Unknown'),
            'cpu_family' => (string) ($cpu['family'] ?? 'Unknown'),
            'cpu_cores' => (int) ($cpu['cores'] ?? 1),
            'cpu_threads' => (int) ($cpu['threads'] ?? 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildArchitectureSystemInfo(): array
    {
        $arch = $this->getDetectionSection('architecture');
        if ($arch === []) {
            return [];
        }

        return [
            'arch' => (string) ($arch['name'] ?? php_uname('m')),
            'arch_bits' => (int) ($arch['bits'] ?? 64),
            'arch_family' => (string) ($arch['family'] ?? 'Unknown'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDeviceTypeSystemInfo(): array
    {
        $device = $this->getDetectionSection('device_type');
        if ($device === []) {
            return [];
        }

        return [
            'device_type' => (string) ($device['type'] ?? 'unknown'),
            'device_technology' => (string) ($device['technology'] ?? ''),
            'device_confidence' => (string) ($device['confidence'] ?? 'low'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStorageSystemInfo(): array
    {
        $storage = $this->getDetectionSection('storage');
        if ($storage === []) {
            return [];
        }

        return [
            'storage_type' => (string) ($storage['type'] ?? 'unknown'),
            'storage_device' => (string) ($storage['device'] ?? ''),
            'storage_confidence' => (string) ($storage['confidence'] ?? 'low'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getDetectionSection(string $name): array
    {
        $section = $this->systemDetection[$name] ?? [];

        if (!is_array($section)) {
            return [];
        }

        return $this->stringKeyedArray($section);
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
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
