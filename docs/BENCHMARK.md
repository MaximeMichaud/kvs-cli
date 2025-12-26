# KVS CLI Benchmark System

## Overview

The `system:benchmark` command provides comprehensive performance testing for KVS installations. It measures HTTP response times, database query performance, Memcached cache operations, file I/O, and system metrics.

## Usage

```bash
# Basic usage (DB and File I/O only)
kvs benchmark

# Full benchmark with HTTP tests
kvs benchmark --url=https://your-site.com

# With custom tag for comparison
kvs benchmark --tag=php81-mariadb11

# Export results to JSON
kvs benchmark --export=results-php81.json

# Compare with baseline
kvs benchmark --compare=baseline.json --export=current.json
```

## Command Options

| Option | Short | Default | Description |
|--------|-------|---------|-------------|
| `--url` | `-u` | - | Base URL for HTTP tests |
| `--samples` | `-s` | 5 | Number of HTTP samples per endpoint |
| `--db-iterations` | - | 10 | Iterations for DB tests |
| `--cache-iterations` | - | 100 | Iterations for cache tests |
| `--file-iterations` | - | 100 | Iterations for file I/O tests |
| `--memcached-host` | - | 127.0.0.1 | Memcached server host |
| `--memcached-port` | - | 11211 | Memcached server port |
| `--tag` | `-t` | - | Label for this benchmark run |
| `--export` | `-e` | - | Export results to JSON file |
| `--compare` | `-c` | - | Compare with baseline JSON file |

## Benchmark Types

### 1. HTTP Response Times

Tests real page response times using curl:
- Homepage
- Video Listing
- Categories
- Search
- Admin Panel

Metrics: avg, min, max, p50, p95, p99 (in milliseconds)

### 2. Database Performance

Tests actual KVS queries:

**Basic Queries (page loads):**
- Video Listing (20 items)
- Video Count Query
- Category Listing + Counts
- LIKE Search Query
- User Lookup (prepared statement)

**Heavy Queries (cron-style):**
- Category Summary (JOIN with aggregation)
- Stats Aggregation (30 days grouping)
- Complex JOIN (5 tables with GROUP_CONCAT)

**Write Operations:**
- INSERT (temp table)
- UPDATE Counter

Metrics: avg (ms), queries/sec

### 3. Cache Performance (Memcached)

Tests KVS-style caching patterns:
- Simple GET/SET operations
- Large value operations (~50KB page content)
- Bulk operations (multi-get/set with 100 keys)
- KVS Page Cache pattern (MD5 hash keys)

Metrics: avg, p50, p95, p99, ops/sec, hit ratio

### 4. File I/O Performance

Tests KVS file operations:
- Serialize/Unserialize (config arrays)
- Language file serialization (~500 strings)
- Config load (file_get_contents + unserialize)
- File reads (1KB, 10KB, 100KB)
- File writes (10KB, with fsync)
- Directory operations (scandir, glob, filemtime)

Metrics: avg, min, max, ops/sec

### 5. System Information

Collected automatically:
- PHP version, SAPI, OPcache, JIT status
- Database type (MySQL/MariaDB) and version
- Memcached version
- Loaded extensions (memcached, curl, gd, imagick, pdo_mysql)
- CPU model and cores
- Memory usage
- Load average

## JSON Export Format

```json
{
  "timestamp": "2025-12-26T10:00:00+00:00",
  "tag": "php81-mariadb11",
  "system_info": {
    "php_version": "8.1.0",
    "db_type": "mariadb",
    "db_version": "11.4.0-MariaDB",
    "memcached_version": "1.6.22",
    "extensions": ["memcached", "curl", "gd", "pdo_mysql"]
  },
  "http_results": {
    "homepage": {"name": "Homepage", "avg": 45.2, "p50": 42.0, "p95": 55.0}
  },
  "db_results": {
    "video_listing": {"name": "Video Listing", "avg_ms": 0.5, "queries_sec": 2000}
  },
  "cache_results": {
    "simple_get": {"name": "Simple GET", "avg": 0.15, "ops_sec": 6666}
  },
  "fileio_results": {
    "serialize": {"name": "Serialize Config", "avg": 0.02, "ops_sec": 50000}
  },
  "system_metrics": {
    "load_1m": 0.5,
    "memory_percent": 45.2,
    "cpu_cores": 8
  },
  "score": 15000,
  "rating": "Excellent"
}
```

## Comparing PHP Versions

```bash
# Run on PHP 8.1
php81 /path/to/kvs.phar benchmark --tag=php81 --export=bench-php81.json

# Run on PHP 8.2
php82 /path/to/kvs.phar benchmark --tag=php82 --export=bench-php82.json

# Compare
php82 /path/to/kvs.phar benchmark --compare=bench-php81.json
```

## Comparing MariaDB vs MySQL

```bash
# With MariaDB
kvs benchmark --tag=mariadb-11 --export=bench-mariadb.json

# Switch to MySQL, then run
kvs benchmark --tag=mysql-8 --compare=bench-mariadb.json
```

## Architecture

```
src/Benchmark/
├── BenchmarkRunner.php   # Orchestrates all tests
├── BenchmarkResult.php   # Stores results with serialization
├── HttpBench.php         # HTTP response time tests
├── DatabaseBench.php     # Database query tests
├── CacheBench.php        # Memcached tests
├── FileIOBench.php       # File I/O tests
└── SystemBench.php       # System info collection

src/Command/System/
└── BenchmarkCommand.php  # CLI command
```

## Adding New Benchmarks

1. Create a new `*Bench.php` class in `src/Benchmark/`
2. Add recording method to `BenchmarkResult.php` (e.g., `recordMyBench()`)
3. Integrate in `BenchmarkRunner.php`
4. Add display method in `BenchmarkCommand.php`

## Notes

- All DB write tests use temporary tables (no data pollution)
- Cache keys auto-expire (short TTL)
- File I/O tests use system temp directory
- HTTP tests require the server to be reachable
- Memcached is optional (tests skipped if not available)
