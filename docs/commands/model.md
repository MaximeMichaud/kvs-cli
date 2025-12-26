# kvs model

Manage models/performers in your KVS installation.

## Synopsis

```bash
kvs model <action> [options]
```

## Description

The `model` command allows you to list and view model/performer profiles.

## Actions

### list

List models with optional filtering.

```bash
kvs model list [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--limit=<n>` | 20 | Maximum number of results |
| `--offset=<n>` | 0 | Skip first N results |
| `--format=<fmt>` | table | Output format |
| `--fields=<list>` | - | Comma-separated fields |

## Default Fields

- `model_id` - Model ID
- `title` - Model name
- `dir` - Directory/slug
- `rating` - Rating (0-5 scale)

## Examples

### Basic Usage

```bash
# List models
kvs model list

# List 50 models
kvs model list --limit=50
```

### Output Formats

```bash
# JSON output
kvs model list --format=json

# CSV export
kvs model list --format=csv > models.csv

# Count only
kvs model list --format=count
```

### Scripting Examples

```bash
# Count total models
kvs model list --format=count

# Export model data
kvs model list --format=json > models.json
```

## Available Fields

| Field | Description |
|-------|-------------|
| `model_id` | Unique model ID |
| `title` | Model name |
| `dir` | URL slug |
| `alias` | Alternate names |
| `rating` | Rating (0-100 scale) |
| `rating_amount` | Number of ratings |
| `profile_viewed` | Profile views |
| `total_videos` | Number of videos |
| `total_albums` | Number of albums |

## Aliases

- `kvs models`
- `kvs content:model`

## See Also

- [`video`](video.md) - Manage videos
- [`album`](album.md) - Manage albums
- [`dvd`](dvd.md) - Manage DVDs
