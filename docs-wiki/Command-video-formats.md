# kvs video:formats

Manage video formats and quality variants.

## Synopsis

```bash
kvs video:formats <action> [<video_id>] [options]
```

## Description

The `video:formats` command allows you to view and manage video format variants for specific videos.

## Actions

### list

List available formats for a video.

```bash
kvs video:formats list <video_id> [options]
```

### check

Check which formats exist or are missing for a video.

```bash
kvs video:formats check <video_id>
```

### available

Show all configured format options in KVS.

```bash
kvs video:formats available
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--fields=<fields>` | - | Comma-separated fields |
| `--format=<format>` | table | Output format |

## Examples

### List Video Formats

```bash
kvs video:formats list 123
```

Output:

```
Video #123 Formats
==================

Format    Resolution  Bitrate   Size      Status
───────────────────────────────────────────────────
source    1920x1080   8000k     1.2 GB    Active
1080p     1920x1080   4000k     650 MB    Active
720p      1280x720    2500k     380 MB    Active
480p      854x480     1200k     180 MB    Active
360p      640x360     600k      90 MB     Active
```

### Check Format Status

```bash
kvs video:formats check 123
```

Output:

```
Video #123 Format Check
=======================

Format    Expected  Actual    Status
──────────────────────────────────────
source    ✓         ✓         OK
1080p     ✓         ✓         OK
720p      ✓         ✓         OK
480p      ✓         ✗         Missing
360p      ✓         ✓         OK

1 format(s) missing. Run conversion to generate.
```

### Show Available Formats

```bash
kvs video:formats available
```

Output:

```
Configured Video Formats
========================

ID  Name    Resolution  Bitrate  Status
────────────────────────────────────────
1   source  Original    -        Active
2   1080p   1920x1080   4000k    Active
3   720p    1280x720    2500k    Active
4   480p    854x480     1200k    Active
5   360p    640x360     600k     Active
6   240p    426x240     400k     Disabled
```

### Output Formats

```bash
# JSON output
kvs video:formats list 123 --format=json

# CSV export
kvs video:formats list 123 --format=csv
```

## Aliases

- `kvs formats`

## See Also

- [[Command-video|video]] - Manage videos
- [[Command-video-screenshots|video:screenshots]] - Manage screenshots
