# kvs migrate:package

**[EXPERIMENTAL]** Create a portable migration package.

## Synopsis

```bash
kvs migrate:package [<path>] [options]
```

## Description

The `migrate:package` command creates a portable migration package containing your KVS database and content files. The package is a tar archive compressed with zstd for efficient storage and transfer.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `path` | No | Path to KVS installation (defaults to current installation) |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `-o, --output=FILE` | Auto-generated | Output file path |
| `--no-content` | - | Skip content files (database only) |
| `-c, --compression=LEVEL` | 3 | Compression level (1-19) |
| `--force` | - | Skip experimental feature confirmation |

## Package Contents

The generated package contains:

- **database.sql.zst** - Compressed database dump
- **content/** - Content files (videos, albums, screenshots, etc.)
- **metadata.json** - Package metadata (version, paths, checksums)

## Compression Levels

| Level | Speed | Size | Use Case |
|-------|-------|------|----------|
| 1-3 | Fast | Large | Quick backups (default: 3) |
| 4-9 | Balanced | Medium | Normal use |
| 10-19 | Slow | Small | Long-term storage |

## Examples

### Basic Package

```bash
# Package current installation
kvs migrate:package

# Package specific installation
kvs migrate:package /var/www/mysite
```

### Custom Output

```bash
# Specify output file
kvs migrate:package -o backup-2024-01-15.tar.zst

# Use dated filename
kvs migrate:package -o "backup-$(date +%Y%m%d).tar.zst"
```

### Database Only

```bash
# Skip content files (faster, smaller)
kvs migrate:package --no-content
kvs migrate:package --no-content -o db-only.tar.zst
```

### Custom Compression

```bash
# Fast compression (level 1)
kvs migrate:package -c 1

# Maximum compression (level 19, slow)
kvs migrate:package -c 19
```

## Migration Workflow

### Complete Site Migration

```bash
# 1. On source server - create package
kvs migrate:package -o site-backup.tar.zst

# 2. Transfer to destination server
scp site-backup.tar.zst user@newserver:/tmp/

# 3. On destination server - import
kvs migrate:import /tmp/site-backup.tar.zst \
  --domain=example.com \
  --email=admin@example.com
```

### Database-Only Backup

```bash
# Quick database backup for testing
kvs migrate:package --no-content -o db-snapshot.tar.zst
```

## Aliases

- `kvs package`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- Requires `zstd` compression tool (usually pre-installed)
- Package size depends on content volume and compression level
- Default compression (level 3) balances speed and size well

## Requirements

- **zstd** - Compression tool
- **mysqldump** or **mariadb-dump** - Database export
- Sufficient disk space for package (roughly 30-50% of content size with compression)

## See Also

- [`migrate:scan`](migrate_scan.md) - Scan installation before packaging
- [`migrate:import`](migrate_import.md) - Import a migration package
- [`migrate:to-docker`](migrate_to_docker.md) - Direct migration to Docker
