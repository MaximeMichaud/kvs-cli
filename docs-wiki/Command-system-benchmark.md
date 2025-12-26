# system:benchmark

Run performance benchmarks (HTTP response times, DB queries, CPU, cache, file I/O).

## Usage

```bash
kvs system:benchmark [options]
```

**Aliases:** `benchmark`, `bench`

## Options

| Option | Description | Default |
|--------|-------------|---------|
| `-u, --url=<url>` | Base URL for HTTP tests | Auto-detect from config |
| `-s, --samples=<n>` | Number of HTTP samples per endpoint | 5 |
| `--db-iterations=<n>` | Number of iterations for DB tests | 10 |
| `--cache-iterations=<n>` | Number of iterations for cache tests | 100 |
| `--file-iterations=<n>` | Number of iterations for file I/O tests | 100 |
| `--cpu-iterations=<n>` | Number of iterations for CPU tests | 1000 |
| `--memcached-host=<host>` | Memcached server host | 127.0.0.1 |
| `--memcached-port=<port>` | Memcached server port | 11211 |
| `-t, --tag=<tag>` | Tag/label for this benchmark run | - |
| `-e, --export=<file>` | Export results to JSON file | - |
| `-c, --compare=<file>` | Compare with baseline JSON file | - |
| `--php-container=<name>` | Docker container name for PHP-FPM config | Auto-detect |

## Benchmark Categories

### CPU Performance

Tests PHP-specific operations commonly used in KVS:

- **Hashing**: MD5 operations (simple, session, cache key, file hashing)
- **Serialization**: serialize/unserialize, JSON encode/decode
- **String Operations**: str_replace, htmlspecialchars, concatenation, sprintf
- **Regex**: Routing patterns, content parsing, email validation
- **Math/Stats**: Statistical calculations, sorting, percentiles
- **Arrays**: array_map, array_filter, array_column, array_merge, usort

### HTTP Response Times

Measures page load times for KVS endpoints:
- Homepage
- Video pages
- Category pages
- Search functionality

### Database Performance

Tests common database operations:
- Simple SELECT queries
- JOIN queries
- COUNT operations
- INSERT/UPDATE/DELETE cycles

### Cache Performance (Memcached)

Tests Memcached operations:
- SET operations (various sizes)
- GET operations
- DELETE operations
- Multi-get operations

### File I/O Performance

Tests filesystem operations:
- Small file read/write
- Large file read/write
- Directory operations
- File existence checks

## Examples

### Basic benchmark (no HTTP)

```bash
kvs benchmark
```

### Full benchmark with HTTP tests

```bash
kvs benchmark --url=https://example.com
```

### Benchmark with more samples

```bash
kvs benchmark --url=https://example.com --samples=10
```

### Tag and export results

```bash
kvs benchmark --url=https://example.com --tag="php81-baseline" --export=baseline.json
```

### Compare with baseline

```bash
kvs benchmark --url=https://example.com --tag="php84" --compare=baseline.json
```

### Use specific Docker container for PHP-FPM info

```bash
kvs benchmark --php-container=kvs-php
```

## Output

The benchmark displays:

1. **System Information**
   - OS, PHP version, OPcache/JIT status
   - Database type and version
   - Memcached version

2. **CPU Performance** (grouped by category)
   - Average time (ms)
   - p50 and p95 percentiles
   - Standard deviation
   - Operations per second

3. **HTTP Response Times**
   - Average, min, max times
   - p50 and p95 percentiles
   - Requests per second

4. **Database Performance**
   - Average query time
   - Queries per second

5. **Cache Performance**
   - Operation times
   - Operations per second

6. **File I/O Performance**
   - Read/write times
   - Operations per second

7. **System Metrics**
   - Load average
   - Memory usage
   - CPU info

8. **Summary**
   - Total benchmark time
   - Performance score
   - Rating (Excellent/Good/Fair/Poor)

## Comparison Mode

When using `--compare`, the output shows percentage differences:
- Green: Performance improvement
- Yellow: Minor degradation (<10%)
- Red: Significant degradation (>10%)

## Use Cases

### PHP Version Comparison

```bash
# On PHP 8.1
kvs benchmark --tag="php81" --export=php81.json

# On PHP 8.4
kvs benchmark --tag="php84" --compare=php81.json
```

### Before/After Optimization

```bash
# Before changes
kvs benchmark --url=https://example.com --export=before.json

# After changes
kvs benchmark --url=https://example.com --compare=before.json
```

### Server Comparison

```bash
# On server A
kvs benchmark --tag="server-a" --export=server-a.json

# On server B
kvs benchmark --tag="server-b" --compare=server-a.json
```
