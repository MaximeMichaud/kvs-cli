# kvs video

Manage videos in your KVS installation.

## Synopsis

```bash
kvs video [<action>] [<id>] [options]
```

## Description

The `video` command allows you to list, view, delete, and inspect statistics for
video content.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `delete`, `stats` (default: `list`) |
| `id` | Conditional | Video ID, required for `show` and `delete` |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--status=STATUS` | - | Filter by status (`active`, `disabled`, `error`, `processing`, `deleting`, `deleted`) |
| `--limit=N` | 20 | Number of results to show |
| `--search=TEXT` | - | Search in titles, directories, descriptions, URLs, and custom fields |
| `--resolution=RESOLUTION` | - | Filter by KVS resolution type ID |
| `--load-type=TYPE` | - | Filter by KVS load type ID (`0`, `1`, `2`, `3`, `5`) |
| `--category=CATEGORY` | - | Filter by category ID or title |
| `--category-group=GROUP` | - | Filter by category group ID or title |
| `--tag=TAG` | - | Filter by tag ID or name |
| `--model=MODEL` | - | Filter by model ID or title |
| `--model-group=GROUP` | - | Filter by model group ID or title |
| `--content-source=SOURCE` | - | Filter by content source ID or title |
| `--content-source-group=GROUP` | - | Filter by content source group ID or title |
| `--dvd=DVD` | - | Filter by DVD ID or title |
| `--dvd-group=GROUP` | - | Filter by DVD group ID or title |
| `--playlist=PLAYLIST` | - | Filter by playlist ID or title |
| `--user=USER` | - | Filter by user ID or username |
| `--admin-user=ADMIN` | - | Filter by admin user ID or login |
| `--ip=IP` | - | Filter by IP address |
| `--server-group=GROUP` | - | Filter by storage server group ID or title |
| `--format-video-group=GROUP` | - | Filter by video format group ID or title |
| `--feed=FEED` | - | Filter by import feed ID |
| `--has-errors=ERROR` | - | Filter by KVS processing error bit (`1`, `10`, `100`, `1000`, `10000`) |
| `--posted=POSTED` | - | Filter by public posting state (`yes`, `no`) |
| `--neuroscore=STATE` | - | Filter by KVS Neuroscore operation state |
| `--digiregs-copyright=STATE` | - | Filter by KVS DigiRegs copyright state |
| `--show-id=SHOW` | - | Filter by KVS admin show ID |
| `--public` | - | Show only public videos |
| `--private` | - | Show only private videos |
| `--premium` | - | Show only premium videos |
| `--access-level=LEVEL` | - | Filter by access level (0-3) |
| `--review-needed` | - | Show only videos that need review |
| `--not-review-needed` | - | Show only videos that do not need review |
| `--locked` | - | Show only locked videos |
| `--unlocked` | - | Show only unlocked videos |
| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/tags` |
| `--flag=FLAG` | - | Filter by admin or user flag ID |
| `--flag-votes=VOTES` | 1 | Minimum user flag votes for `--flag` |
| `--post-date-from=DATE` | - | Filter by minimum post date (`YYYY-MM-DD`) |
| `--post-date-to=DATE` | - | Filter by maximum post date (`YYYY-MM-DD`) |
| `--duration-from=SECONDS` | - | Filter by minimum duration in seconds |
| `--duration-to=SECONDS` | - | Filter by maximum duration in seconds |
| `--stats` | - | Show video statistics without using the `stats` action |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--field=FIELD` | - | Display a single field value |
| `--format=FORMAT` | table | Output format: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `--no-truncate` | - | Do not truncate long values |

## Actions

### list

List videos with optional filtering.

```bash
kvs video list [options]
```

### show

Display details of a specific video.

```bash
kvs video show <id>
```

### delete

Delete a video through KVS native cleanup.

```bash
kvs video delete <id>
```

### stats

Display video totals and top-viewed video statistics.

```bash
kvs video stats
```

You can also use `kvs video --stats`.

## Mutating Actions

The `delete` action modifies video data and uses KVS native cleanup. It prompts
for confirmation before deleting. Run `show` first if you need to confirm the
target video.

## Available Fields

| Field | Aliases | Description |
|-------|---------|-------------|
| `video_id` | `id` | Video ID |
| `title` | - | Video title |
| `dir` | - | Directory slug |
| `description` | - | Description |
| `status_id` | - | Numeric status ID |
| `status` | - | Video status |
| `load_type_id` | - | KVS load type ID |
| `resolution_type` | `resolution` | KVS resolution type |
| `is_private` | `type` | Public, Private, or Premium |
| `access_level_id` | `access` | Access level |
| `user_id` | - | Owner user ID |
| `username` | `user` | Owner username |
| `user_status_id` | - | Owner user status ID |
| `admin_user_id` | - | Admin user ID |
| `admin_user` | - | Admin login |
| `content_source_id` | - | Content source ID |
| `content_source` | - | Content source title |
| `dvd_id` | - | DVD ID |
| `dvd` | - | DVD title |
| `format_video_group_id` | - | Video format group ID |
| `format_video_group` | - | Video format group title |
| `server_group_id` | - | Storage server group ID |
| `server_group` | - | Storage server group title |
| `admin_flag_id` | - | Admin flag ID |
| `admin_flag` | - | Admin flag title |
| `duration` | - | Duration |
| `file_size` | `filesize` | File size |
| `file_dimensions` | - | File dimensions |
| `file_formats` | - | Available format data |
| `video_viewed` | `views` | View count |
| `video_viewed_player` | - | Player view count |
| `video_viewed_unique` | - | Unique view count |
| `comments_count` | - | Number of comments |
| `favourites_count` | `favourites` | Number of favourites |
| `purchases_count` | - | Number of purchases |
| `rating` | - | Rating (out of 5) |
| `rating_amount` | - | Number of ratings |
| `r_ctr` | - | Rotator CTR percentage |
| `screen_amount` | - | Overview screenshot count |
| `screen_main` | - | Main overview screenshot index |
| `poster_amount` | - | Poster count |
| `poster_main` | - | Main poster index |
| `tags` | - | Comma-separated tag names |
| `categories` | - | Comma-separated category titles |
| `models` | - | Comma-separated model names |
| `ip` | - | IP address |
| `gallery_url` | - | Gallery URL |
| `post_date` | `date` | Posted date |
| `added_date` | - | Created date |
| `last_time_view_date` | - | Last view date |
| `release_year` | - | Release year |
| `is_locked` | - | Website lock flag |
| `is_review_needed` | - | Review-needed flag |
| `has_errors` | - | Processing error flag |
| `feed_id` | - | Import feed ID |
| `thumb` | - | Overview thumbnail URL |
| `website_link` | - | Public website link |

## Field Filters

The `--field-filter` option accepts `empty/<field>` and `filled/<field>` forms
for KVS admin video fields such as:

- `title`
- `description`
- `gallery_url`
- `custom1`
- `custom2`
- `custom3`
- `af_custom1`
- `af_custom2`
- `af_custom3`
- `tokens_required`
- `video_viewed`
- `video_viewed_unique`
- `content_source`
- `dvd`
- `admin`
- `admin_flag`
- `comments`
- `favourites`
- `purchases`
- `rating`
- `tags`
- `categories`
- `models`

For example, `filled/tags`, `empty/comments`, and `filled/video_viewed` are
valid filters.

## Advanced Operation Filters

The `--neuroscore` option accepts these KVS admin states when the Neuroscore
plugin data exists:

- `score_missing`
- `score_postponed`
- `score_processing`
- `score_finished`
- `title_missing`
- `title_postponed`
- `title_processing`
- `title_finished`
- `categories_missing`
- `categories_postponed`
- `categories_processing`
- `categories_finished`
- `models_missing`
- `models_postponed`
- `models_processing`
- `models_finished`

The `--digiregs-copyright` option accepts:

- `copyright_applied`
- `copyright_not_applied`
- `copyright_empty`
- `copyright_studio`
- `copyright_watermark`

## Show ID Filters

The `--show-id` option accepts KVS admin show IDs and special values such as:

- `15`, `16`, `17`, `18`, `19`, `20`, `21`, `22`, `23`, `24`, `25`
- `is_vertical`
- `is_horizontal`
- `wf/<postfix>` and `wof/<postfix>` for videos with or without a format
- `wq/<height>` and `woq/<height>` for videos with or without a quality

## Examples

### Basic Usage

```bash
# List first 20 videos
kvs video list

