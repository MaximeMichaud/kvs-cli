# kvs content:playlist

Manage KVS playlists.

## Synopsis

```bash
kvs content:playlist [<action>] [<id>] [options]
```

## Description

The `content:playlist` command allows you to list, view, and delete user playlists in your KVS installation.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `delete` (default: `list`) |
| `id` | Conditional | Playlist ID (required for `show` and `delete`) |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--status=STATUS` | - | Filter by status (`active`, `disabled`) |
| `--user=ID` | - | Filter by user ID |
| `--public` | - | Show only public playlists |
| `--private` | - | Show only private playlists |
| `--search=TEXT` | - | Search in titles and descriptions |
| `--limit=N` | 20 | Number of results |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--format=FORMAT` | table | Output format |
| `--no-truncate` | - | Don't truncate long values |

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

### delete

Delete a playlist.

```bash
kvs playlist delete <id>
```

## Available Fields

| Field | Aliases | Description |
|-------|---------|-------------|
| `playlist_id` | `id` | Playlist ID |
| `title` | - | Playlist title |
| `status` | - | Status (Active/Disabled) |
| `type` | - | Public or Private |
| `videos` | - | Number of videos |
| `username` | `user` | Owner username |
| `views` | - | View count |
| `rating` | - | Rating (out of 5) |
| `added_date` | `date` | Created date |

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

### Filter by Visibility

```bash
# Public playlists
kvs playlist list --public

# Private playlists
kvs playlist list --private

# User's private playlists
kvs playlist list --private --user=5
```

### Filter by User

```bash
# Playlists by user 5
kvs playlist list --user=5

# Active playlists by user 10
kvs playlist list --user=10 --status=active
```

### Search Playlists

```bash
# Search by title/description
kvs playlist list --search="favorites"

# Search in user's playlists
kvs playlist list --user=5 --search="best"
```

### View Playlist Details

```bash
# Show playlist 1
kvs playlist show 1

# Show playlist 42
kvs playlist show 42
```

### Delete Playlist

```bash
# Delete playlist 10
kvs playlist delete 10
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
 4    Holiday Special        Disabled Public  12      sarah     234     4.2/5
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
# Public playlists, most popular first (manual sort by views)
kvs playlist list --public --format=json | jq 'sort_by(-.views) | .[:10]'
```

### Moderate Playlists

```bash
# Review playlists with specific keywords
kvs playlist list --search="spam"

# Check a user's playlists
kvs playlist list --user=suspicious_user_id
```

### Export for Backup

```bash
# Export all playlists
kvs playlist list --limit=10000 --format=json > playlists-backup.json

# Export with full titles (no truncation)
kvs playlist list --no-truncate --format=csv > playlists.csv
```

### Clean Up

```bash
# Find playlists by inactive users (requires combining with user command)
# Delete specific playlist
kvs playlist delete 123
```

## Aliases

- `kvs playlist`
- `kvs playlists`

## Notes

- Public playlists are visible to all users
- Private playlists are only visible to their owners
- Deleting a playlist doesn't delete the videos in it
- View count is separate from video view counts
- Rating is for the playlist itself, not the videos

## See Also

- [`video`](video.md) - Manage videos
- [`user`](user.md) - Manage users
- [`dvd`](dvd.md) - Manage DVDs/channels (similar concept)
