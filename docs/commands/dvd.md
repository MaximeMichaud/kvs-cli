# kvs dvd

Manage DVDs, channels, and series in your KVS installation.

## Synopsis

```bash
kvs dvd [<action>] [<id>] [options]
```

## Description

The `dvd` command allows you to list, view, and inspect statistics for DVD,
channel, or series records.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `stats` (default: `list`) |
| `id` | Conditional | DVD ID, required for `show` |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--status=STATUS` | - | Filter by status (`active`, `disabled`, `inactive`) |
| `--limit=N` | 20 | Number of results to show |
| `--search=TEXT` | - | Search in DVD titles, directories, descriptions, and synonyms |
| `--user=USER` | - | Filter by user ID or username |
| `--group=GROUP` | - | Filter by DVD group ID or title |
| `--dvd-group=GROUP` | - | Filter by DVD group ID or title |
| `--tag=TAG` | - | Filter by tag ID or name |
| `--category=CATEGORY` | - | Filter by category ID or title |
| `--model=MODEL` | - | Filter by model ID or title |
| `--usage=USAGE` | - | KVS admin usage filter (`used/videos`, `notused/videos`) |
| `--review-needed` | - | Show only DVDs that need review |
| `--not-review-needed` | - | Show only DVDs that do not need review |
| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/tags` |
| `--flag=FLAG` | - | Filter by user flag ID |
| `--flag-votes=VOTES` | 1 | Minimum user flag votes for `--flag` |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--field=FIELD` | - | Display a single field value |
| `--format=FORMAT` | table | Output format: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `--no-truncate` | - | Do not truncate long values |

## Actions

### list

List DVDs with optional filtering.

```bash
kvs dvd list [options]
```

### show

Display details of a specific DVD.

```bash
kvs dvd show <id>
```

### stats

Display DVD totals.

```bash
kvs dvd stats
```

## Available Fields

| Field | Aliases | Description |
|-------|---------|-------------|
| `dvd_id` | `id` | DVD ID |
| `title` | - | DVD, channel, or series title |
| `dir` | - | Directory slug |
| `description` | - | Description |
| `synonyms` | - | Synonyms |
| `status_id` | - | Numeric status ID |
| `status` | - | Status label (Active/Disabled) |
| `dvd_group_id` | - | DVD group ID |
| `dvd_group` | - | DVD group title |
| `dvd_group_status_id` | - | DVD group status ID |
| `user_id` | - | Owner user ID |
| `user` | - | Owner username |
| `total_videos` | `videos_amount`, `videos` | Number of videos |
| `total_videos_duration` | - | Total duration in seconds |
| `total_duration` | `duration` | Formatted total duration |
| `release_year` | - | Release year |
| `dvd_viewed` | `views` | View count |
| `rating` | - | Rating (out of 5) |
| `rating_amount` | - | Number of ratings |
| `comments_amount` | - | Number of comments |
| `subscribers_count` | `subscribers_amount`, `subscribers` | Subscriber count |
| `tags` | - | Comma-separated tag names |
| `categories` | - | Comma-separated category titles |
| `models` | - | Comma-separated model names |
| `added_date` | `date` | Created date |

## Usage Filters

The `--usage` option accepts:

- `used/videos`
- `notused/videos`

## Field Filters

The `--field-filter` option accepts `empty/<field>` and `filled/<field>` forms
for KVS admin DVD fields such as:

- `description`
- `synonyms`
- `group`
- `user`
- `cover1_front`
- `cover1_back`
- `cover2_front`
- `cover2_back`
- `rating`
- `dvd_viewed`
- `tokens_required`
- `tags`
- `categories`
- `models`

Custom DVD fields and enabled DVD file fields can also be filtered when they
exist in the KVS installation.

## Examples

### Basic Usage

```bash
# List DVDs
kvs dvd list

# List 50 DVDs
kvs dvd list --limit=50

# Show DVD details
kvs dvd show 1

# Display DVD statistics
kvs dvd stats
```

### Filtering

```bash
# Active DVDs only
kvs dvd list --status=active

# Search by title, directory, description, or synonyms
kvs dvd list --search="Series"

# Filter by group, tag, category, model, or user
kvs dvd list --group=Travel
kvs dvd list --dvd-group=Travel
kvs dvd list --tag=training
kvs dvd list --category=Featured
kvs dvd list --model=7
kvs dvd list --user=alice

# KVS admin usage, review, flag, and field filters
kvs dvd list --usage=used/videos
kvs dvd list --review-needed
kvs dvd list --flag=3 --flag-votes=2
kvs dvd list --field-filter=filled/tags
```

### Output Formats

```bash
# JSON output
kvs dvd list --format=json

# CSV export
kvs dvd list --format=csv > dvds.csv

# Count only
kvs dvd list --format=count

# IDs only
kvs dvd list --format=ids
```

### Custom Fields

```bash
# Specific fields
kvs dvd list --fields=dvd_id,title,total_videos,dvd_viewed --format=json

# Alias fields
kvs dvd list --fields=id,title,videos,views,release_year

# Single field
kvs dvd list --field=title

# Full text in table output
kvs dvd list --no-truncate
```

## Aliases

- `kvs dvds`
- `kvs channel`
- `kvs channels`
- `kvs content:dvd`

## See Also

- [`video`](video.md) - Manage videos
- [`model`](model.md) - Manage models
