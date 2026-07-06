# kvs model

Manage models and performers in your KVS installation.

## Synopsis

```bash
kvs model [<action>] [<id>] [options]
```

## Description

The `model` command allows you to list, view, and inspect statistics for
model/performer profiles.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `stats` (default: `list`) |
| `id` | Conditional | Model ID, required for `show` |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--status=STATUS` | - | Filter by status (`active`, `disabled`, `inactive`) |
| `--limit=N` | 20 | Number of results to show |
| `--search=TEXT` | - | Search in model names, directories, descriptions, aliases, and gallery URLs |
| `--group=GROUP` | - | Filter by model group ID or title |
| `--model-group=GROUP` | - | Filter by model group ID or title |
| `--tag=TAG` | - | Filter by tag ID or name |
| `--category=CATEGORY` | - | Filter by category ID or title |
| `--usage=USAGE` | - | KVS admin usage filter, such as `used/videos` |
| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/description` |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--field=FIELD` | - | Display a single field value |
| `--format=FORMAT` | table | Output format: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `--no-truncate` | - | Do not truncate long values |

## Actions

### list

List models with optional filtering.

```bash
kvs model list [options]
```

### show

Display details of a specific model.

```bash
kvs model show <id>
```

### stats

Display model totals and relation statistics.

```bash
kvs model stats
```

## Available Fields

| Field | Aliases | Description |
|-------|---------|-------------|
| `model_id` | `id` | Model ID |
| `title` | - | Model name |
| `dir` | - | Directory slug |
| `description` | - | Description |
| `alias` | - | Alternate names |
| `status_id` | - | Numeric status ID |
| `status` | - | Status label (Active/Disabled) |
| `model_group_id` | - | Model group ID |
| `model_group` | - | Model group title |
| `rating` | - | Rating (out of 5) |
| `rating_amount` | - | Number of ratings |
| `model_viewed` | `views` | Profile view count |
| `videos_amount` | `total_videos`, `videos` | Number of videos |
| `albums_amount` | `total_albums`, `albums` | Number of albums |
| `posts_amount` | - | Number of posts |
| `other_amount` | - | Number of DVD or DVD group relations |
| `all_amount` | - | Total content relations |
| `comments_amount` | - | Number of comments |
| `subscribers_amount` | `subscribers_count` | Subscriber count |
| `country` | `country_name` | Country name |
| `city` | - | City |
| `state` | - | State |
| `gender_id` | - | Gender ID |
| `birth_date` | - | Birth date |
| `death_date` | - | Death date |
| `age` | - | Age |
| `measurements` | - | Body measurements |
| `height` | - | Height |
| `weight` | - | Weight |
| `rank` | - | Model rank |
| `tags` | - | Comma-separated tag names |
| `categories` | - | Comma-separated category titles |
| `gallery_url` | - | Gallery URL |
| `added_date` | `date` | Created date |

## Usage Filters

The `--usage` option accepts these KVS admin-style values:

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
for KVS admin model fields such as:

- `description`
- `alias`
- `group`
- `screenshot1`
- `screenshot2`
- `rating`
- `model_viewed`
- `country`
- `city`
- `state`
- `height`
- `weight`
- `hair_id`
- `eye_color_id`
- `measurements`
- `gallery_url`
- `age`
- `tags`
- `categories`

For example, `filled/tags` and `empty/categories` are valid filters.

Custom model fields and enabled model file fields can also be filtered when
they exist in the KVS installation.

## Examples

### Basic Usage

```bash
# List models
kvs model list

# List 50 models
kvs model list --limit=50

# Show model details
kvs model show 7

# Display model statistics
kvs model stats
```

### Filtering

```bash
# Active or inactive models
kvs model list --status=active
kvs model list --status=inactive

# Search by name, directory, description, alias, or gallery URL
kvs model list --search="Jane"

# Filter by model group, tag, or category
kvs model list --group=Featured
kvs model list --model-group=Featured
kvs model list --tag=training
kvs model list --category=Featured

# KVS admin usage and field filters
kvs model list --usage=used/videos
kvs model list --field-filter=filled/description
```

### Output Formats

```bash
# JSON output
kvs model list --format=json

# CSV export
kvs model list --format=csv > models.csv

# Count only
kvs model list --format=count

# IDs only
kvs model list --format=ids
```

### Custom Fields

```bash
# Specific fields
kvs model list --fields=model_id,title,videos_amount,albums_amount --format=json

# Alias fields
kvs model list --fields=id,title,videos,albums

# Single field
kvs model list --field=title

# Full text in table output
kvs model list --no-truncate
```

## Aliases

- `kvs models`
- `kvs performer`
- `kvs performers`
- `kvs content:model`

## See Also

- [`video`](video.md) - Manage videos
- [`album`](album.md) - Manage albums
- [`dvd`](dvd.md) - Manage DVDs/channels
