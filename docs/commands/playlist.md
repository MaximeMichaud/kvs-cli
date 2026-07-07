# kvs content:playlist

Manage KVS playlists.

## Synopsis

```bash
kvs content:playlist [<action>] [<id>] [options]
```

## Description

The `content:playlist` command allows you to list, view, create, modify, and delete user playlists in your KVS installation.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `create`, `add`, `remove`, `delete` (default: `list`) |
| `id` | Conditional | Playlist ID, or playlist title for `create` |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--status=STATUS` | - | Filter by status (`active`, `disabled`, `inactive`) |
| `--user=USER` | - | Filter by user ID or username; `create` requires a numeric user ID |
| `--public` | - | Show only public playlists; create a public playlist |
| `--private` | - | Show only private playlists; create a private playlist |
| `--search=TEXT` | - | Search in titles, directories, and descriptions |
| `--category=CATEGORY` | - | Filter by category ID or title |
| `--tag=TAG` | - | Filter by tag ID or name |
| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/videos` |
| `--flag=FLAG` | - | Filter by flag ID |
| `--flag-votes=VOTES` | 1 | Minimum flag votes for `--flag` |
| `--review-needed` | - | Show only playlists that need review |
| `--not-review-needed` | - | Show only playlists that do not need review |
| `--locked` | - | Show only locked playlists |
| `--unlocked` | - | Show only unlocked playlists |
| `--title=TITLE` | - | Playlist title for `create` |
| `--description=DESCRIPTION` | - | Playlist description for `create` |
| `--dir=DIR` | - | Playlist directory slug for `create` |
| `--limit=N` | 20 | Number of results |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--field=FIELD` | - | Display a single field value |
| `--format=FORMAT` | table | Output format: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `--no-truncate` | - | Do not truncate long values |
| `--video=VIDEO` | - | Video ID, required for `add` and `remove` |
| `-y, --yes` | - | Skip confirmation prompt for `delete` |

## Actions

### list

List playlists with optional filtering.

```bash
kvs playlist list [options]
```

### show

Display details of a specific playlist.

```bash
kvs playlist show <id>
```

### create

Create a playlist for a user.

```bash
kvs playlist create "Favorites" --user=1 --private
kvs playlist create --title="Favorites" --user=1 --description="Saved videos" --dir=favorites
```

### add

Add a video to a playlist.

```bash
kvs playlist add <playlist_id> --video=<video_id>
```

### remove

Remove a video from a playlist.

```bash
kvs playlist remove <playlist_id> --video=<video_id>
```

### delete

Delete a playlist.

```bash
kvs playlist delete <id>
kvs playlist delete <id> --yes
```

## Mutating Actions

The `create`, `add`, `remove`, and `delete` actions modify playlist data. Run
`list` or `show` first if you need to confirm the target playlist or video IDs.

## Available Fields

| Field | Aliases | Description |
|-------|---------|-------------|
| `playlist_id` | `id` | Playlist ID |
| `title` | - | Playlist title |
| `dir` | - | Playlist directory slug |
| `description` | - | Playlist description |
| `status_id` | - | Numeric status ID |
| `status` | - | Status label (Active/Disabled) |
| `is_private` | - | Numeric visibility flag |
| `type` | - | Public or Private |
| `user_id` | - | Owner user ID |
| `username` | `user` | Owner username |
| `user_status_id` | - | Owner status ID |
| `rating` | - | Rating (out of 5) |
| `playlist_viewed` | `views` | View count |
| `tags` | - | Comma-separated tag names |
| `categories` | - | Comma-separated category titles |
| `is_locked` | - | Locked flag |
| `is_review_needed` | - | Review flag |
| `videos_amount` | `total_videos`, `videos` | Number of videos |
| `comments_amount` | - | Number of comments |
| `added_date` | `date` | Created date |
| `last_content_date` | - | Last content update date |

## Field Filters

The `--field-filter` option accepts these KVS admin-style values:

- `empty/description`
- `empty/playlist_viewed`
- `empty/rating`
- `empty/tags`
- `empty/categories`
- `empty/videos`
- `filled/description`
- `filled/playlist_viewed`
- `filled/rating`
- `filled/tags`
- `filled/categories`
- `filled/videos`

## Status Values

| Value | Status | Description |
|-------|--------|-------------|
| 0 | Disabled | Playlist is inactive |
| 1 | Active | Playlist is visible |

## Examples

### List Playlists

```bash
# First 20 playlists
kvs playlist list

