# kvs user:purge

Bulk purge users by explicit cleanup criteria.

## Synopsis

```bash
kvs user:purge [options]
```

## Description

The `user:purge` command finds users that match one or more cleanup criteria. It
is a dry-run by default and only deletes users when `--confirm` is provided.

When deletion is confirmed, the command loads the KVS admin context and uses the
native `delete_users()` function so KVS can clean related files, references,
counters, messages, comments, and subscriptions.

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--removal-requested` | - | Match users who requested account deletion |
| `--no-content` | - | Match users with 0 videos and 0 comments |
| `--inactive-days=N` | - | Match users who have not logged in for N days |
| `--min-age=N` | - | Match accounts older than N days |
| `--limit=N` | 1000 | Maximum number of users to process |
| `--confirm` | - | Actually delete matched users |
| `-y, --yes` | - | Skip confirmation prompt when `--confirm` is used |

At least one filter is required. Without `--confirm`, the command only displays
the matched users.

## Examples

### Preview Users

```bash
# Dry-run: show users who requested deletion and have no content
kvs user:purge --removal-requested --no-content

# Limit the preview
kvs user:purge --no-content --limit=25

# Add inactivity and account-age filters
kvs user:purge --removal-requested --no-content --inactive-days=30 --min-age=90
```

### Delete Users

```bash
# Delete with confirmation prompt
kvs user:purge --removal-requested --no-content --inactive-days=30 --confirm

# Delete without confirmation prompt
kvs user:purge --removal-requested --no-content --confirm --yes
```

## Safety Features

1. **Dry-run by default**: matching users are displayed without deletion unless
   `--confirm` is provided.
2. **Explicit filters required**: the command refuses to run without at least
   one filter.
3. **Confirmation prompt**: `--confirm` still asks for confirmation unless
   `--yes` is also provided.
4. **System account exclusion**: users `1` and `2`, and users with `status_id=4`,
   are excluded from purge candidates.

## Aliases

- `kvs users:purge`
- `kvs user:cleanup`

## See Also

- [`user`](user.md) - Manage users
- [`comment`](comment.md) - Manage comments
