# kvs tag

Manage tags in your KVS installation.

## Synopsis

```bash
kvs tag [<action>] [<identifier>] [<target>] [options]
```

## Description

The `tag` command allows you to list, inspect, create, update, merge, and delete
content tags.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `create`, `delete`, `merge`, `update`, `enable`, `disable`, `stats` (default: `list`) |
| `identifier` | Conditional | Tag ID or name |
| `target` | Conditional | Target tag ID for `merge` |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--name=NAME` | - | New tag name for `update` |
| `--status=STATUS` | - | Filter or set status (`active`, `inactive`, `disabled`, `0`, `1`) |
| `--limit=N` | 50 | Number of results to show |
| `--search=TEXT` | - | Search in tag names, directories, and synonyms |
| `--unused` | - | Show only unused tags |
| `--usage=USAGE` | - | KVS admin usage filter, such as `used/videos` |
| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/synonyms` |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--field=FIELD` | - | Display a single field value |
| `--format=FORMAT` | table | Output format: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `--no-truncate` | - | Do not truncate long text fields |

## Actions

### list

List tags with optional filtering.

```bash
kvs tag list [options]
```

### show

Display details of a specific tag.

```bash
kvs tag show <id-or-name>
```

### create

Create a new tag.

```bash
kvs tag create "4K UHD"
```

### update

Update a tag name or status.

```bash
kvs tag update <id> --name="Ultra HD"
kvs tag update <id> --status=inactive
```

### enable / disable

Change tag status.

```bash
kvs tag enable <id>
kvs tag disable <id>
```

### merge

Merge a source tag into a target tag.

```bash
kvs tag merge <source_id> <target_id>
```

### stats

Display tag totals and the most used tags.

```bash
kvs tag stats
```

### delete

Delete a tag.

```bash
kvs tag delete <id>
```

## Mutating Actions

The `create`, `update`, `enable`, `disable`, `merge`, and `delete` actions
modify tag data.

## Available Fields

| Field | Aliases | Description |
|-------|---------|-------------|
| `tag_id` | `id` | Tag ID |
| `tag` | `tag_rename` | Tag name |
| `tag_dir` | - | URL slug |
| `synonyms` | - | Comma-separated synonyms |
| `status_id` | - | Numeric status ID |
| `status` | - | Status label |
| `video_count` | `videos`, `videos_amount` | Number of videos |
| `album_count` | `albums`, `albums_amount` | Number of albums |
| `posts_amount` | `posts` | Number of posts |
| `other_amount` | - | Other usage count |
| `all_amount` | `total_usage` | Total usage count |
| `added_date` | - | Created date |

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
for KVS admin tag fields such as:

- `synonyms`

Custom tag fields can also be filtered when they exist in the KVS installation.

## Examples

### Basic Usage

```bash
# List tags
kvs tag list

# Show tag details
kvs tag show 5

# Display tag statistics
kvs tag stats
```

### Filtering

```bash
# Status filters
kvs tag list --status=active
kvs tag list --status=inactive

# Search, unused, and admin filters
kvs tag list --search=HD
kvs tag list --unused
kvs tag list --usage=used/videos
kvs tag list --field-filter=filled/synonyms
```

### Mutating Actions

```bash
# Create and update
kvs tag create "4K UHD"
kvs tag update 5 --name="Ultra HD"

# Enable and disable
kvs tag enable 3
kvs tag disable 3

# Merge and delete
kvs tag merge 10 15
kvs tag delete 8
```

### Output Formats

```bash
# JSON output
kvs tag list --format=json

# CSV export
kvs tag list --format=csv > tags.csv

# Count only
kvs tag list --format=count

# IDs only
kvs tag list --format=ids
```

### Field Selection

```bash
# Specific fields
kvs tag list --fields=tag_id,tag,tag_dir,videos_amount,albums_amount --format=json

# Single field
kvs tag list --field=tag

# Full text in table output
kvs tag list --no-truncate
```

## Aliases

- `kvs tags`
- `kvs content:tag`

## See Also

- [`category`](category.md) - Manage categories
- [`video`](video.md) - Manage videos
- [`album`](album.md) - Manage albums
