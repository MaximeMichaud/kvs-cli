# kvs system:cron

Run cron jobs manually.

## Synopsis

```bash
kvs system:cron [<job>]
```

## Description

The `system:cron` command allows you to manually trigger KVS cron jobs. This is useful for testing, immediate processing, or when cron is not configured on the server.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `job` | No | Specific job name to run (runs all if omitted) |

## Available Jobs

| Job | Description |
|-----|-------------|
| `main` | Main cron job (statistics, cleanup) |
| `conversion` | Video conversion processing |
| `optimize` | Database optimization |
| `postponed` | Postponed tasks processing |
| `rotator` | Content rotation |

## Examples

### Run All Cron Jobs

```bash
kvs cron
```

Output:

```
Running KVS cron jobs...

 ✓ Main cron completed (2.3s)
 ✓ Conversion cron completed (15.7s)
 ✓ Optimization cron completed (1.2s)
 ✓ Postponed tasks completed (0.5s)

All cron jobs completed successfully.
```

### Run Specific Job

```bash
# Run main cron
kvs cron main

# Process video conversions
kvs cron conversion

# Optimize database
kvs cron optimize
```

### Scripting Examples

```bash
# Run conversions every 5 minutes (alternative to system cron)
while true; do
    kvs cron conversion
    sleep 300
done

# Run optimization during off-peak hours
kvs cron optimize
```

## Aliases

- `kvs cron`

## See Also

- [`system:status`](system-status.md) - Show system status
- [`system:check`](system-check.md) - Check cron status
