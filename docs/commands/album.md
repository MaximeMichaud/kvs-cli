# kvs album

Manage photo albums in your KVS installation.

## Synopsis

```bash
kvs album <action> [<id>] [options]
```

## Description

The `album` command allows you to list and view photo album content in your KVS installation.

## Actions

### list

List albums with optional filtering.

```bash
kvs album list [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--limit=<n>` | 20 | Maximum number of results |
| `--offset=<n>` | 0 | Skip first N results |
| `--status=<id>` | - | Filter by status (0, 1) |
| `--user=<id>` | - | Filter by user ID |
| `--format=<fmt>` | table | Output format |
| `--fields=<list>` | - | Comma-separated fields |
| `--no-truncate` | - | Don't truncate long values |

**Status Values:**

| Value | Name | Color |
|-------|------|-------|
| 0 | Disabled | Yellow |
| 1 | Active | Green |

### show

Display details of a specific album.

```bash
kvs album show <id>
```

## Default Fields

- `album_id` - Album ID
- `title` - Album title
- `status` - Status with color
- `user_id` - Owner user ID
- `rating` - Rating (0-5 scale)
- `album_viewed` - View count

## Examples

### Basic Usage

```bash
# List first 20 albums
kvs album list

# List 50 albums
kvs album list --limit=50

# Show album details
kvs album show 45
```

### Filtering

```bash
# Active albums only
kvs album list --status=1

# Disabled albums
kvs album list --status=0

# Albums by specific user
kvs album list --user=5

# Combine filters
kvs album list --status=1 --limit=100
```

### Output Formats

```bash
# JSON output
kvs album list --format=json

# CSV export
kvs album list --format=csv > albums.csv

# Count only
kvs album list --format=count
```

### Scripting Examples

```bash
# Count albums by status
echo "Active: $(kvs album list --status=1 --format=count)"
echo "Disabled: $(kvs album list --status=0 --format=count)"

# Export album data
kvs album list --format=json > all_albums.json
```

## Available Fields

| Field | Description |
|-------|-------------|
| `album_id` | Unique album ID |
| `title` | Album title |
| `dir` | Directory/slug |
| `status_id` | Status code (0, 1) |
| `user_id` | Owner user ID |
| `rating` | Rating (0-100 scale) |
| `rating_amount` | Number of ratings |
| `album_viewed` | View count |
| `added_date` | Date added |
| `photos_amount` | Number of photos |

## Aliases

- `kvs albums`
- `kvs content:album`

## See Also

- [`video`](video.md) - Manage videos
- [`user`](user.md) - Manage users
- [`comment`](comment.md) - Manage comments
