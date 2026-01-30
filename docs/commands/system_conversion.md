# kvs system:conversion

**[EXPERIMENTAL]** Manage KVS conversion servers (video/image transcoding).

## Synopsis

```bash
kvs system:conversion [<action>] [<id>] [options]
```

## Description

The `system:conversion` command manages KVS conversion servers responsible for video encoding, transcoding, and image processing.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `enable`, `disable`, `debug-on`, `debug-off`, `log`, `config`, `stats` |
| `id` | Conditional | Server ID (required for most actions except `list` and `stats`) |

## Options

| Option | Description |
|--------|-------------|
| `--status=STATUS` | Filter by status (`active`, `disabled`, `init`) |
| `--errors` | Show only servers with errors |
| `--limit=N` | Number of results (default: 50) |
| `--format=FORMAT` | Output format: `table`, `csv`, `json`, `yaml`, `count` |
| `--fields=FIELDS` | Comma-separated list of fields |
| `--no-truncate` | Disable truncation |
| `--force` | Skip experimental feature confirmation |

## Actions

### list

List all conversion servers with optional filtering.

```bash
kvs conversion list
kvs conversion list --status=active
kvs conversion list --errors
```

### show <id>

Show detailed server information including tasks, options, and connection details.

```bash
kvs conversion show 1
```

### enable <id>

Enable/activate a conversion server.

```bash
kvs conversion enable 1
```

### disable <id>

Disable/deactivate a conversion server.

```bash
kvs conversion disable 1
```

### debug-on <id>

Enable debug mode for detailed conversion logging.

```bash
kvs conversion debug-on 1
```

### debug-off <id>

Disable debug mode.

```bash
kvs conversion debug-off 1
```

### log <id>

View the conversion server log.

```bash
kvs conversion log 1
```

### config <id>

View server configuration including libraries and paths.

```bash
kvs conversion config 1
```

### stats

Show conversion statistics across all servers.

```bash
kvs conversion stats
```

## Status Values

| ID | Status | Description |
|----|--------|-------------|
| 0 | Disabled | Server is inactive |
| 1 | Active | Server is processing conversions |
| 2 | Initializing | Server is starting up |

## Priority Levels

| ID | Level | Use Case |
|----|-------|----------|
| 0 | Realtime | User uploads (immediate processing) |
| 4 | High | Important content |
| 9 | Medium | Normal content (default) |
| 14 | Low | Bulk conversions |
| 19 | Very Low | Background tasks |

## Error Codes

| Code | Error | Description |
|------|-------|-------------|
| 1 | Write error | Cannot write output files |
| 2 | Heartbeat | Lost connection to server |
| 3 | Heartbeat timeout | Server not responding |
| 4 | Library path | FFmpeg/tool not found |
| 5 | API version | Incompatible API version |
| 6 | Locked too long | Task stuck/frozen |

## Examples

### Basic Usage

```bash
# List all conversion servers
kvs conversion list

# Show server 1 details
kvs conversion show 1

# View conversion statistics
kvs conversion stats
```

### Filtering

```bash
# Active servers only
kvs conversion list --status=active

# Servers with errors
kvs conversion list --errors
```

### Server Management

```bash
# Enable server 1
kvs conversion enable 1

# Disable server 2
kvs conversion disable 2
```

### Debugging

```bash
# Enable debug logging for server 1
kvs conversion debug-on 1

# View conversion log
kvs conversion log 1

# View server configuration
kvs conversion config 1

# Disable debug mode
kvs conversion debug-off 1
```

## Sample Output

### List

```
Conversion Servers
==================

 ID  Title       Status  Priority  Load  Tasks  Errors
 1   Main Server Active  Medium    45%   3      0
 2   Backup      Active  Low       12%   1      0
 3   GPU Server  Disabled -        0%    0      0
```

### Show

```
Conversion Server #1
====================

 ID              1
 Title           Main Server
 Status          Active
 Priority        Medium (9)
 Current Load    45%
 Active Tasks    3
 Completed       1,234
 Failed          12
 Debug Mode      Off

Libraries
---------
 FFmpeg          /usr/bin/ffmpeg v5.1
 FFprobe         /usr/bin/ffprobe
 ImageMagick     /usr/bin/convert
```

## Aliases

- `kvs conversion`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- Debug mode generates large log files - disable when not needed
- Server priority affects task queue order
- Use `config` action to verify FFmpeg/ImageMagick paths

## See Also

- [`system:server`](system_server.md) - Manage storage servers
- [`system:status`](system_status.md) - Overall system status
- [`system:queue`](queue.md) - View background task queue
