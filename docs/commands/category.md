# kvs category

Manage categories in your KVS installation.

## Synopsis

```bash
kvs category <action> [<id>] [<values>...] [options]
```

## Description

The `category` command allows you to list, view, and manage content categories.

## Actions

### list

List categories with optional filtering.

```bash
kvs category list [options]
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

### show

Display details of a specific category.

```bash
kvs category show <id>
```

### enable

Enable a category (set status to 1).

```bash
kvs category enable <id>
```

### disable

Disable a category (set status to 0).

```bash
kvs category disable <id>
```

### merge

Merge a source category into a target category.

```bash
kvs category merge <source_id> <target_id>
```

The command moves category associations to the target, drops duplicate associations, updates category references, and deletes the source category.

### assign-group

Bulk-assign categories to a category group.

```bash
kvs category assign-group <group_id> <category_ids...>
```

Use comma-separated IDs or repeated arguments. Group `0` clears the category group.

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--dry-run` | false | Print the planned reassignment without writing changes |

## Default Fields

- `category_id` - Category ID
- `title` - Category title
- `dir` - Directory/slug
- `status` - Status with color

## Examples

### Basic Usage

```bash
# List all categories
kvs category list

# Show category details
kvs category show 5
```

### Filtering

```bash
# Active categories only
kvs category list --status=1

# Inactive categories
kvs category list --status=0
```

### Managing Status

```bash
# Enable a category
kvs category enable 5

# Disable a category
kvs category disable 5
```

### Consolidating Categories

```bash
# Merge one duplicate category into another
kvs category merge 12 15

# Assign country categories to the Countries group
kvs category assign-group 5 12,15,18

# Preview a repeated-argument group assignment
kvs category assign-group 5 12 15 18 --dry-run
```

### Output Formats

```bash
# JSON output
kvs category list --format=json

# CSV export
kvs category list --format=csv > categories.csv

# Count only
kvs category list --format=count
```

### Scripting Examples

```bash
# Count categories by status
echo "Active: $(kvs category list --status=1 --format=count)"
echo "Inactive: $(kvs category list --status=0 --format=count)"

# Export category data
kvs category list --format=json > categories.json

# Disable multiple categories
for id in 5 6 7; do
    kvs category disable $id
done
```

## Available Fields

| Field | Description |
|-------|-------------|
| `category_id` | Unique category ID |
| `title` | Category title |
| `dir` | URL slug |
| `status_id` | Status code (0, 1) |
| `description` | Category description |
| `total_videos` | Number of videos |
| `total_albums` | Number of albums |

## Aliases

- `kvs categories`
- `kvs content:category`

## See Also

- [`tag`](tag.md) - Manage tags
- [`video`](video.md) - Manage videos
- [`album`](album.md) - Manage albums
