# kvs video:screenshots

Manage video screenshots.

## Synopsis

```bash
kvs video:screenshots <action> <video_id> [options]
```

## Description

The `video:screenshots` command allows you to list, generate, and regenerate screenshots for videos.

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
| `--count=<n>` | 10 | Number of screenshots to generate |
| `--type=<type>` | timeline | Screenshot type: timeline, poster |
| `--fields=<fields>` | - | Comma-separated fields |
| `--format=<format>` | table | Output format |
| `--no-truncate` | - | Don't truncate long fields |

## Examples

### List Screenshots

```bash
kvs video:screenshots list 123
```

Output:

```
Video #123 Screenshots
======================

#   Time      File                           Size
────────────────────────────────────────────────────
1   0:00:05   123_1_timeline.jpg             45 KB
2   0:00:32   123_2_timeline.jpg             52 KB
3   0:01:05   123_3_timeline.jpg             48 KB
4   0:01:38   123_4_timeline.jpg             51 KB
5   0:02:12   123_5_timeline.jpg             49 KB

Total: 5 screenshots
```

### Generate Screenshots

```bash
# Generate 10 screenshots (default)
kvs video:screenshots generate 123

# Generate 20 screenshots
kvs video:screenshots generate 123 --count=20
```

Output:

```
Generating screenshots for video #123...

Video duration: 5:32 (332 seconds)
Interval: 33 seconds

Generating screenshot 1/10 at 0:00:16... ✓
Generating screenshot 2/10 at 0:00:49... ✓
Generating screenshot 3/10 at 0:01:22... ✓
...
Generating screenshot 10/10 at 0:05:16... ✓

Generated 10 screenshots successfully.
```

### Regenerate Screenshots

```bash
kvs video:screenshots regenerate 123
```

Output:

```
Deleting existing screenshots for video #123...
Deleted 5 screenshots.

Generating new screenshots...
...
Generated 10 screenshots successfully.
```

### Output Formats

```bash
# JSON output
kvs video:screenshots list 123 --format=json

# Count only
kvs video:screenshots list 123 --format=count
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

- [[Command-video|video]] - Manage videos
- [[Command-video-formats|video:formats]] - Manage formats
