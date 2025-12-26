# kvs dev:log

View and manage KVS logs.

## Synopsis

```bash
kvs dev:log [<type>] [options]
```

## Description

The `dev:log` command allows you to view and manage KVS log files.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `type` | No | Log type to view |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `-t, --tail=<n>` | 50 | Show last N lines |
| `-f, --follow` | - | Follow log output (like `tail -f`) |
| `--clear` | - | Clear log file |
| `--list` | - | List available log files |

## Log Types

| Type | Description |
|------|-------------|
| `cron` | Cron job execution logs |
| `api` | API request logs |
| `uploader` | Content upload logs |
| `conversion` | Video conversion logs |
| `error` | Error logs |

## Examples

### List Available Logs

```bash
kvs log --list
```

Output:

```
Available Log Files
===================

Type        Size      Last Modified
──────────────────────────────────────
cron        12.3 MB   5 minutes ago
api         45.6 MB   2 minutes ago
uploader    8.9 MB    1 hour ago
conversion  23.4 MB   10 minutes ago
error       1.2 MB    3 hours ago
```

### View Recent Log Entries

```bash
# View last 50 lines of cron log
kvs log cron

# View last 100 lines
kvs log cron --tail=100

# View error log
kvs log error
```

### Follow Log in Real-time

```bash
kvs log cron --follow
```

Press `Ctrl+C` to stop.

### Clear Log File

```bash
kvs log cron --clear
```

Output:

```
Cleared cron log (was 12.3 MB)
```

### Scripting Examples

```bash
# Monitor for errors
kvs log error --follow | grep -i "critical"

# Save recent logs
kvs log cron --tail=1000 > cron-debug.log

# Check conversion logs
kvs log conversion --tail=20
```

## Aliases

- `kvs log`
- `kvs logs`

## See Also

- [`dev:debug`](dev-debug.md) - Debug information
- [`system:status`](system-status.md) - System status
