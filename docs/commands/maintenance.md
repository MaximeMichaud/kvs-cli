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
|--------|-------------|
| `on` | Enable maintenance mode |
| `off` | Disable maintenance mode |
| `status` | Check current status |

## Examples

### Enable Maintenance Mode

```bash
kvs maintenance on
```

Output:

```
Maintenance mode enabled.
Visitors will now see the maintenance page.
```

### Disable Maintenance Mode

```bash
kvs maintenance off
```

Output:

```
Maintenance mode disabled.
Website is now accessible to visitors.
```

### Check Status

```bash
kvs maintenance status
```

Output:

```
Maintenance mode: DISABLED
Website is accessible to visitors.
```

Or:

```
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
kvs cache clear
kvs maintenance off

# Scheduled maintenance
kvs maintenance on
kvs backup --create
kvs cron optimize
kvs cache clear
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
kvs cache clear

echo "Deployment completed successfully!"
# maintenance off happens automatically via trap
```

## Aliases

- `kvs maint`

## See Also

- [`system:backup`](system-backup.md) - Create backups
- [`system:cache`](system-cache.md) - Clear cache
- [`system:status`](system-status.md) - Check status
