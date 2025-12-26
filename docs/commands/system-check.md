# kvs system:check

Run comprehensive health checks on your KVS installation.

## Synopsis

```bash
kvs system:check [options]
```

## Description

The `system:check` command performs a thorough analysis of your KVS installation, checking for potential issues with configuration, dependencies, performance, and security.

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output results as JSON |
| `--quiet-ok` | Only show warnings and errors |

## Checks Performed

### KVS Update Check

Checks if a newer version of KVS is available.

### PHP Compatibility

- PHP version meets KVS requirements
- Compatibility with current KVS version

### PHP Extensions

Checks for required extensions:
- `mysqli` - Database connectivity
- `curl` - HTTP requests
- `zlib` - Compression
- `simplexml` - XML parsing
- `gd` - Image processing
- `mbstring` - Unicode support
- `ioncube` - License verification (if used)

### System Tools

Checks for external tools:
- **FFmpeg** - Video processing
- **ImageMagick** - Image processing
- **mysqldump** - Database backups

### Memcached

- Extension installed
- Server connectivity
- Memory configuration

### OPcache

- Extension enabled
- JIT configuration
- Memory settings

### PHP Settings

Validates critical PHP settings:
- `upload_max_filesize`
- `post_max_size`
- `memory_limit`
- `max_execution_time`
- `max_input_time`

### Cron Status

Checks last run times for:
- Main cron job
- Conversion cron
- Optimization cron
- Other scheduled tasks

### MySQL/MariaDB

- Server version
- Configuration recommendations
- Performance settings

### System Load

- CPU load average
- IO wait percentage
- Memory usage

### Disk Space

Checks free space on:
- KVS installation path
- Content storage path
- Temp directories

### Internet Connectivity

Tests outbound connectivity for:
- License verification
- Update checks
- External services

### End of Life Status

Checks EOL status for:
- PHP version
- MySQL/MariaDB version
- Operating system (if detectable)

## Output

### Table Format (Default)

```
KVS System Check
================

Check                    Status  Details
─────────────────────────────────────────────────────
KVS Update              ✓ OK    Version 6.3.2 is current
PHP Version             ✓ OK    PHP 8.2.15
PHP Extensions          ✓ OK    All required extensions loaded
FFmpeg                  ✓ OK    6.0.0 at /usr/bin/ffmpeg
ImageMagick             ✓ OK    7.1.0 at /usr/bin/convert
Memcached               ✓ OK    Connected to 127.0.0.1:11211
OPcache                 ⚠ WARN  JIT not enabled
Cron Status             ✓ OK    Last run 5 minutes ago
MySQL Version           ✓ OK    MySQL 8.0.35
Disk Space (KVS)        ✓ OK    45.2 GB free (90.4% available)
Disk Space (Content)    ⚠ WARN  15.3 GB free (12.5% available)
PHP EOL                 ✓ OK    PHP 8.2 supported until 2026-12-08
MySQL EOL               ✓ OK    MySQL 8.0 supported until 2026-04-30

Summary: 11 passed, 2 warnings, 0 errors
```

### JSON Format

```bash
kvs check --json
```

```json
{
  "checks": [
    {
      "name": "KVS Update",
      "status": "ok",
      "message": "Version 6.3.2 is current"
    },
    {
      "name": "PHP Version",
      "status": "ok",
      "message": "PHP 8.2.15",
      "details": {
        "version": "8.2.15",
        "required": "8.1.0"
      }
    }
  ],
  "summary": {
    "passed": 11,
    "warnings": 2,
    "errors": 0
  }
}
```

### Quiet Mode

```bash
kvs check --quiet-ok
```

Only shows warnings and errors:

```
⚠ OPcache: JIT not enabled
⚠ Disk Space (Content): 15.3 GB free (12.5% available)
```

## Examples

### Basic Check

```bash
kvs check
```

### JSON Output for Monitoring

```bash
kvs check --json > health_check.json
```

### Only Show Problems

```bash
kvs check --quiet-ok
```

### Integration with Monitoring

```bash
#!/bin/bash
# health-check.sh

RESULT=$(kvs check --json)
ERRORS=$(echo "$RESULT" | jq '.summary.errors')

if [ "$ERRORS" -gt 0 ]; then
    echo "CRITICAL: KVS health check failed"
    echo "$RESULT" | jq '.checks[] | select(.status == "error")'
    exit 2
fi

WARNINGS=$(echo "$RESULT" | jq '.summary.warnings')
if [ "$WARNINGS" -gt 0 ]; then
    echo "WARNING: KVS health check has warnings"
    exit 1
fi

echo "OK: All KVS health checks passed"
exit 0
```

## Aliases

- `kvs check`

## See Also

- [`system:status`](system-status.md) - Show system status
- [`config`](config.md) - View configuration
- [`dev:debug`](dev-debug.md) - Debug information
