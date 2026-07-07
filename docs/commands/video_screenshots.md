# kvs video:screenshots

Manage video screenshots.

## Synopsis

```bash
kvs video:screenshots <action> <video_id> [options]
```

## Description

The `video:screenshots` command lists KVS overview screenshots for a video and can generate or regenerate
KVS source overview screenshot files from the source video.

`list` reports the logical KVS overview screenshots. When database metadata is available, the row count
comes from `videos.screen_amount` and the main screenshot marker comes from `videos.screen_main`.
Timeline screenshots and posters are separate KVS screenshot groups and are not selected by this command.

## Actions

### list

List existing screenshots for a video.

```bash
kvs video:screenshots list <video_id> [options]
```

### generate

Generate screenshots for a video.

```bash
kvs video:screenshots generate <video_id> [options]
```

**Requires:** FFmpeg installed

### regenerate

Delete existing screenshots and generate new ones.

```bash
kvs video:screenshots regenerate <video_id> [options]
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--count=<n>` | 10 | Number of screenshots to generate for `generate` and `regenerate` |
| `--fields=<fields>` | `index,filename,formats,dimensions` | Comma-separated fields for `list` output |
| `--format=<format>` | table | `list` output format: `table`, `csv`, `json`, `yaml`, `count` |
| `--no-truncate` | - | Do not truncate long fields in table output |

Available `list` fields:

- `index`
- `filename`
- `formats`
- `size`
- `dimensions`
- `path`
- `is_main`

## Examples

### List Screenshots

```bash
kvs video:screenshots list 123 --fields=index,filename,formats,is_main --format=json
```

Output:

```json
[
  {
    "index": 1,
    "filename": "1.jpg",
    "formats": 2,
    "is_main": 1
  }
]
```

### Generate Screenshots

```bash
# Generate 10 screenshots (default)
kvs video:screenshots generate 123

# Generate 20 screenshots
kvs video:screenshots generate 123 --count=20
```

This writes source overview screenshots to `contents/videos_sources/<bucket>/<video_id>/screenshots/`
and requires FFmpeg and FFprobe to be configured.

### Regenerate Screenshots

```bash
kvs video:screenshots regenerate 123
```

This replaces existing source overview screenshots after the new screenshots are generated successfully.
Generated KVS preview and resized overview files under `contents/videos_screenshots/` are not deleted.

### Output Formats

```bash
# JSON output
kvs video:screenshots list 123 --format=json

# Count only
kvs video:screenshots list 123 --format=count

# Select list fields
kvs video:screenshots list 123 --fields=index,filename,formats,size,dimensions,path,is_main --format=json
```

### Batch Operations

```bash
# Regenerate screenshots for all error videos
for id in $(kvs video list --status=2 --format=ids); do
    echo "Processing video $id..."
    kvs video:screenshots regenerate "$id"
done
```

## Aliases

- `kvs screenshots`

## See Also

- [`video`](video.md) - Manage videos
- [`video:formats`](video_formats.md) - Manage formats
