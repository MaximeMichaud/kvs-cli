#!/usr/bin/env php
<?php
/**
 * KVS-CLI Build Script
 * Builds PHAR from source files
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line');
}

// Check phar.readonly setting
if (ini_get('phar.readonly')) {
    die("Error: phar.readonly is enabled. Run with: php -d phar.readonly=0 build.php\n");
}

$sourceDir = __DIR__;
$outputFile = $sourceDir . '/kvs.phar';

echo "🔧 Building KVS CLI from source files\n";
echo "=====================================\n";

// Remove existing PHAR
if (file_exists($outputFile)) {
    unlink($outputFile);
    echo "🗑️  Removed existing kvs.phar\n";
}

// Create new PHAR
try {
    $phar = new Phar($outputFile);
    $phar->startBuffering();

    // Add source files
    echo "📁 Adding source files...\n";
    $phar->buildFromDirectory($sourceDir, '/\.(php|json)$/');

    // Add VERSION file
    $phar->addFile($sourceDir . '/VERSION', 'VERSION');

    // Set stub (entry point) - use Unix line endings
    $stub = "#!/usr/bin/env php\n" .
            "<?php\n" .
            "/**\n" .
            " * KVS CLI - Professional command line interface for KVS\n" .
            " */\n\n" .
            "Phar::mapPhar('kvs.phar');\n\n" .
            "require_once 'phar://kvs.phar/vendor/autoload.php';\n\n" .
            "use KVS\\CLI\\Application;\n\n" .
            "try {\n" .
            "    \$app = new Application();\n" .
            "    \$app->run();\n" .
            "} catch (Exception \$e) {\n" .
            "    fprintf(STDERR, \"Error: %s\\n\", \$e->getMessage());\n" .
            "    exit(1);\n" .
            "}\n\n" .
            "__HALT_COMPILER();";

    $phar->setStub($stub);
    $phar->stopBuffering();

    // Note: Compression options intentionally disabled.
    //
    // Gzip compression (~35% smaller) adds runtime overhead:
    // - Decompression on every execution
    // - Increased memory usage
    // - CPU load
    // Not ideal for CLI tools where startup time matters.
    // if (Phar::canCompress(Phar::GZ)) {
    //     $phar->compressFiles(Phar::GZ);
    // }
    //
    // php_strip_whitespace (~15% smaller) breaks debugging:
    // - Stack traces show wrong line numbers
    // - Error messages are harder to trace
    // Not worth the trade-off for minor size reduction.

    // Make executable
    chmod($outputFile, 0755);

    $size = round(filesize($outputFile) / 1024 / 1024, 2);
    echo "✅ Built kvs.phar ({$size}MB)\n";
    echo "\n";
    echo "🚀 To install globally:\n";
    echo "   sudo cp kvs.phar /usr/local/bin/kvs\n";
    echo "\n";
    echo "🧪 To test locally:\n";
    echo "   ./kvs.phar --version\n";
    echo "   ./kvs.phar --help\n";

} catch (Exception $e) {
    die("Build failed: " . $e->getMessage() . "\n");
}
