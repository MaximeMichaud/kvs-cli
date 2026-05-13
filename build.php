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

/**
 * @return list<string>|null
 */
function findComposerCommand(string $sourceDir): ?array
{
    $composerPhar = $sourceDir . '/composer.phar';
    if (is_file($composerPhar)) {
        return [PHP_BINARY, $composerPhar];
    }

    $composerPath = trim((string) shell_exec('command -v composer 2>/dev/null'));
    if ($composerPath !== '') {
        return [$composerPath];
    }

    return null;
}

/**
 * @param list<string> $composerCommand
 * @param list<string> $args
 */
function runComposerCommand(array $composerCommand, string $sourceDir, array $args): void
{
    $currentDir = getcwd();
    if ($currentDir === false) {
        throw new RuntimeException('Unable to resolve current working directory');
    }

    if (!chdir($sourceDir)) {
        throw new RuntimeException("Unable to change directory to $sourceDir");
    }

    $command = implode(' ', array_map('escapeshellarg', array_merge($composerCommand, $args)));
    passthru($command, $exitCode);

    if (!chdir($currentDir)) {
        throw new RuntimeException("Unable to restore working directory to $currentDir");
    }

    if ($exitCode !== 0) {
        throw new RuntimeException('Composer command failed: ' . implode(' ', $args));
    }
}

$sourceDir = __DIR__;
$outputFile = $sourceDir . '/kvs.phar';
$composerCommand = findComposerCommand($sourceDir);
$restoreComposerAutoload = false;
$buildException = null;

echo "🔧 Building KVS CLI from source files\n";
echo "=====================================\n";

// Remove existing PHAR
if (file_exists($outputFile)) {
    unlink($outputFile);
    echo "🗑️  Removed existing kvs.phar\n";
}

try {
    if (!is_dir($sourceDir . '/vendor/composer')) {
        throw new RuntimeException('Composer dependencies are not installed. Run composer install first.');
    }
    if ($composerCommand === null) {
        throw new RuntimeException('Composer is required to prepare a production PHAR autoloader.');
    }

    echo "📦 Preparing Composer autoloader (no-dev)...\n";
    runComposerCommand($composerCommand, $sourceDir, ['dump-autoload', '--no-dev', '--optimize']);
    $restoreComposerAutoload = true;

    // Create new PHAR
    $phar = new Phar($outputFile);
    $phar->startBuffering();

    // Add source files
    echo "📁 Adding source files...\n";
    $excludeVendorPackages = [];
    $composerLock = $sourceDir . '/composer.lock';
    if (is_file($composerLock)) {
        $lockData = json_decode((string) file_get_contents($composerLock), true);
        if (is_array($lockData)) {
            $prodPackages = [];
            foreach (($lockData['packages'] ?? []) as $package) {
                if (isset($package['name']) && is_string($package['name'])) {
                    $prodPackages[$package['name']] = true;
                }
            }
            foreach (($lockData['packages-dev'] ?? []) as $package) {
                if (!isset($package['name']) || !is_string($package['name']) || isset($prodPackages[$package['name']])) {
                    continue;
                }
                $excludeVendorPackages[] = 'vendor/' . $package['name'];
            }
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDir) + 1));
        $extension = strtolower($file->getExtension());
        if (!in_array($extension, ['php', 'json'], true)) {
            continue;
        }

        if (
            $relativePath === 'build.php'
            || $relativePath === 'kvs.phar'
            || str_starts_with($relativePath, 'tests/')
            || str_starts_with($relativePath, '.git/')
            || str_starts_with($relativePath, '.github/')
            || str_starts_with($relativePath, '.phpunit.cache/')
            || str_starts_with($relativePath, 'coverage-report/')
            || str_starts_with($relativePath, 'temp/')
            || str_starts_with($relativePath, 'tmp/')
        ) {
            continue;
        }

        $isDevPackage = false;
        foreach ($excludeVendorPackages as $packagePath) {
            if (str_starts_with($relativePath, $packagePath . '/')) {
                $isDevPackage = true;
                break;
            }
        }
        if ($isDevPackage) {
            continue;
        }

        $files[$file->getPathname()] = $relativePath;
    }

    foreach ($files as $sourceFile => $localName) {
        $phar->addFile($sourceFile, $localName);
    }

    // Add VERSION file
    $phar->addFile($sourceDir . '/VERSION', 'VERSION');

    // Set stub (entry point) - use Unix line endings
    $stub = "#!/usr/bin/env php\n" .
            "<?php\n" .
            "/**\n" .
            " * KVS CLI - Professional command line interface for KVS\n" .
            " */\n\n" .
            "// Check required extensions before autoload (polyfills may fail to parse)\n" .
            "\$required = ['mbstring' => 'Multibyte String', 'json' => 'JSON'];\n" .
            "\$missing = [];\n" .
            "foreach (\$required as \$ext => \$name) {\n" .
            "    if (!extension_loaded(\$ext)) {\n" .
            "        \$missing[] = \$ext;\n" .
            "    }\n" .
            "}\n" .
            "if (\$missing) {\n" .
            "    fprintf(STDERR, \"Error: Required PHP extensions missing: %s\\n\", implode(', ', \$missing));\n" .
            "    fprintf(STDERR, \"Install them and try again.\\n\");\n" .
            "    exit(1);\n" .
            "}\n\n" .
            "Phar::mapPhar('kvs.phar');\n\n" .
            "require_once 'phar://kvs.phar/vendor/autoload.php';\n\n" .
            "use KVS\\CLI\\Application;\n\n" .
            "try {\n" .
            "    \$app = new Application();\n" .
            "    exit(\$app->run());\n" .
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
    $buildException = $e;
} finally {
    if ($restoreComposerAutoload && $composerCommand !== null) {
        echo "♻️  Restoring Composer dev autoloader...\n";
        try {
            runComposerCommand($composerCommand, $sourceDir, ['dump-autoload', '--optimize']);
        } catch (Exception $e) {
            fwrite(STDERR, "Warning: failed to restore Composer autoloader: {$e->getMessage()}\n");
        }
    }
}

if ($buildException !== null) {
    die("Build failed: " . $buildException->getMessage() . "\n");
}
