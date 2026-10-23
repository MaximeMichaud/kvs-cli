# kvs maintenance

Enable or disable website maintenance mode.

## Synopsis

```bash
kvs maintenance <action>
```

## Description

The `maintenance` command allows you to enable or disable maintenance mode on your KVS website. When enabled, visitors see a maintenance page instead of the regular site content.

## Actions

| Action | Description |
| -------- | ------------- |
| `on` | Enable maintenance mode |
| `off` | Disable maintenance mode |
| `status` | Check current status |

## Examples

### Enable Maintenance Mode

```bash
kvs maintenance on
```

Output:

```text
Maintenance mode enabled.
Visitors will now see the maintenance page.
```

### Disable Maintenance Mode

```bash
kvs maintenance off
```

Output:

```text
Maintenance mode disabled.
Website is now accessible to visitors.
```

### Check Status

```bash
kvs maintenance status
```

Output:

```text
Maintenance mode: DISABLED
Website is accessible to visitors.
```

Or:

```text
Maintenance mode: ENABLED
Visitors are seeing the maintenance page.
```

### Common Workflows

```bash
# Before deployment
kvs maintenance on
# ... deploy changes ...
kvs maintenance off

# Before database operations
kvs maintenance on
kvs db:export --compress=gzip -o backup.sql.gz
kvs db:import migration.sql
kvs cache --clear
kvs maintenance off

# Scheduled maintenance
kvs maintenance on
kvs backup --create
kvs cron optimize
kvs cache --clear
kvs maintenance off
```

### Scripting Example

```bash
#!/bin/bash
# deploy.sh

set -e  # Exit on error

echo "Starting deployment..."

# Enable maintenance mode
kvs maintenance on

# Trap to ensure we disable maintenance mode on exit
trap 'kvs maintenance off' EXIT

# Your deployment steps
git pull
composer install --no-dev
kvs cache --clear

echo "Deployment completed successfully!"
# maintenance off happens automatically via trap
```

## Settings File Updates

Maintenance settings are written to a temporary file and replace the existing
configuration only after the complete content has been written. Failed writes
leave the previous settings intact. Symbolic links continue to point to their
existing targets.

Atomic replacement requires a writable target directory as well as a writable
settings file. Existing file permissions, owner, and group are preserved. If the
current account cannot preserve ownership or group, the command fails without
replacing the file. An account with group write access alone may therefore need
to run the command as the file owner or another account with the required rights.

## Aliases

- `kvs maint`

## See Also

- [`system:backup`](system_backup.md) - Create backups
- [`system:cache`](system_cache.md) - Clear cache
- [`system:status`](system_status.md) - Check status
