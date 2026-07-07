# kvs comment

Manage comments in your KVS installation.

## Synopsis

```bash
kvs comment [<action>] [<id>] [options]
```

## Description

The `comment` command allows you to list, inspect, moderate, delete, and get
statistics about comments across KVS object types.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `pending`, `show`, `approve`, `reject`, `delete`, `stats` (default: `list`) |
| `id` | Conditional | Comment ID or comma-separated comment IDs |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--video=ID` | - | Filter by video ID |
| `--album=ID` | - | Filter by album ID |
| `--content-source=ID` | - | Filter by content source ID |
| `--model=ID` | - | Filter by model ID |
| `--dvd=ID` | - | Filter by DVD ID |
| `--post=ID` | - | Filter by post ID |
| `--playlist=ID` | - | Filter by playlist ID |
| `--object-type=TYPE` | - | Filter by KVS object type ID or alias |
| `--object-id=ID` | - | Filter by KVS object ID |
| `--user=USER` | - | Filter by user ID, username, or anonymous name |
| `--ip=IP` | - | Filter by IP address |
| `--limit=N` | 50 | Number of results to show |
| `--search=TEXT` | - | Search in comment text and usernames |
| `--oldest` | - | Show oldest comments first |
| `--approved` | - | Show only approved comments |
| `--pending` | - | Show only pending comments |
| `--not-approved` | - | Show only not approved comments |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--field=FIELD` | - | Display a single field value |
| `--format=FORMAT` | table | Output format: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `--no-truncate` | - | Do not truncate long text fields |
| `--all` | - | Apply moderation action to all pending comments |
| `-y, --yes` | - | Skip confirmation prompt for moderation actions |

## Actions

### list

List comments with optional filtering.

```bash
kvs comment list [options]
```

### pending

List comments awaiting moderation.

```bash
kvs comment pending
```

### show

Display details of a specific comment.

```bash
kvs comment show <id>
```

### approve

Approve one or more pending comments.

```bash
kvs comment approve <id>
kvs comment approve 1,2,3
kvs comment approve --all
```

### reject / delete

Reject and delete one or more comments.

```bash
kvs comment reject <id>
kvs comment delete <id>
kvs comment reject 1,2,3 --yes
```

### stats

Show comment statistics.

```bash
kvs comment stats
```

## Mutating Actions

The `approve`, `reject`, and `delete` actions modify comment visibility or
delete comment data. `reject` and `delete` are equivalent.

## Object Type Values

The `--object-type` option accepts IDs and aliases:

| Alias | KVS ID |
|-------|--------|
| `video` | 1 |
| `album` | 2 |
| `content-source`, `content_source`, `source` | 3 |
| `model` | 4 |
| `dvd` | 5 |
| `post` | 12 |
| `playlist` | 13 |

## Available Fields

| Field | Aliases | Description |
|-------|---------|-------------|
| `comment_id` | `id` | Comment ID |
| `username` | `user` | Comment author |
| `user_status_id` | - | Numeric user status ID |
| `object_id` | `content` | Parent object ID |
| `object_type` | `type` | Parent object type label |
| `object_title` | `content_title`, `object` | Parent object title |
| `object_dir` | - | Parent object directory |
| `post_type_id` | - | Post type ID for post comments |
| `comment` | - | Comment text |
| `comment_full` | - | Full comment text |
| `ip` | - | IP address |
| `country` | - | Country title |
| `rating` | - | Comment rating |
| `is_approved` | - | Approval state |
| `added_date` | `date` | Posted date |

## Examples

### Basic Usage

```bash
# List recent comments
kvs comment list

# List pending comments
kvs comment pending

# Show comment details
kvs comment show 789

# View statistics
kvs comment stats
```

### Filtering

```bash
# Comments on specific object types
kvs comment list --video=123
kvs comment list --album=45
kvs comment list --object-type=playlist
kvs comment list --object-type=video --object-id=123

# Comments by author or IP
kvs comment list --user=alice
kvs comment list --ip=127.0.0.1

# Moderation filters
kvs comment list --approved
kvs comment list --pending
kvs comment list --not-approved

# Search and ordering
kvs comment list --search="spam" --format=csv
kvs comment list --oldest
```

### Moderation

```bash
# Approve comments
kvs comment approve 123
kvs comment approve 1,2,3,4
kvs comment approve --all

# Reject or delete comments
kvs comment reject 456
kvs comment delete 456 --yes
```

### Output Formats

```bash
# JSON output
kvs comment list --format=json

# CSV export
kvs comment list --format=csv > comments.csv

# Count only
kvs comment list --format=count

# IDs only
kvs comment list --format=ids
```

### Field Selection

```bash
# Specific fields
kvs comment list --fields=comment_id,username,object_type,object_title,comment,added_date

# KVS admin fields
kvs comment list --fields=comment_id,comment,object,user,username,post_type_id,object_id --format=json

# Single field
kvs comment list --field=comment

# Full text in table output
kvs comment list --no-truncate
```

## Aliases

- `kvs comments`
- `kvs content:comment`

## See Also

- [`video`](video.md) - Manage videos
- [`album`](album.md) - Manage albums
- [`user`](user.md) - Manage users
