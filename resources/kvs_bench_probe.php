<?php
/**
 * KVS Benchmark Probe
 *
 * Deploy this file to your web root to enable accurate OPcache/JIT detection.
 * The benchmark will automatically detect this file at:
 *   - /_kvs_bench_probe.php
 *   - /kvs_bench_probe.php
 *
 * SECURITY: Delete this file after benchmarking, or restrict access via .htaccess
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$info = [
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
];

// OPcache status
if (function_exists('opcache_get_status')) {
    $status = @opcache_get_status(false);
    $info['opcache'] = $status !== false && isset($status['opcache_enabled']) && $status['opcache_enabled'] === true;

    // JIT status (PHP 8.0+)
    if ($status !== false && isset($status['jit']['enabled'])) {
        $info['jit'] = $status['jit']['enabled'] === true;
        $info['jit_buffer_size'] = $status['jit']['buffer_size'] ?? 0;
    } else {
        $info['jit'] = false;
    }

    // OPcache config
    if (function_exists('opcache_get_configuration')) {
        $config = @opcache_get_configuration();
        if ($config !== false && isset($config['directives'])) {
            $info['opcache_memory'] = $config['directives']['opcache.memory_consumption'] ?? 0;
            $info['jit_mode'] = $config['directives']['opcache.jit'] ?? 'off';
        }
    }
} else {
    $info['opcache'] = false;
    $info['jit'] = false;
}

// Loaded extensions relevant for KVS
$relevantExtensions = ['memcached', 'redis', 'curl', 'gd', 'imagick', 'pdo_mysql', 'mysqli'];
$info['extensions'] = array_values(array_filter($relevantExtensions, 'extension_loaded'));

echo json_encode($info, JSON_PRETTY_PRINT);
