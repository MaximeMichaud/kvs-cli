# kvs db:import

Import database from SQL file.

## Synopsis

```bash
kvs db:import <file>
```

## Description

The `db:import` command imports a SQL dump into your KVS database. It automatically detects and handles compressed files.

**Warning:** This will overwrite all existing data in the database!

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `file` | Yes | Path to SQL file |

## Supported Formats

| Extension | Format |
|-----------|--------|
| `.sql` | Uncompressed SQL |
| `.gz`, `.gzip` | Gzip compressed |
| `.zst`, `.zstd` | Zstandard compressed |
| `.xz` | XZ compressed |
| `.bz2`, `.bzip2` | Bzip2 compressed |

## Examples

### Import Uncompressed SQL

```bash
kvs db:import backup.sql
```

Output:

```
Importing database from backup.sql...
 Size: 245.6 MB
 ⚠ This will overwrite all existing data!

Confirm import? [y/N] y

Importing... done.
 Tables: 156
 Time: 45.3 seconds

Database imported successfully.
```

### Import Compressed Files

```bash
# Gzip
kvs db:import backup.sql.gz

# Zstandard
kvs db:import backup.sql.zst

# XZ
kvs db:import backup.sql.xz
```

### Migration Workflow

```bash
# 1. Enable maintenance mode
kvs maintenance on

# 2. Backup current database
kvs db:export --compress=gzip -o pre-migration-backup.sql.gz

# 3. Import new database
kvs db:import migration.sql.gz

# 4. Clear cache
kvs cache clear

# 5. Disable maintenance mode
kvs maintenance off
```

### Scripting Examples

```bash
#!/bin/bash
# restore.sh

BACKUP=$1

if [ -z "$BACKUP" ]; then
    echo "Usage: $0 <backup-file>"
    exit 1
fi

if [ ! -f "$BACKUP" ]; then
    echo "Error: File not found: $BACKUP"
    exit 1
fi

# Confirm
echo "This will restore from: $BACKUP"
echo "All current data will be LOST!"
read -p "Continue? [y/N] " confirm

if [ "$confirm" != "y" ]; then
    echo "Cancelled."
    exit 0
fi

# Enable maintenance
kvs maintenance on

# Import
kvs db:import "$BACKUP"

# Clear cache
kvs cache clear

# Disable maintenance
kvs maintenance off

echo "Restore complete!"
```

## Aliases

- `kvs database:import`
- `kvs db:restore`

## See Also

- [[Command-db-export|db:export]] - Export database
- [[Command-system-backup|system:backup]] - Full backup/restore
- [[Command-maintenance|maintenance]] - Maintenance mode
