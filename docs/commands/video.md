# kvs video

Manage videos in your KVS installation.

## Synopsis

```bash
kvs video <action> [<id>] [options]
```

## Description

The `video` command allows you to list and view video content in your KVS installation.

## Actions

### list

List videos with optional filtering.

```bash
kvs video list [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--limit=<n>` | 20 | Maximum number of results |
| `--offset=<n>` | 0 | Skip first N results |
| `--status=<id>` | - | Filter by status (0, 1, 2) |
| `--user=<id>` | - | Filter by user ID |
| `--format=<fmt>` | table | Output format |
| `--fields=<list>` | - | Comma-separated fields |
| `--no-truncate` | - | Don't truncate long values |

**Status Values:**

| Value | Name | Color |
|-------|------|-------|
| 0 | Disabled | Yellow |
| 1 | Active | Green |
| 2 | Error | Red |

### show

Display details of a specific video.

```bash
kvs video show <id>
```

## Default Fields

When using `--format=table` (default):

- `video_id` - Video ID
- `title` - Video title
- `status` - Status with color
- `user_id` - Owner user ID
- `rating` - Rating (0-5 scale)
- `video_viewed` - View count

## Examples

### Basic Usage

```bash
# List first 20 videos
kvs video list

# List 50 videos
kvs video list --limit=50

# Show video details
kvs video show 123
```

### Filtering

```bash
# Active videos only
kvs video list --status=1

# Videos with errors
kvs video list --status=2

# Videos by specific user
kvs video list --user=5

# Combine filters
kvs video list --status=1 --user=5 --limit=100
```

### Output Formats

```bash
# JSON output
kvs video list --format=json

# CSV export
kvs video list --format=csv > videos.csv

# YAML
kvs video list --format=yaml

# Count only
kvs video list --format=count

# IDs only (for piping)
kvs video list --format=ids
```

### Field Selection

```bash
# Specific fields
kvs video list --fields=video_id,title,status

# Using aliases
kvs video list --fields=id,title,views

# All fields without truncation
kvs video list --no-truncate
```

### Pagination

```bash
# First 50 videos
kvs video list --limit=50

# Videos 51-100
kvs video list --limit=50 --offset=50

# All videos (careful with large datasets)
kvs video list --limit=0
```

### Scripting Examples

```bash
# Count videos by status
echo "Active: $(kvs video list --status=1 --format=count)"
echo "Errors: $(kvs video list --status=2 --format=count)"

# Process videos with jq
kvs video list --format=json | jq '.[] | select(.video_viewed > 1000)'

# Export to file
kvs video list --format=json > all_videos.json

# Loop through IDs
for id in $(kvs video list --status=2 --format=ids); do
    echo "Video with error: $id"
    kvs video show "$id"
done
```

## Available Fields

| Field | Description |
|-------|-------------|
| `video_id` | Unique video ID |
| `title` | Video title |
| `dir` | Directory/slug |
| `status_id` | Status code (0, 1, 2) |
| `user_id` | Owner user ID |
| `duration` | Duration in seconds |
| `rating` | Rating (0-100 scale) |
| `rating_amount` | Number of ratings |
| `video_viewed` | View count |
| `added_date` | Date added |
| `file_formats` | Available formats |
| `screen_amount` | Screenshot count |

## Aliases

- `kvs videos`
- `kvs content:video`

## See Also

- [`video:formats`](video-formats.md) - Manage video formats
- [`video:screenshots`](video-screenshots.md) - Manage screenshots
- [`user`](user.md) - Manage users
- [`comment`](comment.md) - Manage comments
