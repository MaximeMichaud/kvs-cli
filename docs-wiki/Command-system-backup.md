# kvs system:backup

Create and restore KVS backups.

## Synopsis

```bash
kvs system:backup [options]
```

## Description

The `system:backup` command allows you to create and restore full or partial backups of your KVS installation.

## Options

| Option | Description |
|--------|-------------|
| `--create` | Create a new backup |
| `--restore=<file>` | Restore from backup file |
| `--list` | List available backups |
| `--type=<type>` | Backup type: full, db, files |
| `--output=<dir>` | Output directory for backup |

## Backup Types

| Type | Description |
|------|-------------|
| `full` | Database + all files (default) |
| `db` | Database only |
| `files` | Files only (content, config) |

## Examples

### Create Full Backup

```bash
kvs backup --create
```

Output:

```
Creating full backup...
 ✓ Exporting database... (45.2 MB)
 ✓ Archiving files... (1.2 GB)
 ✓ Compressing...

Backup created: /var/www/kvs/backups/backup-20240115-143022.tar.gz
Size: 892.3 MB
```

### Create Database-Only Backup

```bash
kvs backup --create --type=db
```

### Create Backup to Specific Location

```bash
kvs backup --create --output=/backups/kvs
```

### List Available Backups

```bash
kvs backup --list
```

Output:

```
Available Backups
=================

File                              Type    Size      Date
────────────────────────────────────────────────────────────
backup-20240115-143022.tar.gz     full    892.3 MB  2024-01-15 14:30
backup-20240114-020000.tar.gz     full    891.1 MB  2024-01-14 02:00
backup-20240113-db.sql.gz         db      45.2 MB   2024-01-13 02:00
```

### Restore from Backup

```bash
kvs backup --restore=/backups/backup-20240115-143022.tar.gz
```

**Warning:** Restoration will overwrite existing data!

### Scripting Examples

```bash
#!/bin/bash
# daily-backup.sh

DATE=$(date +%Y%m%d)
BACKUP_DIR=/backups/kvs

# Enable maintenance mode
kvs maintenance on

# Create backup
kvs backup --create --output="$BACKUP_DIR"

# Disable maintenance mode
kvs maintenance off

# Cleanup old backups (keep last 7 days)
find "$BACKUP_DIR" -name "backup-*.tar.gz" -mtime +7 -delete

echo "Backup completed: $BACKUP_DIR/backup-$DATE-*.tar.gz"
```

## Aliases

- `kvs backup`

## See Also

- [[Command-db-export|db:export]] - Export database
- [[Command-db-import|db:import]] - Import database
- [[Command-maintenance|maintenance]] - Maintenance mode
