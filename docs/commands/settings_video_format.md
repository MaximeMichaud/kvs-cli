# kvs settings:video-format

**[EXPERIMENTAL]** Inspect KVS video format configurations.

## Synopsis

```bash
kvs settings:video-format [<action>] [<id>] [options]
```

## Description

The `settings:video-format` command reads configured KVS video formats from
`admin/formats_videos.php` data. It shows which formats are configured for
future conversions, their status, group, access level, duration limits, timeline
settings, and usage count.

**Note:** This command inspects format **configuration**. To check actual video
**files** for a specific video, use `kvs video:formats`.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `groups` (default: `list`) |
| `id` | Conditional | Format ID, required for `show` |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--status=STATUS` | - | Filter by status: `disabled`, `required`, `optional`, `deleting`, `error`, `conditional` |
| `--group=GROUP` | - | Filter by group ID |
| `--search=TEXT` | - | Search in title, postfix, and FFmpeg options |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--format=FORMAT` | table | Output format: `table`, `csv`, `json`, `yaml`, `count` |
| `--no-truncate` | - | Do not truncate long values |
| `--force` | - | Skip experimental feature confirmation |

## Status Values

| Status | ID | Description |
|--------|----|-------------|
| `disabled` | 0 | Format is disabled |
| `required` | 1 | Always converted for every video |
| `optional` | 2 | Converted if source quality allows |
| `deleting` | 3 | Format is being deleted |
| `error` | 4 | Conversion error occurred |
| `conditional` | 9 | Optional with specific conditions |

## Access Levels

| Level | ID | Description |
|-------|----|-------------|
| `any` | 0 | Available to guests |
| `member` | 1 | Requires membership |
| `premium` | 2 | Premium members only |

## Actions

### list

List configured video formats.

```bash
kvs video-format list
kvs video-format list --status=required
kvs video-format list --group=1
kvs video-format list --search=mp4
kvs video-format list --format=json
```

### show

Show detailed format configuration.

```bash
kvs video-format show 1
kvs video-format show 5
```

### groups

List video format groups.

```bash
kvs video-format groups
```

## List Fields

Common `list` fields include:

- `format_video_id`
- `id`
- `title`
- `postfix`
- `status_id`
- `status`
- `is_conditional`
- `format_video_group_id`
- `group_title`
- `size`
- `access_level_id`
- `access`
- `is_download_enabled`
- `download`
- `is_hotlink_protection_enabled`
- `limit_total_duration`
- `limit_offset_start`
- `limit_offset_end`
- `limit_speed_value`
- `is_timeline_enabled`
- `timeline`
- `videos_count`
- `ffmpeg_options`
- `watermark_image`
- `watermark2_image`
- `preroll_video`
- `postroll_video`
- `added_date`

The `show` action can output the same configuration fields in structured
formats, plus calculated display fields such as `group`, `hotlink_protection`,
and formatted duration or offset values.

## Group Fields

Common `groups` fields include:

- `format_video_group_id`
- `id`
- `title`
- `format_count`
- `formats`
- `videos_count`
- `is_default`
- `default`
- `is_premium`
- `premium`
- `set_duration_from`

## Examples

### List Formats

```bash
# All configured formats
kvs video-format list

# Required formats only
kvs video-format list --status=required

# Conditional formats
kvs video-format list --status=conditional

# Formats by group
kvs video-format list --group=1

# Search title, postfix, or FFmpeg options
kvs video-format list --search=mp4
```

### View Format Details

```bash
# Show format 1 configuration
kvs video-format show 1

# JSON output for a specific format
kvs video-format show 1 --format=json
```

### List Groups

```bash
# Show all format groups
kvs video-format groups

# Export groups
kvs video-format groups --format=json
```

### Export Configuration

```bash
# Export current format configuration
kvs video-format list --format=json > formats-config.json

# Export selected fields
kvs video-format list --fields=format_video_id,title,postfix,status,size,access,videos_count --format=json

# Export with long FFmpeg options untruncated
kvs video-format list --fields=format_video_id,title,ffmpeg_options --no-truncate --format=csv > formats.csv
```

## Sample Output

### list

```text
Video Formats
=============

 ID  Title        Postfix       Status          Size                         Access     Download  Timeline
 5   MP4 Preview  _preview.mp4  Required        320x180 (fixed size)         Any users  Yes       No
 4   MP4 4k       _2160p.mp4    Cond. required  4096x2160 (dynamic width)    Any users  Yes       No
 3   MP4 1080p    _1080p.mp4    Cond. required  1920x1080 (dynamic width)    Any users  Yes       No
 2   MP4 720p     _720p.mp4     Required        1280x720 (dynamic width)     Any users  Yes       No
 1   MP4 480p     .mp4          Required        848x480 (dynamic width)      Any users  Yes       10s
```

### show

```text
Video Format #1
===============

Basic Info
----------

 Property  Value
 Title     MP4 480p
 Postfix   .mp4
 Status    Required
 Size      848x480 (dynamic width)
 Group     Default (#1)

Access & Download
-----------------

 Property            Value
 Access Level        Any users
 Download Enabled    Yes
 Hotlink Protection  Yes

Duration & Offset Limits
------------------------

 Property        Value
 Total Duration  As source
 Start Offset    0
 End Offset      0

Timeline Settings
-----------------

 Property           Value
 Timeline Enabled   Yes
 Timeline Interval  10s
```

### groups

```text
Format Groups
=============

 ID  Title    Default  Premium  Formats
 1   Default  Yes      No       5
```

## Use Cases

### Review Configuration

```bash
# Check which formats are required
kvs video-format list --status=required

# Search for MP4 formats
kvs video-format list --search=mp4

# View premium-only or default groups
kvs video-format groups --fields=format_video_group_id,title,is_default,is_premium,formats --format=json
```

### Identify Issues

```bash
# Find formats with errors
kvs video-format list --status=error

# Check formats being deleted
kvs video-format list --status=deleting
```

## Aliases

- `kvs video-format`
- `kvs vformat`

## Notes

- This command is **EXPERIMENTAL** and requires confirmation or `--force`.
- This command inspects format configuration, not actual video files.
- It does not create, update, or delete KVS video formats.
- Use the KVS admin panel for mutating video format configuration.

## Difference: settings:video-format vs video:formats

| Command | Purpose | Scope |
|---------|---------|-------|
| `settings:video-format` | Inspect format **configuration** | Admin settings |
| `video:formats` | Check actual video **files** | Individual videos |

**Example:**

```bash
# Check what formats are configured
kvs settings:video-format list

# Check what files exist for video 123
kvs video:formats 123
```

## See Also

- [`video:formats`](video_formats.md) - Check actual video files
- [`system:conversion`](system_conversion.md) - Manage conversion servers
- [`system:queue`](queue.md) - View conversion queue
- [`video`](video.md) - Manage videos
