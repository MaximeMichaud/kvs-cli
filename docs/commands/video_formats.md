# kvs video:formats

Inspect video format files and configured KVS video formats.

## Synopsis

```bash
kvs video:formats <action> [<video_id>] [options]
```

## Description

The `video:formats` command inspects actual video format files on disk and compares them with
the configured KVS video formats.

## Actions

### list

List actual video files found for a video.

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
| `--format=<format>` | table | Output format: `table`, `csv`, `json`, `yaml` |

## Fields

### list

- `format`
- `postfix`
- `file`
- `size`
- `dimensions`
- `path`

### check

- `format`
- `postfix`
- `status`
- `file`
- `size`
- `dimensions`
- `path`

### available

Default fields:

- `format_id`
- `title`
- `postfix`
- `status`
- `group_id`
- `access`

## Examples

### List Video Formats

```bash
kvs video:formats list 123 --fields=format,postfix,file,size,dimensions --format=json
```

Output:

```json
[
  {
    "format": "MP4 720p",
    "postfix": "_720p.mp4",
    "file": "123_720p.mp4",
    "size": "65.75 MB",
    "dimensions": "1280x720"
  }
]
```

### Check Format Status

```bash
kvs video:formats check 123 --fields=format,postfix,status,file,size,dimensions --format=json
```

Output:

```json
[
  {
    "format": "MP4 720p",
    "postfix": "_720p.mp4",
    "status": "available",
    "file": "123_720p.mp4",
    "size": "65.75 MB",
    "dimensions": "1280x720"
  }
]
```

### Show Available Formats

```bash
kvs video:formats available --fields=format_id,title,postfix,status,group_id,access --format=json
```

Output:

```json
[
  {
    "format_id": 2,
    "title": "MP4 720p",
    "postfix": "_720p.mp4",
    "status": "Required",
    "group_id": 1,
    "access": "Any users"
  }
]
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

- [`video`](video.md) - Manage videos
- [`video:screenshots`](video_screenshots.md) - Manage screenshots
