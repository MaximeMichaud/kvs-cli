# Building

This guide covers building KVS-CLI as a PHAR archive.

## Quick Build

```bash
# Build PHAR
php -d phar.readonly=0 build.php

# Verify
./kvs.phar --version
```

## Requirements

- PHP 8.1+
- `phar.readonly=0` (either in php.ini or via -d flag)
- All dependencies installed (`composer install`)

## Build Script

The `build.php` script:

```php
<?php

// Check phar.readonly
if (ini_get('phar.readonly')) {
    die("Error: phar.readonly must be disabled.\n" .
        "Run with: php -d phar.readonly=0 build.php\n");
}

$outputFile = __DIR__ . '/kvs.phar';
$sourceDir = __DIR__;

// Remove existing PHAR
if (file_exists($outputFile)) {
    unlink($outputFile);
}

echo "Building KVS-CLI PHAR...\n";

// Create PHAR
$phar = new Phar($outputFile);
$phar->startBuffering();

// Add source files
$phar->buildFromDirectory($sourceDir, '/\.(php|json)$/');

// Add VERSION file
$phar->addFile($sourceDir . '/VERSION', 'VERSION');

// Set stub
$stub = <<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('kvs.phar');
require 'phar://kvs.phar/bin/kvs';
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

// Make executable
chmod($outputFile, 0755);

// Show result
$size = filesize($outputFile);
echo sprintf("Built: %s (%.2f MB)\n", $outputFile, $size / 1024 / 1024);
```

## Build Options

### Development Build

```bash
# With all dependencies
composer install
php -d phar.readonly=0 build.php
```

### Production Build

```bash
# Without dev dependencies, optimized
composer install --no-dev --optimize-autoloader
php -d phar.readonly=0 build.php
```

## Why No Compression?

The build script intentionally **does not** compress the PHAR:

1. **Runtime overhead** - Decompression on every execution
2. **Startup time** - CLI tools should start fast
3. **Small benefit** - Only ~35% size reduction
4. **Compatibility** - Some systems lack zlib

## Why No Whitespace Stripping?

The build script **does not** use `php_strip_whitespace()`:

1. **Debugging** - Stack traces show wrong line numbers
2. **Small benefit** - Only ~15% size reduction
3. **Readability** - Source in PHAR remains readable

## Verifying Build

```bash
# Check version
./kvs.phar --version

# Run tests
./kvs.phar help

# Check PHAR contents
php -r "print_r((new Phar('kvs.phar'))->getSignature());"
```

## CI Build

GitHub Actions builds the PHAR on every push:

```yaml
# .github/workflows/ci.yml
build:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v4

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.1'
        ini-values: phar.readonly=0

    - name: Install dependencies
      run: composer install --no-dev --optimize-autoloader

    - name: Build PHAR
      run: php build.php

    - name: Verify PHAR
      run: ./kvs.phar --version

    - name: Upload artifact
      uses: actions/upload-artifact@v4
      with:
        name: kvs-cli-phar
        path: kvs.phar
        compression-level: 9
```

## Release Build

For releases, the PHAR is built with PHP 8.1 for maximum compatibility:

```yaml
# .github/workflows/release.yml
release:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v4

    - name: Setup PHP 8.1
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.1'
        ini-values: phar.readonly=0

    - name: Install dependencies
      run: composer install --no-dev --optimize-autoloader

    - name: Build PHAR
      run: php build.php

    - name: Generate checksum
      run: sha256sum kvs.phar > kvs.phar.sha256

    - name: Create release
      uses: softprops/action-gh-release@v1
      with:
        files: |
          kvs.phar
          kvs.phar.sha256
```

## Troubleshooting

### "phar.readonly must be disabled"

```bash
# Use -d flag
php -d phar.readonly=0 build.php

# Or edit php.ini
phar.readonly = Off
```

### "Cannot create phar"

Check:
- Directory is writable
- No existing kvs.phar that's locked
- PHP has phar extension enabled

### "PHAR won't run"

```bash
# Check PHP version
php --version

# Check for errors
php -l kvs.phar

# Check signature
php -r "var_dump((new Phar('kvs.phar'))->getSignature());"
```

### Size Comparison

| Build Type | Size |
|------------|------|
| Development (with tests) | ~8 MB |
| Production (no dev) | ~2 MB |
| Compressed (gzip) | ~1.3 MB |

## Self-Update Architecture

The `self-update` command replaces the running PHAR:

1. Download new PHAR to temp location
2. Verify new PHAR works (`--version`)
3. Pre-load required classes (prevent lazy-load errors)
4. Replace current PHAR with new one
5. Preserve permissions

```php
// Pre-load before replacing
class_exists(\Symfony\Component\String\UnicodeString::class);
class_exists(\Symfony\Component\Console\Terminal::class);

// Replace
rename($newPhar, $currentPhar);
```