# 50 playlists
kvs playlist list --limit=50

# Active playlists only
kvs playlist list --status=active
```

### Filter Playlists

```bash
# Public playlists
kvs playlist list --public

# Private playlists for a user
kvs playlist list --private --user=alice

# Playlists in a category or tag
kvs playlist list --category=Featured
kvs playlist list --tag=training

# Playlists that need moderation
kvs playlist list --review-needed
kvs playlist list --flag=7 --flag-votes=2

# Admin field filters
kvs playlist list --field-filter=filled/videos
kvs playlist list --field-filter=empty/tags
```

### Search Playlists

```bash
# Search by title, directory, or description
kvs playlist list --search="favorites"

# Search in a user's playlists
kvs playlist list --user=5 --search="best"
```

### View Playlist Details

```bash
# Show playlist 1
kvs playlist show 1

# Show selected fields as JSON
kvs playlist show 1 --fields=playlist_id,title,dir,description,user,status_id,is_private --format=json
```

### Modify Playlists

```bash
# Create a playlist
kvs playlist create "Favorites" --user=1 --private

# Add a video
kvs playlist add 1 --video=42

# Remove a video
kvs playlist remove 1 --video=42

# Delete a playlist
kvs playlist delete 10 --yes
```

### Output Formats

```bash
# JSON output
kvs playlist list --format=json

# CSV export
kvs playlist list --format=csv > playlists.csv

# Count only
kvs playlist list --format=count

# IDs only
kvs playlist list --format=ids
```

### Custom Fields

```bash
# Specific fields
kvs playlist list --fields=id,title,videos,views

# KVS admin-style fields
kvs playlist list --fields=playlist_id,title,videos_amount,playlist_viewed --format=json

# Single field
kvs playlist list --field=title

# No truncation
kvs playlist list --no-truncate
```

## Sample Output

### List

```
Playlists
=========

 ID   Title                  Status  Type    Videos  User      Views   Rating
 1    My Favorites           Active  Public  23      john      1,234   4.5/5
 2    Watch Later            Active  Private 15      jane      0       -
 3    Top Rated Collection   Active  Public  45      admin     5,678   4.8/5
 4    Holiday Special        Disabled Public 12      sarah     234     4.2/5
```

### Show

```
Playlist #1
===========

 ID              1
 Title           My Favorites
 Description     Collection of my favorite videos
 Status          Active
 Type            Public
 Videos          23
 Owner           john (ID: 5)
 Views           1,234
 Rating          4.5/5 (from 45 ratings)
 Created         2024-01-15 10:30:00
 Modified        2024-12-20 14:15:00
```

## Use Cases

### Find Popular Playlists

```bash
# Public playlists, most popular first
kvs playlist list --public --format=json | jq 'sort_by(-.views) | .[:10]'
```

### Moderate Playlists

```bash
# Review flagged playlists
kvs playlist list --review-needed
kvs playlist list --flag=7 --flag-votes=2

# Check a user's playlists
kvs playlist list --user=suspicious_user_id
```

### Export for Backup

```bash
# Export all playlists
kvs playlist list --limit=10000 --format=json > playlists-backup.json

# Export with full titles and descriptions
kvs playlist list --no-truncate --format=csv > playlists.csv
```

## Aliases

- `kvs playlist`
- `kvs playlists`

## Notes

- Public playlists are visible to all users.
- Private playlists are only visible to their owners.
- Deleting a playlist does not delete the videos in it.
- View count is separate from video view counts.
- Rating is for the playlist itself, not the videos.

## See Also

- [`video`](video.md) - Manage videos
