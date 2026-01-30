# kvs settings:video-format

**[EXPERIMENTAL]** Manage KVS video format configurations.

## Synopsis

```bash
kvs settings:video-format [<action>] [<id>] [options]
```

## Description

The `settings:video-format` command manages video format configurations in KVS. This controls which video formats are generated during conversion and their settings.

**Note:** This command manages format **configuration** (admin settings). To check actual video **files**, use `kvs video:formats`.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `groups` (default: `list`) |
| `id` | Conditional | Format ID (required for `show`) |

## Options

| Option | Description |
|--------|-------------|
| `--status=STATUS` | Filter by status (see status values below) |
| `--group=ID` | Filter by group ID |
| `--fields=FIELDS` | Comma-separated fields to display |
| `--format=FORMAT` | Output format: `table`, `csv`, `json`, `yaml`, `count` |
| `--no-truncate` | Disable truncation |
| `--force` | Skip experimental feature confirmation |

## Status Values

| Status | ID | Description |
|--------|-----|-------------|
| `disabled` | 0 | Format is disabled |
| `required` | 1 | Always converted for every video |
| `optional` | 2 | Converted if source quality allows |
| `deleting` | 3 | Format is being deleted |
| `error` | 4 | Conversion error occurred |
| `conditional` | 9 | Optional with specific conditions |

## Access Levels

| Level | ID | Description |
|-------|-----|-------------|
| `any` | 0 | Available to guests |
| `member` | 1 | Requires membership |
| `premium` | 2 | Premium members only |

## Actions

### list

List all configured video formats.

```bash
# All formats
kvs video-format list

# Required formats only
kvs video-format list --status=required

# Formats in group 1
kvs video-format list --group=1

# JSON output
kvs video-format list --format=json
```

### show <id>

Show detailed format configuration.

```bash
kvs video-format show 1
kvs video-format show 5
```

### groups

List format groups.

```bash
kvs video-format groups
```

## Examples

### List Formats

```bash
# All configured formats
kvs video-format list

# Required formats only
kvs video-format list --status=required

# Optional formats
kvs video-format list --status=optional

# Disabled formats
kvs video-format list --status=disabled

# Formats by group
kvs video-format list --group=1
```

### View Format Details

```bash
# Show format 1 configuration
kvs video-format show 1

# Show format 5
kvs video-format show 5
```

### List Groups

```bash
# Show all format groups
kvs video-format groups
```

### Export Configuration

```bash
# Export format config to JSON
kvs video-format list --format=json > formats-config.json

# Export groups
kvs video-format groups --format=json > groups.json
```

## Sample Output

### List

```
Video Formats
=============

 ID  Title      Status    Group  Size           Access   Videos
 1   1080p      Required  1      1920x1080      Any      1,234
 2   720p       Required  1      1280x720       Any      1,234
 3   480p       Required  1      854x480        Any      1,234
 4   360p       Optional  1      640x360        Any      856
 5   4K         Optional  2      3840x2160      Premium  45
```

### Show

```
Video Format #1
===============

 ID              1
 Title           1080p Full HD
 Status          Required
 Group           Standard Formats
 Resolution      1920x1080
 Bitrate         5000 kbps
 Access Level    Any
 Videos          1,234

Configuration
-------------
 Video Codec     h264
 Audio Codec     aac
 Container       mp4
 Postfix         _1080p

Conditions
----------
 Min Source Width  1920px
 Min Source Height 1080px
```

### Groups

```
Format Groups
=============

 ID  Title             Formats  Description
 1   Standard Formats  3        SD, HD, Full HD
 2   High Quality      2        4K, 8K
 3   Mobile            2        Low-res for mobile
```

## Use Cases

### Review Configuration

```bash
# Check which formats are required
kvs video-format list --status=required

# View premium-only formats
# (would need to filter output or use show)
kvs video-format list
```

### Identify Issues

```bash
# Find formats with errors
kvs video-format list --status=error

# Check formats being deleted
kvs video-format list --status=deleting
```

### Export for Documentation

```bash
# Export full configuration
kvs video-format list --format=json > format-config-$(date +%Y%m%d).json

# Export with all details
kvs video-format list --no-truncate --format=csv > formats.csv
```

## Aliases

- `kvs video-format`
- `kvs vformat`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- This manages format **configuration**, not actual video files
- Changes to format configuration affect future conversions only
- Existing videos are not automatically reconverted

## Difference: settings:video-format vs video:formats

| Command | Purpose | Scope |
|---------|---------|-------|
| `settings:video-format` | Manage format **configuration** | Admin settings |
| `video:formats` | Check actual video **files** | Individual videos |

**Example:**
```bash
# Check what formats are configured (admin)
kvs settings:video-format list

# Check what files exist for video 123
kvs video:formats 123
```

## See Also

- [`video:formats`](video_formats.md) - Check actual video files
- [`system:conversion`](system_conversion.md) - Manage conversion servers
- [`system:queue`](queue.md) - View conversion queue
- [`video`](video.md) - Video management
