# kvs dvd

Manage DVDs/channels in your KVS installation.

## Synopsis

```bash
kvs dvd <action> [options]
```

## Description

The `dvd` command allows you to list and view DVD or channel content.

## Actions

### list

List DVDs with optional filtering.

```bash
kvs dvd list [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--limit=<n>` | 20 | Maximum number of results |
| `--offset=<n>` | 0 | Skip first N results |
| `--format=<fmt>` | table | Output format |
| `--fields=<list>` | - | Comma-separated fields |

## Default Fields

- `dvd_id` - DVD ID
- `title` - DVD title
- `dir` - Directory/slug
- `rating` - Rating (0-5 scale)

## Examples

### Basic Usage

```bash
# List DVDs
kvs dvd list

# List 50 DVDs
kvs dvd list --limit=50
```

### Output Formats

```bash
# JSON output
kvs dvd list --format=json

# CSV export
kvs dvd list --format=csv > dvds.csv

# Count only
kvs dvd list --format=count
```

### Scripting Examples

```bash
# Count total DVDs
kvs dvd list --format=count

# Export DVD data
kvs dvd list --format=json > dvds.json
```

## Available Fields

| Field | Description |
|-------|-------------|
| `dvd_id` | Unique DVD ID |
| `title` | DVD title |
| `dir` | URL slug |
| `rating` | Rating (0-100 scale) |
| `rating_amount` | Number of ratings |
| `total_videos` | Number of videos |
| `dvd_viewed` | View count |

## Aliases

- `kvs dvds`
- `kvs content:dvd`

## See Also

- [`video`](video.md) - Manage videos
- [`model`](model.md) - Manage models
