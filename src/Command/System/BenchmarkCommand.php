<?php

declare(strict_types=1);

namespace KVS\CLI\Command\System;

use KVS\CLI\Benchmark\BenchmarkResult;
use KVS\CLI\Benchmark\BenchmarkRunner;
use KVS\CLI\Benchmark\ConfigScorer;
use KVS\CLI\Benchmark\ExperimentResult;
use KVS\CLI\Benchmark\RemoteBenchmarkClient;
use KVS\CLI\Benchmark\StackScorer;
use KVS\CLI\Benchmark\SystemDetector;
use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use KVS\CLI\Util\IonCubeDetector;
use KVS\CLI\Service\BenchmarkApiClient;
use KVS\CLI\Util\FpmConfigReader;
use KVS\CLI\Util\VersionChecker;
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
                'cpu-iterations',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of iterations for CPU tests',
                '1000'
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
                (string) Constants::DEFAULT_MEMCACHE_PORT
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
                InputOption::VALUE_OPTIONAL,
                'Export results to JSON file (auto-generates filename if none specified)',
                false
            )
            ->addOption(
                'compare',
                'c',
                InputOption::VALUE_REQUIRED,
                'Compare with baseline JSON file'
            )
            ->addOption(
                'php-container',
                null,
                InputOption::VALUE_REQUIRED,
                'Docker container name to fetch PHP-FPM config from (e.g., "kvs-php")'
            )
            ->addOption(
                'runs',
                'r',
                InputOption::VALUE_REQUIRED,
                'Number of benchmark runs for HTTP tests (averaged for stable scores)',
                '3'
            )
            ->addOption(
                'local',
                'l',
                InputOption::VALUE_NONE,
                'Test local server via 127.0.0.1 (bypass DNS, CDN, proxy)'
            )
            ->addOption(
                'localhost',
                null,
                InputOption::VALUE_NONE,
                'Alias for --local'
            )
            ->addOption(
                'submit',
                'S',
                InputOption::VALUE_NONE,
                'Submit results to the benchmark API'
            )
            ->addOption(
                'cli',
                null,
                InputOption::VALUE_NONE,
                'Force CLI execution (skip server-side FPM benchmarks)'
            )
            ->addOption(
                'remote-timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Timeout for server-side benchmark execution in seconds',
                '120'
            )
            ->addOption(
                'skip-version-check',
                null,
                InputOption::VALUE_NONE,
                'Skip checking for CLI updates (for CI/headless environments)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Check for CLI version updates (unless --skip-version-check is set)
        $skipVersionCheck = $input->getOption('skip-version-check');
        if ($skipVersionCheck !== true && !$this->checkVersion($input)) {
            return self::FAILURE;
        }

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
        $samples = is_numeric($samplesOption) ? max(1, (int)$samplesOption) : 5;

        $dbIterOption = $input->getOption('db-iterations');
        $dbIterations = is_numeric($dbIterOption) ? max(1, (int)$dbIterOption) : 10;

        $cacheIterOption = $input->getOption('cache-iterations');
        $cacheIterations = is_numeric($cacheIterOption) ? max(1, (int)$cacheIterOption) : 100;

        $fileIterOption = $input->getOption('file-iterations');
        $fileIterations = is_numeric($fileIterOption) ? max(1, (int)$fileIterOption) : 100;

        $cpuIterOption = $input->getOption('cpu-iterations');
        $cpuIterations = is_numeric($cpuIterOption) ? max(1, (int)$cpuIterOption) : 1000;

        $mcHostOption = $input->getOption('memcached-host');
        $mcHost = $mcHostOption;

        $mcPortOption = $input->getOption('memcached-port');
        $mcPort = is_numeric($mcPortOption) ? (int)$mcPortOption : Constants::DEFAULT_MEMCACHE_PORT;

        $tagOption = $input->getOption('tag');
        $tag = is_string($tagOption) ? $tagOption : '';

        $phpContainerOption = $input->getOption('php-container');
        $phpContainer = is_string($phpContainerOption) ? $phpContainerOption : '';

        $runsOption = $input->getOption('runs');
        $httpRuns = is_numeric($runsOption) ? max(1, (int)$runsOption) : 3;

        // --local or --localhost: test via 127.0.0.1 (bypass DNS/CDN)
        $useLocalhost = $input->getOption('local') === true || $input->getOption('localhost') === true;

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

        // Check for remote execution options
        $forceCli = $input->getOption('cli') === true;
        $remoteTimeoutOption = $input->getOption('remote-timeout');
        $remoteTimeout = is_numeric($remoteTimeoutOption) ? max(30, (int) $remoteTimeoutOption) : 120;

        // Run benchmarks
        $runner = new BenchmarkRunner(
            $db,
            $this->config->getTablePrefix(),
            $baseUrl,
            $samples,
            $dbIterations,
            ['host' => $mcHost, 'port' => $mcPort],
            $cacheIterations,
            $fileIterations,
            $cpuIterations,
            $httpRuns,
            $useLocalhost
        );

        // Enable remote execution if available and not forced to CLI
        $useRemote = false;
        if (!$forceCli && $baseUrl !== '') {
            $remoteClient = new RemoteBenchmarkClient($this->config);
            if ($remoteClient->isAvailable()) {
                $remoteClient->setIterations($cpuIterations, $cacheIterations, $fileIterations, $dbIterations);
                $remoteClient->setTimeout($remoteTimeout);
                $runner->setRemoteExecution($remoteClient);
                $useRemote = true;
                $this->io()->text('<info>Running server-side benchmarks (PHP-FPM)</info>');
                if ($useLocalhost) {
                    $this->io()->text('<info>Mode: Local server (127.0.0.1 - bypass DNS/CDN/proxy)</info>');
                }
                $this->io()->text('<comment>Use --cli to force CLI execution</comment>');
                $this->io()->newLine();
            }
        }

        if (!$useRemote) {
            $this->io()->text('<comment>Running CLI benchmarks (no web server)</comment>');
            if (!$forceCli) {
                $this->io()->text('<comment>Tip: Configure project_url in KVS for server-side benchmarks</comment>');
            }
            $this->io()->newLine();
        }

        $result = $runner->run(function (string $stage, string $message): void {
            $this->io()->text("<comment>{$message}</comment>");
        });

        // Set tag if provided
        if ($tag !== '') {
            $result->setTag($tag);
        }

        // Fetch PHP-FPM config from Docker container (auto-detect if not specified)
        if ($phpContainer === '') {
            $phpContainer = $this->autoDetectPhpContainer();
        }

        if ($phpContainer !== '') {
            $fpmInfo = $this->getPhpFpmInfoFromDocker($phpContainer);
            if ($fpmInfo !== []) {
                $systemInfo = $result->getSystemInfo();
                $systemInfo = array_merge($systemInfo, $fpmInfo);
                $systemInfo['source'] = "Docker ({$phpContainer})";
                $result->setSystemInfo($systemInfo);
            }
        }

        // System detection (CPU, architecture, device type, storage)
        $detector = new SystemDetector();
        $detection = $detector->detect();

        // Stack Score (software freshness - PHP, DB, OS versions vs EOL)
        $systemInfo = $result->getSystemInfo();
        $ioResults = $result->getFileIOResults();
        $stackScorer = new StackScorer($db, $systemInfo, $ioResults);
        $stackScore = $stackScorer->calculate();

        // IonCube detection (affects JIT compatibility)
        $ionCubeDetector = new IonCubeDetector($this->config);
        $ionCubeStatus = $ionCubeDetector->getStatus();
        $hasIonCube = $ionCubeStatus['files_encoded'];

        // Config Score (KVS configuration optimization)
        $configData = $this->collectConfigData();
        $configData['has_ioncube'] = $hasIonCube; // Pass ionCube status to scorer
        $configScorer = new ConfigScorer();
        $configScore = $configScorer->calculate($configData);

        // Add KVS and CLI versions to system info
        $systemInfo = $result->getSystemInfo();
        $systemInfo['kvs_cli_version'] = defined('KVS_CLI_VERSION') ? KVS_CLI_VERSION : 'unknown';
        $kvsVersion = $this->config->get('project_version');
        if (is_string($kvsVersion) && $kvsVersion !== '') {
            $systemInfo['kvs_version'] = $kvsVersion;
        }
        // Add KVS source type (source vs ionCube encoded)
        $systemInfo['kvs_source_type'] = $hasIonCube ? 'ioncube' : 'source';
        $result->setSystemInfo($systemInfo);

        // Create experiment result for ID and export
        $experiment = new ExperimentResult($result);
        $experiment->setSystemDetection($detection);

        // Calculate and set additional scores for API submission
        $cpuCores = 1;
        if (isset($detection['cpu']) && is_array($detection['cpu'])) {
            $cpuCores = max(1, (int) ($detection['cpu']['cores'] ?? 1));
        }
        $rawScore = $result->calculateScore();
        $efficiencyPerCore = $rawScore / $cpuCores;
        $baselineEfficiency = 250.0;
        $efficiencyScore = (int) round(($efficiencyPerCore / $baselineEfficiency) * 1000);

        $experiment->setEfficiencyScore($efficiencyScore);
        $experiment->setStackScore($stackScore);
        $experiment->setConfigScore($configScore);

        // Display results (show ID only if exporting or submitting)
        $willSubmit = $input->getOption('submit') === true;
        $willExport = $exportPath !== false;
        $showBenchmarkId = $willSubmit || $willExport;
        $this->displayResults($result, $baseline, $detection, $stackScore, $configScore, $experiment->getId(), $showBenchmarkId);

        // Export if requested
        if ($exportPath !== false) {
            $filename = (is_string($exportPath) && $exportPath !== '')
                ? $exportPath
                : $experiment->getFilename();

            $json = json_encode($experiment->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

            if (file_put_contents($filename, $json) !== false) {
                $this->io()->success("Results exported to: {$filename}");
            } else {
                $this->io()->warning("Failed to export results to: {$filename}");
            }
        }

        // Submit if requested
        if ($input->getOption('submit') === true) {
            $this->submitBenchmark($experiment, $exportPath !== false);
        }

        return self::SUCCESS;
    }

    /**
     * Submit benchmark results to the API.
     */
    private function submitBenchmark(ExperimentResult $experiment, bool $hasExport): void
    {
        // Check if API URL is configured
        // @phpstan-ignore identical.alwaysFalse (check remains for potential config changes)
        if (Constants::BENCHMARK_API_URL === '') {
            $this->io()->warning('Cannot submit: Benchmark API URL not configured.');
            if (!$hasExport) {
                $this->io()->text('Use --export to save results locally instead.');
            }
            return;
        }

        /** @phpstan-ignore-next-line deadCode.unreachable (code is reachable once API URL is set) */
        $this->io()->newLine();
        $this->io()->text('<comment>Submitting benchmark results to API...</comment>');

        $client = new BenchmarkApiClient();
        $response = $client->submit($experiment);

        if ($response->success) {
            if ($response->url !== null) {
                $this->io()->success('✓ Benchmark submitted successfully!');
                $this->io()->text('  View results: <fg=cyan;options=bold>' . $response->url . '</>');
            } else {
                $this->io()->success('✓ Benchmark submitted successfully!');
                $this->io()->text('<fg=yellow>Note: No dashboard URL provided by API</>');
            }
        } else {
            $this->io()->error('✗ Failed to submit benchmark to API');
            $this->io()->text("Reason: {$response->message}");

            if (!$hasExport) {
                $this->io()->newLine();
                $this->io()->text('<fg=yellow>Tip: Use --export to save results locally</>');
            }
        }
    }

    /**
     * @param array<string, mixed> $detection
     * @param array<string, mixed> $stackScore
     * @param array<string, mixed> $configScore
     */
    private function displayResults(
        BenchmarkResult $result,
        ?BenchmarkResult $baseline,
        array $detection,
        array $stackScore,
        array $configScore,
        string $benchmarkId,
        bool $showBenchmarkId = false
    ): void {
        // System Detection (hardware info)
        $this->io()->section('System Detection');
        $this->displayDetectedSystem($detection);

        // System info (software)
        $this->io()->section('System Information');
        $this->displaySystemInfo($result);

        // CPU results (most important for PHP version comparison)
        if ($result->hasCpuResults()) {
            $this->io()->section('CPU Performance');
            $this->displayCpuResults($result, $baseline);
        }

        // HTTP results
        if ($result->hasHttpResults()) {
            $httpRuns = $this->getHttpRunsFromResult($result);
            $isLocalhost = $this->isLocalhostMode($result);

            $httpTitle = 'HTTP Response Times';
            $extras = [];
            if ($httpRuns > 1) {
                $extras[] = "averaged over {$httpRuns} runs";
            }
            if ($isLocalhost) {
                $extras[] = 'via localhost';
            }
            if ($extras !== []) {
                $httpTitle .= ' (' . implode(', ', $extras) . ')';
            }

            $this->io()->section($httpTitle);
            $this->displayHttpResults($result, $baseline);
        }

        // DB results
        if ($result->hasDbResults()) {
            $this->io()->section('Database Performance');

            // Display warnings before DB results
            $this->displayWarnings($result, 'database');

            $this->displayDbResults($result, $baseline);
        }

        // Cache results
        if ($result->hasCacheResults()) {
            $cacheBackend = $this->getCacheBackendLabel($result);
            $this->io()->section("Cache Performance ({$cacheBackend})");
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

        // Stack Score (software freshness)
        $this->io()->section('Stack Score');
        $stackColor = $this->displayStackScoreSection($stackScore);

        // Config Score (KVS optimization)
        $this->io()->section('Config Score');
        $configColor = $this->displayConfigScoreSection($configScore);

        // Summary with all scores
        $this->io()->section('Summary');
        $this->displayFullSummary($result, $baseline, $detection, $stackScore, $configScore, $benchmarkId, $showBenchmarkId);
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

        // KVS source type (source vs ionCube encoded)
        $kvsSourceType = null;
        if (isset($info['kvs_source_type']) && is_string($info['kvs_source_type'])) {
            $kvsSourceType = $info['kvs_source_type'];
            $sourceDisplay = $kvsSourceType === 'ioncube'
                ? '<fg=yellow>Encoded (ionCube)</>'
                : '<fg=green>Source</>';
            $rows[] = ['KVS Type', $sourceDisplay];
        }

        // Check if info came from Docker FPM or local CLI
        $source = isset($info['source']) && is_string($info['source']) ? $info['source'] : '';
        $isFpmSource = $source !== '' && str_contains($source, 'Docker');
        $sourceLabel = $isFpmSource ? '' : ' (CLI)';

        $opcache = isset($info['opcache']) && $info['opcache'] === true;
        $opcacheStatus = $opcache ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled</>';
        $rows[] = ['OPcache' . $sourceLabel, $opcacheStatus];

        $jit = isset($info['jit']) && $info['jit'] === true;
        if ($jit) {
            $jitStatus = '<fg=green>Enabled</>';
        } else {
            // Explain why JIT is disabled if ionCube is present
            $jitStatus = $kvsSourceType === 'ioncube'
                ? '<fg=yellow>Disabled (ionCube)</>'
                : '<fg=yellow>Disabled</>';
        }
        $rows[] = ['JIT' . $sourceLabel, $jitStatus];

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

        // Add note about CLI vs FPM OPcache detection
        if (!$isFpmSource) {
            $this->io()->text(
                '<fg=gray>(CLI) = PHP CLI config. PHP-FPM may have different OPcache/JIT settings. '
                . 'Use --php-container for Docker-based detection.</>'
            );
        }
    }

    private function displayCpuResults(BenchmarkResult $result, ?BenchmarkResult $baseline): void
    {
        $cpuResults = $result->getCpuResults();
        $baselineCpu = $baseline !== null ? $baseline->getCpuResults() : [];

        // Group results by category
        $categories = [
            'Hashing (MD5)' => ['md5_simple', 'md5_session', 'md5_cache_key', 'md5_file_1kb', 'md5_file_100kb'],
            'Serialization' => ['serialize_config', 'serialize_lang', 'json_config', 'json_lang'],
            'String Ops' => ['str_replace', 'htmlspecialchars', 'concat', 'sprintf'],
            'Regex' => ['regex_routing', 'regex_content', 'regex_email'],
            'Math/Stats' => ['math_stats', 'math_sort', 'math_percentile'],
            'Arrays' => ['array_map', 'array_filter', 'array_column', 'array_merge', 'usort'],
        ];

        $headers = ['Operation', 'Avg (ms)', 'p50', 'p95', 'StdDev', 'Ops/sec'];
        if ($baseline !== null) {
            $headers[] = 'vs Base';
        }

        foreach ($categories as $categoryName => $keys) {
            $rows = [];
            foreach ($keys as $key) {
                if (!isset($cpuResults[$key])) {
                    continue;
                }
                $data = $cpuResults[$key];
                $row = [
                    $data['name'],
                    $this->formatMs($data['avg']),
                    $this->formatMs($data['p50']),
                    $this->formatMs($data['p95']),
                    $this->formatMs($data['std_dev']),
                    $this->formatNumber($data['ops_sec']),
                ];

                if ($baseline !== null && isset($baselineCpu[$key])) {
                    // Compare ops/sec (higher is better)
                    $row[] = $this->formatComparison($data['ops_sec'], $baselineCpu[$key]['ops_sec'], false);
                } elseif ($baseline !== null) {
                    $row[] = '<fg=gray>N/A</>';
                }

                $rows[] = $row;
            }

            if ($rows !== []) {
                $this->io()->text("<fg=cyan;options=bold>{$categoryName}</>");
                $this->renderTable($headers, $rows);
            }
        }

        $this->io()->text('<fg=gray>Using hrtime(true) for nanosecond precision. Warmup iterations excluded.</>');
    }

    private function displayHttpResults(BenchmarkResult $result, ?BenchmarkResult $baseline): void
    {
        $httpResults = $result->getHttpResults();
        $baselineHttp = $baseline !== null ? $baseline->getHttpResults() : [];

        $headers = ['Endpoint', 'Avg (ms)', 'Min', 'Max', 'p50', 'p95', 'Req/sec'];
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
                $this->formatNumber($data['req_sec']),
            ];

            if ($baseline !== null && isset($baselineHttp[$key])) {
                // Compare req/sec (higher is better)
                $row[] = $this->formatComparison($data['req_sec'], $baselineHttp[$key]['req_sec'], false);
            } elseif ($baseline !== null) {
                $row[] = '<fg=gray>N/A</>';
            }

            $rows[] = $row;
        }

        $this->renderTable($headers, $rows);
        $this->io()->text('<fg=gray>Latency in ms (lower = better). Req/sec = theoretical max throughput (higher = better).</>');
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
            if (strlen($model) > Constants::CPU_MODEL_TRUNCATE_LENGTH) {
                $model = substr($model, 0, Constants::CPU_MODEL_TRUNCATE_LENGTH - 3) . '...';
            }
            $rows[] = ['CPU', sprintf('%s (%d cores)', $model, $cores)];
        }

        if ($rows !== []) {
            $this->renderTable(['Metric', 'Value'], $rows);
        } else {
            $this->io()->text('<fg=yellow>System metrics not available</>');
        }
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

    /**
     * Display full summary with all scores
     *
     * @param array<string, mixed> $detection
     * @param array<string, mixed> $stackScore
     * @param array<string, mixed> $configScore
     */
    private function displayFullSummary(
        BenchmarkResult $result,
        ?BenchmarkResult $baseline,
        array $detection,
        array $stackScore,
        array $configScore,
        string $benchmarkId,
        bool $showBenchmarkId = false
    ): void {
        $rawScore = $result->calculateScore();
        $rating = $result->getRating();
        $hasInsufficientData = $result->hasWarnings();

        // Get CPU cores for efficiency calculation
        $cpuCores = 1;
        if (isset($detection['cpu']) && is_array($detection['cpu'])) {
            $cpuCores = max(1, (int) ($detection['cpu']['cores'] ?? 1));
        }

        // Calculate efficiency score (pts per vCPU)
        $efficiencyPerCore = $rawScore / $cpuCores;
        $baselineEfficiency = 250.0;
        $efficiencyScore = (int) round(($efficiencyPerCore / $baselineEfficiency) * 1000);

        $stackTotal = (int) ($stackScore['total'] ?? 0);
        $configTotal = (int) ($configScore['total'] ?? 0);

        // Basic info table
        $rows = [
            ['Total Benchmark Time', sprintf('%.2f s', $result->getTotalTime())],
        ];
        $this->renderTable(['Metric', 'Value'], $rows);

        // Summary box
        $separator = str_repeat('━', 65);
        $this->io()->newLine();
        $this->io()->writeln("<fg=cyan;options=bold>{$separator}</>");
        $this->io()->writeln('<fg=white;options=bold>  BENCHMARK SUMMARY</>');
        $this->io()->writeln("<fg=cyan;options=bold>{$separator}</>");
        $this->io()->newLine();

        // Raw Score
        if ($rawScore > 0) {
            if ($hasInsufficientData) {
                $this->io()->writeln(sprintf(
                    '  <fg=white>Raw Score:</>         <fg=yellow;options=bold>%s pts*</>  %s',
                    number_format($rawScore),
                    $rating
                ));
            } else {
                $this->io()->writeln(sprintf(
                    '  <fg=white>Raw Score:</>         <fg=cyan;options=bold>%s pts</>  %s',
                    number_format($rawScore),
                    $rating
                ));
            }
        }

        // Efficiency Score
        $effPts = str_pad(number_format($efficiencyScore) . ' pts', 7);
        $this->io()->writeln(sprintf(
            '  <fg=white>Efficiency Score:</>  <fg=cyan;options=bold>%s</>  %s',
            $effPts,
            $this->getEfficiencyMiniRating($efficiencyScore)
        ));

        // Stack Score
        $stackColor = $stackTotal >= 70 ? 'green' : ($stackTotal >= 40 ? 'yellow' : 'red');
        $this->io()->writeln(sprintf(
            '  <fg=white>Stack Score:</>       <fg=%s;options=bold>%3d/100</>  %s',
            $stackColor,
            $stackTotal,
            $this->getStackMiniRating($stackTotal)
        ));

        // Config Score
        $configColor = $configTotal >= 70 ? 'green' : ($configTotal >= 40 ? 'yellow' : 'red');
        $this->io()->writeln(sprintf(
            '  <fg=white>Config Score:</>      <fg=%s;options=bold>%3d/100</>  %s',
            $configColor,
            $configTotal,
            $this->getStackMiniRating($configTotal)
        ));

        // Only show Benchmark ID if exporting or submitting
        if ($showBenchmarkId) {
            $this->io()->newLine();
            $this->io()->writeln(sprintf('  <fg=white>Benchmark ID:</>      <fg=green;options=bold>%s</>', $benchmarkId));
        }

        // Baseline comparison
        if ($baseline !== null) {
            $baselineScore = $baseline->calculateScore();
            $this->displayScoreComparison($rawScore, $baselineScore);
        }

        $this->io()->newLine();
        $this->io()->writeln("<fg=cyan;options=bold>{$separator}</>");
        $this->io()->newLine();

        // Notes
        if ($hasInsufficientData) {
            $this->io()->text('<fg=yellow>* Score may be inflated due to insufficient database data</>');
        }
        $this->io()->text('<fg=gray>Efficiency Score: Performance normalized per CPU core (higher = better)</>');
        $this->io()->text('<fg=gray>Stack Score: Software stack quality (PHP, FFmpeg, DB, OS, web server, I/O)</>');
        $this->io()->text('<fg=gray>Config Score: KVS optimization level (100 = fully optimized)</>');
        $this->io()->newLine();
    }

    private function formatMs(float $ms): string
    {
        if ($ms < 0.001) {
            return sprintf('%.4f', $ms);
        } elseif ($ms < 0.01) {
            return sprintf('%.3f', $ms);
        } elseif ($ms < 1) {
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

    /**
     * Auto-detect PHP Docker container
     *
     * Tries common container names and returns the first one that exists.
     */
    private function autoDetectPhpContainer(): string
    {
        // Check if docker is available
        exec('which docker 2>/dev/null', $output, $returnCode);
        if ($returnCode !== 0) {
            return '';
        }

        // Common PHP container names to try
        $commonNames = ['kvs-php', 'php-fpm', 'php', 'web', 'app'];

        foreach ($commonNames as $name) {
            $cmd = sprintf('docker inspect %s 2>/dev/null', escapeshellarg($name));
            exec($cmd, $output, $returnCode);
            if ($returnCode === 0) {
                return $name;
            }
            $output = [];
        }

        return '';
    }

    /**
     * Get PHP-FPM configuration from a Docker container
     *
     * Uses php-fpm -i to get the actual FPM configuration, not CLI config.
     *
     * @return array<string, mixed>
     */
    private function getPhpFpmInfoFromDocker(string $container): array
    {
        // Check if docker command is available
        exec('which docker 2>/dev/null', $output, $returnCode);
        if ($returnCode !== 0) {
            $this->io()->warning('Docker command not found');
            return [];
        }

        // Get PHP version
        $cmd = sprintf('docker exec %s php -v 2>/dev/null | head -1', escapeshellarg($container));
        $versionOutput = [];
        exec($cmd, $versionOutput, $returnCode);

        $phpVersion = 'Unknown';
        if ($returnCode === 0 && isset($versionOutput[0])) {
            if (preg_match('/PHP\s+([0-9.]+)/', $versionOutput[0], $matches) === 1) {
                $phpVersion = $matches[1];
            }
        }

        // Get FPM configuration using php-fpm -i
        $cmd = sprintf('docker exec %s php-fpm -i 2>/dev/null', escapeshellarg($container));
        $output = [];
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || $output === []) {
            $this->io()->warning("Failed to get PHP-FPM info from container: {$container}");
            return [];
        }

        $info = [
            'php_version' => $phpVersion,
            'php_sapi' => 'fpm',
        ];

        $fpmConfig = implode("\n", $output);

        // Parse OPcache status
        if (preg_match('/^opcache\.enable\s+=>\s+(\w+)/m', $fpmConfig, $matches) === 1) {
            $info['opcache'] = strtolower($matches[1]) === 'on';
        }

        // Parse JIT status
        if (preg_match('/^opcache\.jit\s+=>\s+(\w+)/m', $fpmConfig, $matches) === 1) {
            $jitValue = strtolower($matches[1]);
            $info['jit'] = $jitValue !== 'off' && $jitValue !== 'disable' && $jitValue !== '0';
            $info['jit_mode'] = $matches[1];
        }

        // Parse memory limit
        if (preg_match('/^memory_limit\s+=>\s+([^\s]+)/m', $fpmConfig, $matches) === 1) {
            $info['memory_limit'] = $matches[1];
        }

        // Parse max execution time
        if (preg_match('/^max_execution_time\s+=>\s+(\d+)/m', $fpmConfig, $matches) === 1) {
            $info['max_execution_time'] = $matches[1];
        }

        return $info;
    }

    /**
     * Display warnings for a specific category
     */
    private function displayWarnings(BenchmarkResult $result, string $category): void
    {
        $warnings = $result->getWarnings();
        foreach ($warnings as $warning) {
            if ($warning['category'] === $category) {
                $this->io()->warning($warning['message']);
            }
        }

        // Also display data volume if available
        if ($category === 'database') {
            $dataVolume = $result->getDataVolume();
            if ($dataVolume !== []) {
                $volumeInfo = [];
                foreach ($dataVolume as $table => $count) {
                    $volumeInfo[] = sprintf('%s: %d rows', ucfirst($table), $count);
                }
                $this->io()->text('<fg=gray>Data Volume: ' . implode(', ', $volumeInfo) . '</>');
                $this->io()->newLine();
            }
        }
    }

    /**
     * Get number of HTTP runs from system info
     */
    private function getHttpRunsFromResult(BenchmarkResult $result): int
    {
        $info = $result->getSystemInfo();
        if (isset($info['http_runs']) && is_int($info['http_runs'])) {
            return $info['http_runs'];
        }
        return 1;
    }

    /**
     * Check if localhost mode was used for HTTP tests
     */
    private function isLocalhostMode(BenchmarkResult $result): bool
    {
        $info = $result->getSystemInfo();
        return isset($info['http_localhost']) && $info['http_localhost'] === true;
    }

    /**
     * Get cache backend label from system info
     */
    private function getCacheBackendLabel(BenchmarkResult $result): string
    {
        $info = $result->getSystemInfo();

        // Check if we have cache backend type stored
        if (isset($info['cache_backend']) && is_string($info['cache_backend'])) {
            $backend = $info['cache_backend'];
            if ($backend === 'dragonfly') {
                return 'Dragonfly';
            } elseif ($backend === 'memcached') {
                return 'Memcached';
            }
        }

        // Check version string for hints
        if (isset($info['memcached_version']) && is_string($info['memcached_version'])) {
            $version = $info['memcached_version'];
            if (stripos($version, 'dragonfly') !== false || stripos($version, 'df-') !== false) {
                return 'Dragonfly';
            }
        }

        // Default to "Object Cache" (generic)
        return 'Object Cache';
    }

    /**
     * Display detected system information
     *
     * @param array<string, mixed> $detection
     */
    private function displayDetectedSystem(array $detection): void
    {
        $rows = [];

        // CPU info
        if (isset($detection['cpu']) && is_array($detection['cpu'])) {
            $cpu = $detection['cpu'];
            $cpuStr = (string) ($cpu['vendor'] ?? 'Unknown');
            $cpuModel = (string) ($cpu['model'] ?? 'Unknown');
            if ($cpuModel !== 'Unknown') {
                // Truncate long model names
                if (strlen($cpuModel) > 50) {
                    $cpuModel = substr($cpuModel, 0, 47) . '...';
                }
                $cpuStr = $cpuModel;
            }
            $rows[] = ['CPU Model', $cpuStr];

            $cpuGen = (string) ($cpu['generation'] ?? 'Unknown');
            if ($cpuGen !== 'Unknown') {
                $rows[] = ['CPU Generation', "<fg=cyan>{$cpuGen}</>"];
            }

            $cores = (int) ($cpu['cores'] ?? 1);
            $threads = (int) ($cpu['threads'] ?? 1);
            $rows[] = ['CPU Cores/Threads', "{$cores} cores / {$threads} threads"];
        }

        // Architecture
        if (isset($detection['architecture']) && is_array($detection['architecture'])) {
            $arch = $detection['architecture'];
            $archStr = (string) ($arch['name'] ?? 'Unknown');
            $archBits = isset($arch['bits']) ? (int) $arch['bits'] : null;
            if ($archBits !== null) {
                $archStr .= " ({$archBits}-bit)";
            }
            $rows[] = ['Architecture', $archStr];
        }

        // Device type
        if (isset($detection['device_type']) && is_array($detection['device_type'])) {
            $deviceInfo = $detection['device_type'];
            $type = (string) ($deviceInfo['type'] ?? 'unknown');
            $tech = isset($deviceInfo['technology']) ? (string) $deviceInfo['technology'] : null;

            $typeLabel = match ($type) {
                'vm' => '<fg=yellow>Virtual Machine (VPS)</>',
                'container' => '<fg=blue>Container</>',
                'bare_metal' => '<fg=green>Bare Metal</>',
                default => ucfirst($type),
            };

            if ($tech !== null && $tech !== '') {
                $typeLabel .= " <fg=gray>({$tech})</>";
            }

            $rows[] = ['Device Type', $typeLabel];
        }

        // Storage
        if (isset($detection['storage']) && is_array($detection['storage'])) {
            $storageInfo = $detection['storage'];
            $type = (string) ($storageInfo['type'] ?? 'unknown');
            $storageDevice = isset($storageInfo['device']) ? (string) $storageInfo['device'] : null;

            $storageLabel = match ($type) {
                'nvme' => '<fg=green>NVMe SSD</>',
                'ssd' => '<fg=green>SSD</>',
                'hdd' => '<fg=yellow>HDD</>',
                'virtio' => '<fg=cyan>VirtIO (Virtual)</>',
                'xen' => '<fg=cyan>Xen (Virtual)</>',
                default => strtoupper($type),
            };

            if ($storageDevice !== null && $storageDevice !== '') {
                $storageLabel .= " <fg=gray>({$storageDevice})</>";
            }

            $rows[] = ['Storage Type', $storageLabel];
        }

        $this->renderTable(['Parameter', 'Detected Value'], $rows);
    }

    /**
     * Display stack score section
     *
     * @param array<string, mixed> $stackScore
     * @return string Color for stack score
     */
    private function displayStackScoreSection(array $stackScore): string
    {
        $this->io()->writeln('<fg=white;options=bold>STACK SCORE</> <fg=gray>(Software & Infrastructure)</>');
        $this->io()->newLine();

        $stackRows = $this->buildStackScoreRows($stackScore);
        $this->renderTable(['Component', 'Info', 'Status', 'Score', 'EOL Date'], $stackRows);

        $stackTotal = (int) ($stackScore['total'] ?? 0);
        $rating = (string) ($stackScore['rating'] ?? '');
        $stackColor = $stackTotal >= 70 ? 'green' : ($stackTotal >= 40 ? 'yellow' : 'red');
        $this->io()->writeln(sprintf(
            '  <fg=white;options=bold>Stack Score:</> <fg=%s;options=bold>%d/100</> %s',
            $stackColor,
            $stackTotal,
            $rating
        ));

        $this->displayStackRecommendations($stackScore);
        $this->io()->newLine();

        return $stackColor;
    }

    /**
     * Build stack score table rows
     *
     * @param array<string, mixed> $stackScore
     * @return list<list<string>>
     */
    private function buildStackScoreRows(array $stackScore): array
    {
        /** @var array<string, mixed> $php */
        $php = is_array($stackScore['php'] ?? null) ? $stackScore['php'] : [];
        /** @var array<string, mixed> $phpConfig */
        $phpConfig = is_array($stackScore['php_config'] ?? null) ? $stackScore['php_config'] : [];
        /** @var array<string, mixed> $ffmpeg */
        $ffmpeg = is_array($stackScore['ffmpeg'] ?? null) ? $stackScore['ffmpeg'] : [];
        /** @var array<string, mixed> $db */
        $db = is_array($stackScore['database'] ?? null) ? $stackScore['database'] : [];
        /** @var array<string, mixed> $os */
        $os = is_array($stackScore['os'] ?? null) ? $stackScore['os'] : [];
        /** @var array<string, mixed> $webServer */
        $webServer = is_array($stackScore['web_server'] ?? null) ? $stackScore['web_server'] : [];
        /** @var array<string, mixed> $storage */
        $storage = is_array($stackScore['storage_io'] ?? null) ? $stackScore['storage_io'] : [];

        return [
            $this->buildPhpRow($php),
            $this->buildPhpConfigRow($phpConfig),
            $this->buildFfmpegRow($ffmpeg),
            $this->buildDatabaseRow($db),
            $this->buildOsRow($os),
            $this->buildWebServerRow($webServer),
            $this->buildStorageRow($storage),
        ];
    }

    /**
     * @param array<string, mixed> $php
     * @return list<string>
     */
    private function buildPhpRow(array $php): array
    {
        return [
            'PHP Version',
            (string) ($php['version'] ?? 'Unknown'),
            StackScorer::getStatusLabel((string) ($php['status'] ?? 'unknown')),
            $this->formatStackScore((int) ($php['score'] ?? 0)),
            (string) ($php['eol_date'] ?? '-'),
        ];
    }

    /**
     * @param array<string, mixed> $phpConfig
     * @return list<string>
     */
    private function buildPhpConfigRow(array $phpConfig): array
    {
        $opcacheEnabled = isset($phpConfig['opcache']) && $phpConfig['opcache'] === true;
        $info = sprintf(
            'OPcache: %s | Memory: %s',
            $opcacheEnabled ? 'On' : 'Off',
            (string) ($phpConfig['memory_limit'] ?? 'unknown')
        );

        return ['PHP Config', $info, '', $this->formatStackScore((int) ($phpConfig['score'] ?? 0)), '-'];
    }

    /**
     * @param array<string, mixed> $ffmpeg
     * @return list<string>
     */
    private function buildFfmpegRow(array $ffmpeg): array
    {
        $installed = (bool) ($ffmpeg['installed'] ?? false);
        $status = $installed ? '<fg=green>Installed</>' : '<fg=red>Missing</>';

        return [
            'FFmpeg',
            (string) ($ffmpeg['version'] ?? 'not installed'),
            $status,
            $this->formatStackScore((int) ($ffmpeg['score'] ?? 0)),
            '-',
        ];
    }

    /**
     * @param array<string, mixed> $db
     * @return list<string>
     */
    private function buildDatabaseRow(array $db): array
    {
        return [
            'Database',
            (string) ($db['version'] ?? 'Unknown') . ' (' . (string) ($db['type'] ?? 'unknown') . ')',
            StackScorer::getStatusLabel((string) ($db['status'] ?? 'unknown')),
            $this->formatStackScore((int) ($db['score'] ?? 0)),
            (string) ($db['eol_date'] ?? '-'),
        ];
    }

    /**
     * @param array<string, mixed> $os
     * @return list<string>
     */
    private function buildOsRow(array $os): array
    {
        return [
            'OS',
            (string) ($os['name'] ?? 'Unknown') . ' ' . (string) ($os['version'] ?? 'Unknown'),
            StackScorer::getStatusLabel((string) ($os['status'] ?? 'unknown')),
            $this->formatStackScore((int) ($os['score'] ?? 0)),
            (string) ($os['eol_date'] ?? '-'),
        ];
    }

    /**
     * @param array<string, mixed> $webServer
     * @return list<string>
     */
    private function buildWebServerRow(array $webServer): array
    {
        return [
            'Web Server',
            (string) ($webServer['name'] ?? 'Unknown'),
            '',
            $this->formatStackScore((int) ($webServer['score'] ?? 0)),
            '-',
        ];
    }

    /**
     * @param array<string, mixed> $storage
     * @return list<string>
     */
    private function buildStorageRow(array $storage): array
    {
        $speed = (float) ($storage['write_speed'] ?? 0);
        $info = $speed > 0 ? sprintf('%.0f MB/s write', $speed) : 'N/A';

        return ['Storage I/O', $info, '', $this->formatStackScore((int) ($storage['score'] ?? 0)), '-'];
    }

    /**
     * Display stack score recommendations
     *
     * @param array<string, mixed> $stackScore
     */
    private function displayStackRecommendations(array $stackScore): void
    {
        $recs = $stackScore['recommendations'] ?? [];
        /** @var list<string> $recommendations */
        $recommendations = is_array($recs) ? $recs : [];

        if ($recommendations !== []) {
            $this->io()->newLine();
            $this->io()->text('<fg=cyan;options=bold>Recommendations:</>');
            foreach ($recommendations as $recommendation) {
                $this->io()->text(sprintf('  → %s', $recommendation));
            }
        }
    }

    /**
     * Display config score section
     *
     * @param array<string, mixed> $configScore
     * @return string Color for config score
     */
    private function displayConfigScoreSection(array $configScore): string
    {
        $this->io()->writeln('<fg=white;options=bold>CONFIG SCORE</> <fg=gray>(KVS Optimization)</>');
        $this->io()->newLine();

        /** @var array<string, mixed> $phpSettings */
        $phpSettings = is_array($configScore['php_settings'] ?? null) ? $configScore['php_settings'] : [];
        /** @var array<string, mixed> $opcache */
        $opcache = is_array($configScore['opcache'] ?? null) ? $configScore['opcache'] : [];
        /** @var array<string, mixed> $cache */
        $cache = is_array($configScore['cache'] ?? null) ? $configScore['cache'] : [];

        $configRows = [
            [
                'PHP Settings',
                ConfigScorer::getScoreLabel((int) ($phpSettings['score'] ?? 0), (int) ($phpSettings['max'] ?? 1)),
                $this->formatConfigIssues($phpSettings),
            ],
            [
                'OPcache',
                ConfigScorer::getScoreLabel((int) ($opcache['score'] ?? 0), (int) ($opcache['max'] ?? 1)),
                $this->formatConfigIssues($opcache),
            ],
            [
                'Cache Memory',
                ConfigScorer::getScoreLabel((int) ($cache['score'] ?? 0), (int) ($cache['max'] ?? 1)),
                $this->formatConfigIssues($cache),
            ],
        ];

        $this->renderTable(['Component', 'Score', 'Status'], $configRows);

        $configTotal = (int) ($configScore['total'] ?? 0);
        $configColor = $configTotal >= 70 ? 'green' : ($configTotal >= 40 ? 'yellow' : 'red');
        $rating = (string) ($configScore['rating'] ?? '');

        $this->io()->writeln(sprintf(
            '  <fg=white;options=bold>Config Score:</> <fg=%s;options=bold>%d/100</> %s',
            $configColor,
            $configTotal,
            $rating
        ));

        // Display recommendations if any
        $recommendations = $this->collectConfigRecommendations($configScore);
        if ($recommendations !== []) {
            $this->io()->newLine();
            $this->io()->text('<fg=cyan;options=bold>Recommendations:</>');
            foreach (array_slice($recommendations, 0, 5) as $recommendation) {
                $this->io()->text(sprintf('  → %s', $recommendation));
            }
        }

        $this->io()->newLine();

        return $configColor;
    }

    /**
     * Format config issues for display
     *
     * @param array<string, mixed> $section
     */
    private function formatConfigIssues(array $section): string
    {
        $score = (int) ($section['score'] ?? 0);
        $max = (int) ($section['max'] ?? 1);

        if ($score === $max) {
            return '<fg=green>OK</>';
        }

        /** @var list<string> $issues */
        $issues = is_array($section['issues'] ?? null) ? $section['issues'] : [];
        if (count($issues) > 0) {
            $firstIssue = $issues[0];
            if (strlen($firstIssue) > 40) {
                $firstIssue = substr($firstIssue, 0, 37) . '...';
            }
            return '<fg=yellow>' . $firstIssue . '</>';
        }

        return '<fg=yellow>Needs optimization</>';
    }

    /**
     * Collect recommendations from all config sections
     *
     * @param array<string, mixed> $configScore
     * @return list<string>
     */
    private function collectConfigRecommendations(array $configScore): array
    {
        $recommendations = [];

        foreach (['php_settings', 'opcache', 'cache'] as $section) {
            if (isset($configScore[$section]) && is_array($configScore[$section])) {
                $recs = $configScore[$section]['recommendations'] ?? [];
                if (is_array($recs)) {
                    foreach ($recs as $rec) {
                        if (is_string($rec) && $rec !== '') {
                            $recommendations[] = $rec;
                        }
                    }
                }
            }
        }

        return $recommendations;
    }

    /**
     * Collect config data for scoring
     *
     * Uses FpmConfigReader to get real PHP-FPM settings (not CLI values).
     *
     * @return array{
     *     php_settings: array<string, string|int>,
     *     opcache?: array{enabled: bool, memory_mb: int, strings_mb: int},
     *     cache?: array{connected: bool, memory_mb: int|null, type: string}
     * }
     */
    private function collectConfigData(): array
    {
        // Use FpmConfigReader to get real PHP-FPM settings
        $docker = $this->isDockerMode() ? $this->docker() : null;
        $fpmReader = new FpmConfigReader($this->config, $docker);
        $fpmConfig = $fpmReader->getConfig();

        // Map FpmConfigReader response to ConfigScorer format
        $phpSettings = [
            'upload_max_filesize' => $fpmConfig['upload_max_filesize'],
            'post_max_size' => $fpmConfig['post_max_size'],
            'memory_limit' => $fpmConfig['memory_limit'],
            'max_execution_time' => $fpmConfig['max_execution_time'],
        ];

        // OPcache from FPM config
        $opcache = null;
        if (isset($fpmConfig['opcache'])) {
            $oc = $fpmConfig['opcache'];
            $opcache = [
                'enabled' => $oc['enable'],
                'memory_mb' => $oc['memory_consumption'],
                'strings_mb' => $oc['interned_strings_buffer'],
            ];
        }

        // Cache (Memcached/Dragonfly)
        $cache = null;
        $serverValue = $this->config->get('memcache_server', '127.0.0.1');
        $server = is_string($serverValue) ? $serverValue : '127.0.0.1';
        $portValue = $this->config->get('memcache_port', Constants::DEFAULT_MEMCACHE_PORT);
        $port = is_int($portValue) ? $portValue : Constants::DEFAULT_MEMCACHE_PORT;

        if ($server !== '') {
            $cacheType = 'Memcached';
            $memoryMb = null;

            // Try Docker cache check first
            if ($this->isDockerMode()) {
                $cacheInfo = $this->docker()->checkCache();
                if ($cacheInfo['available']) {
                    $memoryMb = $cacheInfo['memory_mb'];
                    if ($cacheInfo['type'] !== null) {
                        $cacheType = $cacheInfo['type'];
                    }
                }
            } else {
                // Try direct connection
                $memoryMb = $this->getMemcachedMemory($server, $port);
            }

            $cache = [
                'connected' => $memoryMb !== null,
                'memory_mb' => $memoryMb,
                'type' => $cacheType,
            ];
        }

        $result = ['php_settings' => $phpSettings];
        if ($opcache !== null) {
            $result['opcache'] = $opcache;
        }
        if ($cache !== null) {
            $result['cache'] = $cache;
        }

        return $result;
    }

    /**
     * Get memcached memory (simplified version for benchmark)
     */
    private function getMemcachedMemory(string $server, int $port): ?int
    {
        if (class_exists('Memcached')) {
            try {
                $m = new \Memcached();
                $m->addServer($server, $port);
                $stats = $m->getStats();
                $key = "$server:$port";
                if (isset($stats[$key]) && is_array($stats[$key]) && isset($stats[$key]['limit_maxbytes'])) {
                    $limitMaxbytes = $stats[$key]['limit_maxbytes'];
                    if (is_numeric($limitMaxbytes)) {
                        return (int) ((float) $limitMaxbytes / 1024 / 1024);
                    }
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }

        // Try raw socket
        $fp = @fsockopen($server, $port, $errno, $errstr, 2);
        if ($fp === false) {
            return null;
        }

        fwrite($fp, "stats\r\n");
        $response = '';
        while (!feof($fp)) {
            $line = fgets($fp, 256);
            if ($line !== false) {
                $response .= $line;
                if (trim($line) === 'END') {
                    break;
                }
            }
        }
        fclose($fp);

        if (preg_match('/STAT limit_maxbytes (\d+)/', $response, $matches) === 1) {
            return (int) ((int) $matches[1] / 1024 / 1024);
        }

        return null;
    }

    /**
     * Format stack component score with color
     */
    private function formatStackScore(int $score): string
    {
        $color = $score >= 70 ? 'green' : ($score >= 40 ? 'yellow' : 'red');
        return sprintf('<fg=%s>%d</>', $color, $score);
    }

    /**
     * Get mini rating for stack score (for summary box)
     */
    private function getStackMiniRating(int $score): string
    {
        if ($score >= 90) {
            return '★★★★★ Excellent';
        } elseif ($score >= 70) {
            return '★★★★☆ Good';
        } elseif ($score >= 50) {
            return '★★★☆☆ Fair';
        } elseif ($score >= 30) {
            return '★★☆☆☆ Outdated';
        }
        return '★☆☆☆☆ Critical';
    }

    /**
     * Get mini rating for efficiency score (for summary box)
     */
    private function getEfficiencyMiniRating(int $score): string
    {
        if ($score >= 1500) {
            return '★★★★★ Excellent';
        } elseif ($score >= 1200) {
            return '★★★★☆ Very Good';
        } elseif ($score >= 900) {
            return '★★★☆☆ Good';
        } elseif ($score >= 600) {
            return '★★☆☆☆ Fair';
        }
        return '★☆☆☆☆ Needs Improvement';
    }

    /**
     * Check if CLI version is up to date.
     *
     * @return bool True to continue, False to abort
     */
    private function checkVersion(InputInterface $input): bool
    {
        $checker = new VersionChecker();
        $result = $checker->check();

        if ($result['is_latest']) {
            // Up to date, continue silently
            return true;
        }

        // Outdated version detected
        $this->io()->warning(sprintf(
            'Your KVS CLI version (%s) is outdated. Latest version: %s',
            $result['current'],
            $result['latest'] ?? 'unknown'
        ));

        // Check if we're in interactive mode
        if (!$input->isInteractive()) {
            // Non-interactive mode: warn but continue
            $this->io()->text('Continuing with outdated version (non-interactive mode).');
            $this->io()->text('Update command: kvs self-update');
            return true;
        }

        // Interactive mode: ask for confirmation
        $this->io()->text('Benchmark results from outdated versions may not be accurate.');
        $this->io()->text('Update command: kvs self-update');

        $continue = $this->io()->confirm('Do you want to continue anyway?', false);

        if (!$continue) {
            $this->io()->info('Benchmark cancelled. Please update and try again.');
            return false;
        }

        return true;
    }
}
