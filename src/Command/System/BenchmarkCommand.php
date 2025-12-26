<?php

declare(strict_types=1);

namespace KVS\CLI\Command\System;

use KVS\CLI\Benchmark\BenchmarkResult;
use KVS\CLI\Benchmark\BenchmarkRunner;
use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function KVS\CLI\Utils\format_bytes;

#[AsCommand(
    name: 'system:benchmark',
    description: 'Run performance benchmarks (HTTP response times, DB queries, system metrics)',
    aliases: ['benchmark', 'bench']
)]
class BenchmarkCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'url',
                'u',
                InputOption::VALUE_REQUIRED,
                'Base URL for HTTP tests (e.g., https://example.com)'
            )
            ->addOption(
                'samples',
                's',
                InputOption::VALUE_REQUIRED,
                'Number of HTTP samples per endpoint',
                '5'
            )
            ->addOption(
                'db-iterations',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of iterations for DB tests',
                '10'
            )
            ->addOption(
                'cache-iterations',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of iterations for cache tests',
                '100'
            )
            ->addOption(
                'file-iterations',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of iterations for file I/O tests',
                '100'
            )
            ->addOption(
                'memcached-host',
                null,
                InputOption::VALUE_REQUIRED,
                'Memcached server host',
                '127.0.0.1'
            )
            ->addOption(
                'memcached-port',
                null,
                InputOption::VALUE_REQUIRED,
                'Memcached server port',
                '11211'
            )
            ->addOption(
                'tag',
                't',
                InputOption::VALUE_REQUIRED,
                'Tag/label for this benchmark run (e.g., "php81", "mariadb11")'
            )
            ->addOption(
                'export',
                'e',
                InputOption::VALUE_REQUIRED,
                'Export results to JSON file'
            )
            ->addOption(
                'compare',
                'c',
                InputOption::VALUE_REQUIRED,
                'Compare with baseline JSON file'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseUrl = $input->getOption('url');
        $baseUrl = is_string($baseUrl) ? $baseUrl : '';

        // Auto-detect URL from project config if not provided
        if ($baseUrl === '') {
            $projectUrl = $this->config->get('project_url');
            if (is_string($projectUrl) && $projectUrl !== '') {
                $baseUrl = $projectUrl;
            }
        }

        $samplesOption = $input->getOption('samples');
        $samples = is_numeric($samplesOption) ? (int)$samplesOption : 5;

        $dbIterOption = $input->getOption('db-iterations');
        $dbIterations = is_numeric($dbIterOption) ? (int)$dbIterOption : 10;

        $cacheIterOption = $input->getOption('cache-iterations');
        $cacheIterations = is_numeric($cacheIterOption) ? (int)$cacheIterOption : 100;

        $fileIterOption = $input->getOption('file-iterations');
        $fileIterations = is_numeric($fileIterOption) ? (int)$fileIterOption : 100;

        $mcHostOption = $input->getOption('memcached-host');
        $mcHost = $mcHostOption;

        $mcPortOption = $input->getOption('memcached-port');
        $mcPort = is_numeric($mcPortOption) ? (int)$mcPortOption : 11211;

        $tagOption = $input->getOption('tag');
        $tag = is_string($tagOption) ? $tagOption : '';

        $exportPath = $input->getOption('export');
        $comparePath = $input->getOption('compare');

        // Load baseline for comparison if provided
        $baseline = null;
        if (is_string($comparePath) && $comparePath !== '') {
            $baseline = $this->loadBaseline($comparePath);
            if ($baseline === null) {
                return self::FAILURE;
            }
        }

        $title = 'KVS Performance Benchmark';
        if ($tag !== '') {
            $title .= " [{$tag}]";
        }
        $this->io()->title($title);

        // Check requirements
        if ($baseUrl === '') {
            $this->io()->warning('No --url provided and project_url not found in config. HTTP tests will be skipped.');
            $this->io()->text('Usage: kvs benchmark --url=https://your-site.com');
            $this->io()->newLine();
        }

        $db = $this->getDatabaseConnection(true);
        if ($db === null) {
            $this->io()->warning('Database not available. DB tests will be skipped.');
        }

        // Run benchmarks
        $runner = new BenchmarkRunner(
            $db,
            $this->config->getTablePrefix(),
            $baseUrl,
            $samples,
            $dbIterations,
            ['host' => $mcHost, 'port' => $mcPort],
            $cacheIterations,
            $fileIterations
        );

        $result = $runner->run(function (string $stage, string $message): void {
            $this->io()->text("<comment>{$message}</comment>");
        });

        // Set tag if provided
        if ($tag !== '') {
            $result->setTag($tag);
        }

        // Display results
        $this->displayResults($result, $baseline);

        // Export if requested
        if (is_string($exportPath) && $exportPath !== '') {
            $this->exportResults($result, $exportPath);
        }

        return self::SUCCESS;
    }

    private function displayResults(BenchmarkResult $result, ?BenchmarkResult $baseline): void
    {
        // System info
        $this->io()->section('System Information');
        $this->displaySystemInfo($result);

        // HTTP results
        if ($result->hasHttpResults()) {
            $this->io()->section('HTTP Response Times');
            $this->displayHttpResults($result, $baseline);
        }

        // DB results
        if ($result->hasDbResults()) {
            $this->io()->section('Database Performance');
            $this->displayDbResults($result, $baseline);
        }

        // Cache results
        if ($result->hasCacheResults()) {
            $this->io()->section('Cache Performance (Memcached)');
            $this->displayCacheResults($result, $baseline);
        }

        // File I/O results
        if ($result->hasFileIOResults()) {
            $this->io()->section('File I/O Performance');
            $this->displayFileIOResults($result, $baseline);
        }

        // System metrics
        $this->io()->section('System Metrics');
        $this->displaySystemMetrics($result);

        // Summary
        $this->io()->section('Summary');
        $this->displaySummary($result, $baseline);
    }

    private function displaySystemInfo(BenchmarkResult $result): void
    {
        $info = $result->getSystemInfo();

        $rows = [];
        if (isset($info['os_name']) && is_string($info['os_name'])) {
            $rows[] = ['OS', $info['os_name']];
        } else {
            $osVal = $info['os'] ?? 'Unknown';
            $rows[] = ['OS', is_string($osVal) ? $osVal : 'Unknown'];
        }

        $phpVersion = $info['php_version'] ?? 'Unknown';
        $rows[] = ['PHP', is_string($phpVersion) ? $phpVersion : 'Unknown'];

        $opcache = isset($info['opcache']) && $info['opcache'] === true;
        $rows[] = ['OPcache', $opcache ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled</>'];

        $jit = isset($info['jit']) && $info['jit'] === true;
        $rows[] = ['JIT', $jit ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled</>'];

        // Database info
        if (isset($info['db_type']) && is_string($info['db_type'])) {
            $dbType = strtoupper($info['db_type']);
            $dbVersion = isset($info['db_version']) && is_string($info['db_version']) ? $info['db_version'] : 'Unknown';
            $rows[] = ['Database', "<fg=cyan>{$dbType}</> {$dbVersion}"];
        }

        // Memcached version
        if (isset($info['memcached_version']) && is_string($info['memcached_version'])) {
            $rows[] = ['Memcached', $info['memcached_version']];
        }

        // Extensions
        if (isset($info['extensions']) && is_array($info['extensions'])) {
            $extList = implode(', ', array_filter($info['extensions'], 'is_string'));
            if ($extList !== '') {
                $rows[] = ['Extensions', $extList];
            }
        }

        $hostname = $info['hostname'] ?? 'unknown';
        $rows[] = ['Hostname', is_string($hostname) ? $hostname : 'unknown'];

        $this->renderTable(['Parameter', 'Value'], $rows);
    }

    private function displayHttpResults(BenchmarkResult $result, ?BenchmarkResult $baseline): void
    {
        $httpResults = $result->getHttpResults();
        $baselineHttp = $baseline !== null ? $baseline->getHttpResults() : [];

        $headers = ['Endpoint', 'Avg', 'Min', 'Max', 'p50', 'p95', 'p99'];
        if ($baseline !== null) {
            $headers[] = 'vs Base';
        }

        $rows = [];
        foreach ($httpResults as $key => $data) {
            $row = [
                $data['name'],
                $this->formatMs($data['avg']),
                $this->formatMs($data['min']),
                $this->formatMs($data['max']),
                $this->formatMs($data['p50']),
                $this->formatMs($data['p95']),
                $this->formatMs($data['p99']),
            ];

            if ($baseline !== null && isset($baselineHttp[$key])) {
                $row[] = $this->formatComparison($data['avg'], $baselineHttp[$key]['avg'], true);
            } elseif ($baseline !== null) {
                $row[] = '<fg=gray>N/A</>';
            }

            $rows[] = $row;
        }

        $this->renderTable($headers, $rows);
        $this->io()->text('<fg=gray>All times in milliseconds. Lower is better.</>');
    }

    private function displayDbResults(BenchmarkResult $result, ?BenchmarkResult $baseline): void
    {
        $dbResults = $result->getDbResults();
        $baselineDb = $baseline !== null ? $baseline->getDbResults() : [];

        $headers = ['Query', 'Avg (ms)', 'Queries/sec'];
        if ($baseline !== null) {
            $headers[] = 'vs Base';
        }

        $rows = [];
        foreach ($dbResults as $key => $data) {
            $row = [
                $data['name'],
                $this->formatMs($data['avg_ms']),
                $this->formatNumber($data['queries_sec']),
            ];

            if ($baseline !== null && isset($baselineDb[$key])) {
                // For DB, compare queries/sec (higher is better)
                $row[] = $this->formatComparison($data['queries_sec'], $baselineDb[$key]['queries_sec'], false);
            } elseif ($baseline !== null) {
                $row[] = '<fg=gray>N/A</>';
            }

            $rows[] = $row;
        }

        $this->renderTable($headers, $rows);
    }

    private function displayCacheResults(BenchmarkResult $result, ?BenchmarkResult $baseline): void
    {
        $cacheResults = $result->getCacheResults();
        $baselineCache = $baseline !== null ? $baseline->getCacheResults() : [];

        $headers = ['Operation', 'Avg (ms)', 'p50', 'p95', 'Ops/sec'];
        if ($baseline !== null) {
            $headers[] = 'vs Base';
        }

        $rows = [];
        foreach ($cacheResults as $key => $data) {
            $row = [
                $data['name'],
                $this->formatMs($data['avg']),
                $this->formatMs($data['p50']),
                $this->formatMs($data['p95']),
                $this->formatNumber($data['ops_sec']),
            ];

            if ($baseline !== null && isset($baselineCache[$key])) {
                // Compare ops/sec (higher is better)
                $row[] = $this->formatComparison($data['ops_sec'], $baselineCache[$key]['ops_sec'], false);
            } elseif ($baseline !== null) {
                $row[] = '<fg=gray>N/A</>';
            }

            $rows[] = $row;
        }

        $this->renderTable($headers, $rows);
    }

    private function displayFileIOResults(BenchmarkResult $result, ?BenchmarkResult $baseline): void
    {
        $fileResults = $result->getFileIOResults();
        $baselineFile = $baseline !== null ? $baseline->getFileIOResults() : [];

        $headers = ['Operation', 'Avg (ms)', 'Min', 'Max', 'Ops/sec'];
        if ($baseline !== null) {
            $headers[] = 'vs Base';
        }

        $rows = [];
        foreach ($fileResults as $key => $data) {
            $row = [
                $data['name'],
                $this->formatMs($data['avg']),
                $this->formatMs($data['min']),
                $this->formatMs($data['max']),
                $this->formatNumber($data['ops_sec']),
            ];

            if ($baseline !== null && isset($baselineFile[$key])) {
                // Compare ops/sec (higher is better)
                $row[] = $this->formatComparison($data['ops_sec'], $baselineFile[$key]['ops_sec'], false);
            } elseif ($baseline !== null) {
                $row[] = '<fg=gray>N/A</>';
            }

            $rows[] = $row;
        }

        $this->renderTable($headers, $rows);
    }

    private function displaySystemMetrics(BenchmarkResult $result): void
    {
        $metrics = $result->getSystemMetrics();

        $rows = [];

        // Load average
        if (isset($metrics['load_1m']) && is_numeric($metrics['load_1m'])) {
            $load1 = (float)$metrics['load_1m'];
            $load5Val = $metrics['load_5m'] ?? 0;
            $load5 = is_numeric($load5Val) ? (float)$load5Val : 0.0;
            $load15Val = $metrics['load_15m'] ?? 0;
            $load15 = is_numeric($load15Val) ? (float)$load15Val : 0.0;
            $rows[] = ['Load Average', sprintf('%.2f / %.2f / %.2f', $load1, $load5, $load15)];
        }

        // Memory
        if (isset($metrics['memory_total'], $metrics['memory_used'])) {
            $total = is_int($metrics['memory_total']) ? $metrics['memory_total'] : 0;
            $used = is_int($metrics['memory_used']) ? $metrics['memory_used'] : 0;
            $percentVal = $metrics['memory_percent'] ?? 0;
            $percent = is_numeric($percentVal) ? (float)$percentVal : 0.0;
            $rows[] = ['Memory', sprintf('%s / %s (%.1f%%)', format_bytes($used), format_bytes($total), $percent)];
        }

        // CPU
        if (isset($metrics['cpu_model'], $metrics['cpu_cores'])) {
            $model = is_string($metrics['cpu_model']) ? $metrics['cpu_model'] : 'Unknown';
            $cores = is_int($metrics['cpu_cores']) ? $metrics['cpu_cores'] : 1;
            // Truncate long CPU names
            if (strlen($model) > 40) {
                $model = substr($model, 0, 37) . '...';
            }
            $rows[] = ['CPU', sprintf('%s (%d cores)', $model, $cores)];
        }

        if ($rows !== []) {
            $this->renderTable(['Metric', 'Value'], $rows);
        } else {
            $this->io()->text('<fg=yellow>System metrics not available</>');
        }
    }

    private function displaySummary(BenchmarkResult $result, ?BenchmarkResult $baseline): void
    {
        $rows = [
            ['Total Benchmark Time', sprintf('%.2f s', $result->getTotalTime())],
        ];

        $this->renderTable(['Metric', 'Value'], $rows);

        $this->io()->newLine();

        $score = $result->calculateScore();
        $rating = $result->getRating();

        if ($score > 0) {
            $this->io()->text(sprintf('  <fg=cyan;options=bold>SCORE: %s pts</>', number_format($score)));
            $this->io()->text(sprintf('  <fg=white>Rating: %s</>', $rating));

            if ($baseline !== null) {
                $baselineScore = $baseline->calculateScore();
                $this->displayScoreComparison($score, $baselineScore);
            }
        } else {
            $this->io()->text('  <fg=yellow>No HTTP tests run - cannot calculate score</>');
            $this->io()->text('  Use --url=https://your-site.com to enable HTTP benchmarks');
        }

        $this->io()->newLine();
    }

    private function displayScoreComparison(int $current, int $baseline): void
    {
        if ($baseline === 0) {
            return;
        }

        $diff = $current - $baseline;
        $percent = (($current / $baseline) - 1) * 100;

        $this->io()->newLine();

        if ($diff > 0) {
            $this->io()->text(sprintf(
                '  <fg=green>vs Baseline: +%s pts (+%.1f%% faster)</>',
                number_format($diff),
                $percent
            ));
        } elseif ($diff < 0) {
            $this->io()->text(sprintf(
                '  <fg=red>vs Baseline: %s pts (%.1f%% slower)</>',
                number_format($diff),
                $percent
            ));
        } else {
            $this->io()->text('  <fg=yellow>vs Baseline: Same performance</>');
        }
    }

    private function formatMs(float $ms): string
    {
        if ($ms < 1) {
            return sprintf('%.2f', $ms);
        } elseif ($ms < 100) {
            return sprintf('%.1f', $ms);
        } else {
            return sprintf('%.0f', $ms);
        }
    }

    private function formatNumber(float $num): string
    {
        if ($num >= 1000) {
            return sprintf('%.1fK', $num / 1000);
        }
        return sprintf('%.0f', $num);
    }

    /**
     * Format comparison (for latency: lower is better; for throughput: higher is better)
     */
    private function formatComparison(float $current, float $baseline, bool $lowerIsBetter): string
    {
        if ($baseline <= 0) {
            return '<fg=gray>N/A</>';
        }

        $percent = (($current / $baseline) - 1) * 100;

        // Invert for display if lower is better
        if ($lowerIsBetter) {
            $percent = -$percent;
        }

        if ($percent > 10) {
            return sprintf('<fg=green>+%.0f%%</>', $percent);
        } elseif ($percent > 0) {
            return sprintf('<fg=green>+%.1f%%</>', $percent);
        } elseif ($percent > -10) {
            return sprintf('<fg=yellow>%.1f%%</>', $percent);
        } else {
            return sprintf('<fg=red>%.0f%%</>', $percent);
        }
    }

    private function loadBaseline(string $path): ?BenchmarkResult
    {
        if (!file_exists($path)) {
            $this->io()->error("Baseline file not found: {$path}");
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            $this->io()->error("Failed to read baseline file: {$path}");
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \JsonException('Invalid JSON structure');
            }
            /** @var array<string, mixed> $data */
            return BenchmarkResult::fromArray($data);
        } catch (\JsonException $e) {
            $this->io()->error("Invalid JSON in baseline file: {$e->getMessage()}");
            return null;
        }
    }

    private function exportResults(BenchmarkResult $result, string $path): void
    {
        $json = json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json) === false) {
            $this->io()->error("Failed to write results to: {$path}");
            return;
        }

        $this->io()->success("Results exported to: {$path}");
    }
}
