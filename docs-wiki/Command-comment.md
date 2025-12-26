# kvs comment

Manage comments in your KVS installation.

## Synopsis

```bash
kvs comment <action> [<id>] [options]
```

## Description

The `comment` command allows you to list, view, and get statistics about comments on videos and albums.

## Actions

### list

List comments with optional filtering.

```bash
kvs comment list [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--limit=<n>` | 20 | Maximum number of results |
| `--offset=<n>` | 0 | Skip first N results |
| `--video=<id>` | - | Filter by video ID |
| `--album=<id>` | - | Filter by album ID |
| `--user=<id>` | - | Filter by user ID |
| `--format=<fmt>` | table | Output format |
| `--fields=<list>` | - | Comma-separated fields |
| `--no-truncate` | - | Don't truncate long values |

### show

Display details of a specific comment.

```bash
kvs comment show <id>
```

### stats

Show comment statistics.

```bash
kvs comment stats
```

Displays:
- Total comments
- Comments on videos
- Comments on albums
- Recent activity (last 7 days)
- Top commenters

## Default Fields

- `comment_id` - Comment ID
- `object_type` - Type (video/album)
- `object_id` - Video/Album ID
- `user_id` - Author user ID
- `comment` - Comment text (truncated)

## Examples

### Basic Usage

```bash
# List recent comments
kvs comment list

# Show comment details
kvs comment show 789

# View statistics
kvs comment stats
```

### Filtering

```bash
# Comments on a specific video
kvs comment list --video=123

# Comments on a specific album
kvs comment list --album=45

# Comments by a specific user
kvs comment list --user=5

# Combine filters
kvs comment list --video=123 --limit=50
```

### Output Formats

```bash
# JSON output
kvs comment list --format=json

# CSV export
kvs comment list --format=csv > comments.csv

# Count only
kvs comment list --format=count

# Count comments on video
kvs comment list --video=123 --format=count
```

### Statistics Output

```bash
kvs comment stats
```

Example output:

```
Comment Statistics
==================

Overview
--------
 Total Comments     1,234
 Video Comments     1,100
 Album Comments     134
 Last 7 Days        87

Top Commenters
--------------
 user123            156 comments
 jane_doe           98 comments
 john_smith         76 comments
```

### Scripting Examples

```bash
# Count comments per video
kvs comment list --format=json | jq 'group_by(.object_id) | map({video: .[0].object_id, count: length})'

# Find videos with most comments
kvs comment list --format=json | jq '[group_by(.object_id) | .[] | {video: .[0].object_id, count: length}] | sort_by(.count) | reverse | .[:10]'
```

## Available Fields

| Field | Description |
|-------|-------------|
| `comment_id` | Unique comment ID |
| `object_type` | 1=video, 2=album |
| `object_id` | Video or album ID |
| `user_id` | Author user ID |
| `comment` | Comment text |
| `added_date` | Date posted |
| `ip` | IP address |
| `rating` | Comment rating |

## Aliases

- `kvs comments`
- `kvs content:comment`

## See Also

- [[Command-video|video]] - Manage videos
- [[Command-album|album]] - Manage albums
- [[Command-user|user]] - Manage users
