# kvs category

Manage categories in your KVS installation.

## Synopsis

```bash
kvs category [<action>] [<id>] [<values>...] [options]
```

## Description

The `category` command allows you to list, inspect, create, update, merge, and
bulk-assign content categories.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `tree`, `show`, `create`, `delete`, `update`, `enable`, `disable`, `merge`, `assign-group` (default: `list`) |
| `id` | Conditional | Category ID, title, source category ID, or category group ID |
| `values` | Conditional | Target category ID or category IDs for merge and assign-group |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--title=TITLE` | - | Category title for `create` or `update` |
| `--description=DESCRIPTION` | - | Category description for `create` or `update` |
| `--group=GROUP` | - | Filter by, or assign to, category group ID or title |
| `--parent=PARENT` | - | Deprecated alias for `--group` |
| `--status=STATUS` | - | Filter or set status (`active`, `inactive`, `disabled`, `0`, `1`) |
| `--limit=N` | 50 | Number of results to show |
| `--search=TEXT` | - | Search in category titles, directories, descriptions, and synonyms |
| `--unused` | - | Show only unused categories |
| `--usage=USAGE` | - | KVS admin usage filter, such as `used/videos` |
| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/description` |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--field=FIELD` | - | Display a single field value |
| `--format=FORMAT` | table | Output format: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `--dry-run` | - | Preview `assign-group` without writing changes |
| `--no-truncate` | - | Do not truncate long text fields |

## Actions

### list

List categories with optional filtering.

```bash
kvs category list [options]
```

### tree

Display the category tree.

```bash
kvs category tree
```

### show

Display details of a specific category.

```bash
kvs category show <id-or-title>
```

### create

Create a new category.

```bash
kvs category create "New Category" --group=5
kvs category create --title="New Category" --description="Description"
```

### update

Update category properties.

```bash
kvs category update <id> --title="Renamed" --status=inactive
```

### enable / disable

Change category status.

```bash
kvs category enable <id>
kvs category disable <id>
```

### merge

Merge a source category into a target category.

```bash
kvs category merge <source_id> <target_id>
```

### assign-group

Bulk-assign categories to a category group.

```bash
kvs category assign-group <group_id> <category_ids...>
kvs category assign-group 5 12,15,18
kvs category assign-group 5 12 15 18 --dry-run
```

Group `0` clears the category group.

### delete

Delete a category.

```bash
kvs category delete <id>
```

## Mutating Actions

The `create`, `update`, `enable`, `disable`, `merge`, `assign-group`, and
`delete` actions modify category data. Use `assign-group --dry-run` to preview a
bulk reassignment before writing changes.

## Available Fields

| Field | Aliases | Description |
|-------|---------|-------------|
| `category_id` | `id` | Category ID |
| `title` | - | Category title |
| `dir` | - | URL slug |
| `description` | - | Category description |
| `status_id` | - | Numeric status ID |
| `status` | - | Status label |
| `category_group` | - | Category group title |
| `category_group_id` | - | Category group ID |
| `video_count` | `videos`, `videos_amount`, `total_videos` | Number of videos |
| `album_count` | `albums`, `albums_amount`, `total_albums` | Number of albums |
| `posts_amount` | `posts` | Number of posts |
| `other_amount` | - | Other usage count |
| `all_amount` | `total_usage` | Total usage count |
| `synonyms` | - | Comma-separated synonyms |
| `screenshot1` | - | First screenshot filename |
| `screenshot2` | - | Second screenshot filename |
| `added_date` | - | Created date |
| `sort_id` | - | Sort ID |

## Usage Filters

The `--usage` option accepts KVS admin usage values:

- `used/videos`
- `used/albums`
- `used/posts`
- `used/other`
- `used/all`
- `notused/videos`
- `notused/albums`
- `notused/posts`
- `notused/other`
- `notused/all`

## Field Filters

The `--field-filter` option accepts `empty/<field>` and `filled/<field>` forms
for KVS admin category fields such as:

- `description`
- `synonyms`
- `group`
- `screenshot1`
- `screenshot2`

Custom category fields can also be filtered when they exist in the KVS
installation.

## Examples

### Basic Usage

```bash
# List categories
kvs category list

# Show category tree
kvs category tree

# Show category details
kvs category show 5
```

### Filtering

```bash
# Status filters
kvs category list --status=active
kvs category list --status=inactive

# Group and search filters
kvs category list --group=0 --unused
kvs category list --search=Canada

# Usage and field filters
kvs category list --usage=used/videos
kvs category list --field-filter=filled/description
```

### Mutating Actions

```bash
# Create and update
kvs category create "New Category" --group=5
kvs category update 3 --title="Renamed" --status=inactive

# Enable and disable
kvs category enable 2
kvs category disable 2

# Merge and assign groups
kvs category merge 12 15
kvs category assign-group 5 12,15,18 --dry-run
```

### Output Formats

```bash
# JSON output
kvs category list --format=json

# CSV export
kvs category list --format=csv > categories.csv

# Count only
kvs category list --format=count

# IDs only
kvs category list --format=ids
```

### Field Selection

```bash
# Specific fields
kvs category list --fields=category_id,title,videos_amount,albums_amount --format=json

# Single field
kvs category list --field=title

# Full text in table output
kvs category list --no-truncate
```

## Aliases

- `kvs categories`
- `kvs cat`
- `kvs content:category`

## See Also

- [`tag`](tag.md) - Manage tags
- [`video`](video.md) - Manage videos
- [`album`](album.md) - Manage albums
