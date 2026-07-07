# kvs album

Manage photo albums in your KVS installation.

## Synopsis

```bash
kvs album [<action>] [<id>] [options]
```

## Description

The `album` command allows you to list, view, and delete photo album content.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `delete` (default: `list`) |
| `id` | Conditional | Album ID, required for `show` and `delete` |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--status=STATUS` | - | Filter by status (`active`, `disabled`, `error`, `processing`, `deleting`, `deleted`) |
| `--limit=N` | 20 | Number of results |
| `--user=USER` | - | Filter by user ID or username |
| `--category=CATEGORY` | - | Filter by category ID or title |
| `--tag=TAG` | - | Filter by tag ID or name |
| `--model=MODEL` | - | Filter by model ID or title |
| `--content-source=SOURCE` | - | Filter by content source ID or title |
| `--content-source-group=GROUP` | - | Filter by content source group ID or title |
| `--category-group=GROUP` | - | Filter by category group ID or title |
| `--model-group=GROUP` | - | Filter by model group ID or title |
| `--public` | - | Show only public albums |
| `--private` | - | Show only private albums |
| `--premium` | - | Show only premium albums |
| `--access-level=LEVEL` | - | Filter by access level (0-3) |
| `--admin-user=ADMIN` | - | Filter by admin user ID or login |
| `--ip=IP` | - | Filter by IP address |
| `--server-group=GROUP` | - | Filter by storage server group ID or title |
| `--review-needed` | - | Show only albums that need review |
| `--not-review-needed` | - | Show only albums that do not need review |
| `--locked` | - | Show only locked albums |
| `--unlocked` | - | Show only unlocked albums |
| `--has-errors=ERROR` | - | Filter by KVS processing error bit (`1`, `10`) |
| `--posted=POSTED` | - | Filter by public posting state (`yes`, `no`) |
| `--show-id=SHOW` | - | Filter by KVS admin show ID |
| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/tags` |
| `--flag=FLAG` | - | Filter by admin or user flag ID |
| `--flag-votes=VOTES` | 1 | Minimum user flag votes for `--flag` |
| `--post-date-from=DATE` | - | Filter by minimum post date (`YYYY-MM-DD`) |
| `--post-date-to=DATE` | - | Filter by maximum post date (`YYYY-MM-DD`) |
| `--search=TEXT` | - | Search in album titles, directories, and descriptions |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--field=FIELD` | - | Display a single field value |
| `--format=FORMAT` | table | Output format: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `--no-truncate` | - | Do not truncate long values |

## Actions

### list

List albums with optional filtering.

```bash
kvs album list [options]
```

### show

Display details of a specific album.

```bash
kvs album show <id>
```

### delete

Delete an album through KVS native cleanup.

```bash
kvs album delete <id>
```

## Mutating Actions

The `delete` action modifies album data and uses KVS native cleanup. Run `show`
first if you need to confirm the target album.

## Available Fields

| Field | Aliases | Description |
|-------|---------|-------------|
| `album_id` | `id` | Album ID |
| `title` | - | Album title |
| `dir` | - | Directory slug |
| `description` | - | Description |
| `status_id` | - | Numeric status ID |
| `status` | - | Album status |
| `is_private` | `type` | Public, Private, or Premium |
| `access_level_id` | `access` | Access level |
| `user_id` | - | Owner user ID |
| `username` | `user` | Owner username |
| `photos_amount` | `images` | Number of images |
| `album_viewed` | `views` | View count |
| `album_viewed_unique` | - | Unique view count |
| `comments_count` | - | Number of comments |
| `favourites_count` | - | Number of favourites |
| `purchases_count` | - | Number of purchases |
| `rating` | - | Rating (out of 5) |
| `tags` | - | Comma-separated tag names |
| `categories` | - | Comma-separated category titles |
| `models` | - | Comma-separated model names |
| `content_source` | - | Content source title |
| `admin_flag` | - | Admin flag title |
| `server_group` | - | Storage server group title |
| `ip` | - | IP address |
| `post_date` | `date` | Posted date |
| `added_date` | - | Created date |

## Field Filters

The `--field-filter` option accepts `empty/<field>` and `filled/<field>` forms
for KVS admin album fields such as:

- `title`
- `description`
- `gallery_url`
- `content_source`
- `admin`
- `admin_flag`
- `tokens_required`
- `album_viewed`
- `album_viewed_unique`
- `comments`
- `favourites`
- `purchases`
- `rating`
- `tags`
- `categories`
- `models`

Custom album fields can also be filtered when they exist in the KVS installation.

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
# Status filters
kvs album list --status=active
kvs album list --status=processing

# Visibility filters
kvs album list --public
kvs album list --private
kvs album list --premium

# Relation filters
kvs album list --user=alice
kvs album list --category=Featured
kvs album list --tag=training
kvs album list --model=7
kvs album list --content-source=Studio

# Moderation and admin filters
kvs album list --review-needed
kvs album list --locked
kvs album list --flag=2 --flag-votes=2
kvs album list --field-filter=filled/tags

# Date and search filters
kvs album list --post-date-from=2026-01-01 --post-date-to=2026-12-31
kvs album list --search="Outdoor"
```

### Output Formats

```bash
# JSON output
kvs album list --format=json

# CSV export
kvs album list --format=csv > albums.csv

# Count only
kvs album list --format=count

# IDs only
kvs album list --format=ids
```

### Field Selection

```bash
# Specific fields
kvs album list --fields=album_id,title,photos_amount,album_viewed --format=json

# Alias fields
kvs album list --fields=id,title,images,user

# Single field
kvs album list --field=title

# Full text in table output
kvs album list --no-truncate
```

## Aliases

- `kvs albums`
- `kvs gallery`
- `kvs content:album`

## See Also

- [`video`](video.md) - Manage videos
- [`user`](user.md) - Manage users
- [`comment`](comment.md) - Manage comments
