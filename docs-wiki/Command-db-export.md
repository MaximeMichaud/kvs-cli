# kvs db:export

Export KVS database to SQL file.

## Synopsis

```bash
kvs db:export [options]
```

## Description

The `db:export` command creates a SQL dump of your KVS database. It supports multiple compression formats and can export specific tables.

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `-o, --output=<file>` | Auto-generated | Output file path |
| `--tables=<tables>` | All | Comma-separated table names |
| `--no-data` | - | Export structure only (no data) |
| `--compress[=<format>]` | - | Compression format |

## Compression Formats

| Format | Extension | Description |
|--------|-----------|-------------|
| `gzip` | `.gz` | Standard, best compatibility |
| `zstd` | `.zst` | Fast, great compression ratio |
| `xz` | `.xz` | Maximum compression, slower |
| `bzip2` | `.bz2` | Good compression |

## Examples

### Basic Export

```bash
kvs db:export
```

Output:

```
Exporting database...
 Database: kvs_production
 Tables: 156

Exported to: kvs_production_20240115_143022.sql
Size: 245.6 MB
```

### Export with Compression

```bash
# Gzip (recommended for compatibility)
kvs db:export --compress=gzip

# Zstandard (fast, modern)
kvs db:export --compress=zstd

# XZ (maximum compression)
kvs db:export --compress=xz
```

### Specify Output File

```bash
kvs db:export -o backup.sql
kvs db:export -o /backups/daily.sql.gz --compress=gzip
```

### Export Specific Tables

```bash
# Export only video and user tables
kvs db:export --tables=ktvs_videos,ktvs_users

# Export with compression
kvs db:export --tables=ktvs_videos --compress=gzip -o videos.sql.gz
```

### Structure Only (No Data)

```bash
# Export schema without data
kvs db:export --no-data

# Useful for creating empty database
kvs db:export --no-data -o schema.sql
```

### Scripting Examples

```bash
#!/bin/bash
# daily-backup.sh

DATE=$(date +%Y%m%d)
BACKUP_DIR=/backups/kvs

# Create compressed backup
kvs db:export --compress=gzip -o "$BACKUP_DIR/db-$DATE.sql.gz"

# Keep last 30 days
find "$BACKUP_DIR" -name "db-*.sql.gz" -mtime +30 -delete

echo "Backup complete: $BACKUP_DIR/db-$DATE.sql.gz"
```

```bash
#!/bin/bash
# pre-deploy-backup.sh

# Backup before deployment
BACKUP="pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz"
kvs db:export --compress=gzip -o "/backups/$BACKUP"
echo "Backup saved: $BACKUP"
```

## Aliases

- `kvs database:export`
- `kvs db:dump`

## See Also

- [[Command-db-import|db:import]] - Import database
- [[Command-system-backup|system:backup]] - Full backup
- [[Command-maintenance|maintenance]] - Maintenance mode
