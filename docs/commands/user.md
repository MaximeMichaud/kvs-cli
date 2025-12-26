# kvs user

Manage users in your KVS installation.

## Synopsis

```bash
kvs user <action> [<id>] [options]
```

## Description

The `user` command allows you to list and view user accounts in your KVS installation.

## Actions

### list

List users with optional filtering.

```bash
kvs user list [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--limit=<n>` | 20 | Maximum number of results |
| `--offset=<n>` | 0 | Skip first N results |
| `--status=<id>` | - | Filter by status (0-6) |
| `--format=<fmt>` | table | Output format |
| `--fields=<list>` | - | Comma-separated fields |
| `--no-truncate` | - | Don't truncate long values |

**Status Values:**

| Value | Name | Color | Description |
|-------|------|-------|-------------|
| 0 | Disabled | Red | Account disabled |
| 1 | Not Confirmed | Yellow | Email not confirmed |
| 2 | Active | Green | Regular active user |
| 3 | Premium | Cyan | Premium subscriber |
| 4 | VIP | Magenta | VIP user |
| 6 | Webmaster | Blue | Content uploader |

### show

Display details of a specific user.

```bash
kvs user show <id>
```

## Default Fields

- `user_id` - User ID
- `username` - Username
- `email` - Email address
- `status` - Status with color
- `display_name` - Display name

## Examples

### Basic Usage

```bash
# List first 20 users
kvs user list

# List 50 users
kvs user list --limit=50

# Show user details
kvs user show 5
```

### Filtering by Status

```bash
# Active users only
kvs user list --status=2

# Premium users
kvs user list --status=3

# VIP users
kvs user list --status=4

# Webmasters
kvs user list --status=6

# Disabled accounts
kvs user list --status=0

# Unconfirmed accounts
kvs user list --status=1
```

### Output Formats

```bash
# JSON output
kvs user list --format=json

# CSV export
kvs user list --format=csv > users.csv

# Count only
kvs user list --format=count

# Count premium users
kvs user list --status=3 --format=count
```

### Field Selection

```bash
# Specific fields
kvs user list --fields=user_id,username,email

# Export emails only
kvs user list --fields=email --format=csv | tail -n +2 > emails.txt
```

### Scripting Examples

```bash
# Count users by status
echo "Active: $(kvs user list --status=2 --format=count)"
echo "Premium: $(kvs user list --status=3 --format=count)"
echo "VIP: $(kvs user list --status=4 --format=count)"

# Export all user data
kvs user list --limit=0 --format=json > all_users.json

# Find users with jq
kvs user list --format=json | jq '.[] | select(.total_videos > 10)'
```

## Available Fields

| Field | Description |
|-------|-------------|
| `user_id` | Unique user ID |
| `username` | Login username |
| `email` | Email address |
| `display_name` | Display name |
| `status_id` | Status code |
| `added_date` | Registration date |
| `last_login_date` | Last login |
| `total_videos` | Videos uploaded |
| `total_albums` | Albums created |
| `profile_viewed` | Profile views |

## Aliases

- `kvs users`
- `kvs content:user`

## See Also

- [`user:purge`](user-purge.md) - Bulk delete users
- [`video`](video.md) - Manage videos
- [`comment`](comment.md) - Manage comments
