# kvs user:purge

Bulk delete users by status.

## Synopsis

```bash
kvs user:purge <status> [options]
```

## Description

The `user:purge` command allows you to delete multiple users based on their status. This is useful for cleaning up disabled accounts, unconfirmed registrations, or other bulk user management tasks.

**Warning:** This is a destructive operation. Always use `--dry-run` first to preview what will be deleted.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `status` | Yes | User status ID to purge (0-6) |

## Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Show what would be deleted without actually deleting |
| `--force` | Skip confirmation prompt |

## Status Values

| Value | Name | Description |
|-------|------|-------------|
| 0 | Disabled | Disabled accounts |
| 1 | Not Confirmed | Email not confirmed |
| 2 | Active | Active users |
| 3 | Premium | Premium subscribers |
| 4 | VIP | VIP users |
| 6 | Webmaster | Content uploaders |

## Examples

### Preview Deletion (Recommended First Step)

```bash
# See what disabled users would be deleted
kvs user:purge 0 --dry-run

# See what unconfirmed users would be deleted
kvs user:purge 1 --dry-run
```

### Delete Users

```bash
# Delete all disabled users (with confirmation)
kvs user:purge 0

# Delete unconfirmed users without confirmation
kvs user:purge 1 --force
```

### Common Workflows

```bash
# Clean up old unconfirmed registrations
# Step 1: Preview
kvs user:purge 1 --dry-run

# Step 2: Confirm count looks right
# Step 3: Execute
kvs user:purge 1

# Clean up disabled accounts
kvs user:purge 0 --dry-run
kvs user:purge 0
```

## Safety Features

1. **Confirmation Prompt**: Without `--force`, you'll be asked to confirm the deletion
2. **Dry Run Mode**: Use `--dry-run` to see exactly what would be deleted
3. **Status-Based**: Only affects users with the specified status

## Aliases

- `kvs users:purge`

## See Also

- [[Command-user|user]] - Manage users
- [[Command-comment|comment]] - Manage comments
