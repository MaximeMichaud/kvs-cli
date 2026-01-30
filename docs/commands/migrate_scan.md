# kvs migrate:scan

**[EXPERIMENTAL]** Scan a KVS installation for migration analysis.

## Synopsis

```bash
kvs migrate:scan [<path>] [options]
```

## Description

The `migrate:scan` command analyzes a KVS installation to assess its structure, content, and readiness for migration. It provides detailed information about the database, content files, and storage requirements.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `path` | No | Path to KVS installation (defaults to current installation) |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--json` | - | Output results as JSON |
| `--force` | - | Skip experimental feature confirmation |

## Output

The scan provides:

- **KVS Version** - Version number and installation type (Docker/Standalone)
- **Database Info** - Host, database name, size, table count
- **Content Statistics** - Number of videos, albums, users, comments
- **Storage Breakdown** - Size of videos, albums, screenshots, etc.
- **Migration Readiness** - Assessment and warnings

## Examples

### Basic Scan

```bash
# Scan current installation
kvs migrate:scan

# Scan specific installation
kvs migrate:scan /var/www/mysite
```

### JSON Output

```bash
# Get scan data as JSON for scripting
kvs migrate:scan --json
kvs migrate:scan /var/www/site --json > scan-results.json
```

### Pre-migration Check

```bash
# Check readiness before migration
kvs migrate:scan /var/www/oldsite
```

## Sample Output

```
KVS Installation Scan
=====================

Installation
------------
 Path          /var/www/kvs
 Version       6.3.2
 Type          Standalone

Database
--------
 Host          127.0.0.1
 Database      kvs_production
 Size          2.4 GB
 Tables        156

Content Statistics
------------------
 Videos        1,523
 Albums        456
 Users         89
 Comments      3,245

Storage Breakdown
-----------------
 Videos        45.2 GB
 Albums        12.3 GB
 Screenshots   8.9 GB
 Total         66.4 GB

Migration Readiness
-------------------
 ✓ Database accessible
 ✓ Content files readable
 ✓ All checks passed
```

## Aliases

- `kvs scan`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- Read-only operation - does not modify the installation
- Use `--json` output for automation and scripting

## See Also

- [`migrate:package`](migrate_package.md) - Create migration package
- [`migrate:to-docker`](migrate_to_docker.md) - Migrate to Docker
- [`migrate:import`](migrate_import.md) - Import migration package
