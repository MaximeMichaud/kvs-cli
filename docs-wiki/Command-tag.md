# kvs tag

Manage tags in your KVS installation.

## Synopsis

```bash
kvs tag <action> [<id>] [options]
```

## Description

The `tag` command allows you to list and manage content tags.

## Actions

### list

List tags with optional filtering.

```bash
kvs tag list [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--limit=<n>` | 20 | Maximum number of results |
| `--offset=<n>` | 0 | Skip first N results |
| `--status=<id>` | - | Filter by status (0, 1) |
| `--format=<fmt>` | table | Output format |
| `--fields=<list>` | - | Comma-separated fields |

**Status Values:**

| Value | Name | Color |
|-------|------|-------|
| 0 | Inactive | Yellow |
| 1 | Active | Green |

### enable

Enable a tag (set status to 1).

```bash
kvs tag enable <id>
```

### disable

Disable a tag (set status to 0).

```bash
kvs tag disable <id>
```

## Default Fields

- `tag_id` - Tag ID
- `tag` - Tag name
- `dir` - Directory/slug
- `status` - Status with color

## Examples

### Basic Usage

```bash
# List all tags
kvs tag list

# List 50 tags
kvs tag list --limit=50
```

### Filtering

```bash
# Active tags only
kvs tag list --status=1

# Inactive tags
kvs tag list --status=0
```

### Managing Status

```bash
# Enable a tag
kvs tag enable 3

# Disable a tag
kvs tag disable 3
```

### Output Formats

```bash
# JSON output
kvs tag list --format=json

# CSV export
kvs tag list --format=csv > tags.csv

# Count only
kvs tag list --format=count
```

### Scripting Examples

```bash
# Count tags by status
echo "Active: $(kvs tag list --status=1 --format=count)"
echo "Inactive: $(kvs tag list --status=0 --format=count)"

# Export tag data
kvs tag list --format=json > tags.json

# Enable multiple tags
for id in 1 2 3; do
    kvs tag enable $id
done
```

## Available Fields

| Field | Description |
|-------|-------------|
| `tag_id` | Unique tag ID |
| `tag` | Tag name |
| `dir` | URL slug |
| `status_id` | Status code (0, 1) |
| `total_videos` | Number of videos |
| `total_albums` | Number of albums |

## Aliases

- `kvs tags`
- `kvs content:tag`

## See Also

- [[Command-category|category]] - Manage categories
- [[Command-video|video]] - Manage videos
- [[Command-album|album]] - Manage albums