# List 50 videos
kvs video list --limit=50

# Show video details
kvs video show 123

# Display video statistics
kvs video stats
```

### Filtering

```bash
# Status filters
kvs video list --status=active
kvs video list --status=processing

# Visibility filters
kvs video list --public
kvs video list --private
kvs video list --premium

# Relation filters
kvs video list --user=alice
kvs video list --category=Featured
kvs video list --tag=training
kvs video list --model=7
kvs video list --content-source=Studio
kvs video list --dvd=Channel
kvs video list --playlist=Favorites

# Admin filters
kvs video list --admin-user=admin
kvs video list --server-group=Default
kvs video list --format-video-group=MP4
kvs video list --feed=1

# Moderation and field filters
kvs video list --review-needed
kvs video list --locked
kvs video list --flag=2 --flag-votes=2
kvs video list --field-filter=filled/tags

# Date, duration, and search filters
kvs video list --post-date-from=2026-01-01 --post-date-to=2026-12-31
kvs video list --duration-from=60 --duration-to=300
kvs video list --search="Trailer"
```

### Output Formats

```bash
# JSON output
kvs video list --format=json

# CSV export
kvs video list --format=csv > videos.csv

# YAML output
kvs video list --format=yaml

# Count only
kvs video list --format=count

# IDs only
kvs video list --format=ids
```

### Field Selection

```bash
# Specific fields
kvs video list --fields=video_id,title,duration,video_viewed,status_id --format=json

# Alias fields
kvs video list --fields=id,title,views,user

# KVS admin relation fields
kvs video list --fields=video_id,title,tags,categories,models,content_source,dvd --format=json

# Single field
kvs video list --field=title

# Full text in table output
kvs video list --no-truncate
```

### Mutating Usage

```bash
# Confirm the target first
kvs video show 123

# Delete through KVS native cleanup
kvs video delete 123
```

## Aliases

- `kvs videos`
- `kvs content:video`

## See Also

- [`video:formats`](video_formats.md) - Inspect video files and formats
- [`video:screenshots`](video_screenshots.md) - Manage screenshots
- [`album`](album.md) - Manage albums
- [`user`](user.md) - Manage users
- [`comment`](comment.md) - Manage comments
