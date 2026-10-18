# kvs migrate:to-docker

**[EXPERIMENTAL]** Migrate a standalone KVS installation directly to Docker.

## Synopsis

```bash
kvs migrate:to-docker [<source>] [options]
```

## Description

The `migrate:to-docker` command migrates a standalone KVS installation directly to a Docker environment using KVS-Install. Unlike `migrate:package` + `migrate:import`, this performs a direct migration without creating an intermediate package file.

This command delegates to KVS-Install's `setup.sh` which handles port detection, DNS verification, SSL certificate generation, and Docker orchestration.

## Arguments

| Argument | Required | Description |
| ---------- | ---------- | ------------- |
| `source` | No | Source KVS installation path (defaults to current) |

## Options

| Option | Default | Description |
| -------- | --------- | ------------- |
| `-d, --domain=DOMAIN` | - | Target domain name (required) |
| `-e, --email=EMAIL` | - | Admin email for SSL certificates (required) |
| `-t, --target=PATH` | /opt/kvs | KVS-Install directory |
| `--ssl=TYPE` | - | SSL type: 1=Let's Encrypt, 2=ZeroSSL, 3=self-signed |
| `--db=INDEX` | 1 | KVS-Install MariaDB menu index (1=latest LTS) |
| `--no-content` | - | Skip content migration (database only) |
| `--dry-run` | - | Show what would be done without making changes |
| `-y, --yes` | - | Skip confirmation prompts |
| `--force` | - | Skip experimental feature confirmation |

## SSL Options

| Type | Value | Description | Requirements |
| ------ | ------- | ------------- | -------------- |
| Let's Encrypt | `--ssl=1` | Free trusted SSL | Valid DNS + ports 80/443 |
| ZeroSSL | `--ssl=2` | Alternative free SSL | Valid DNS + ports 80/443 |
| Self-signed | `--ssl=3` | Testing only | None (browser warnings) |

## MariaDB Selection

`--db` is forwarded unchanged as KVS-Install's `DB_CHOICE` menu index. Index `1`
selects the latest LTS entry in that installer's current menu. Other indices
depend on the KVS-Install revision and its available entries; they do not pin
a MariaDB release series. The setup output reports the selected series.

KVS CLI accepts indices `1` through `4`. Use an index available in the target
installer's current menu. KVS CLI does not resolve a release number to an index
or provide a stable release-series selector.

## Table Prefix Compatibility

The source prefix must match the prefix loaded from the target site's
`admin/include/setup.php` after KVS-Install setup. A missing prefix or mismatch
stops the migration before any source SQL or content is imported. The source
database dump may already have been created, and setup may already have
created the target installation.

KVS CLI does not rewrite KVS-Install's `TABLES_PREFIX` configuration or
Manticore settings. Use a source matching the target prefix, or use
KVS-Install's existing-site import separately. A dry run only renders the
plan; the loaded target prefix is checked during the actual migration.

## Migration Process

The command performs:

1. **Validation** - Check source installation and requirements
2. **Setup KVS-Install** - Clone and configure Docker environment
3. **Port Check** - Verify ports 80/443 are available
4. **DNS Verification** - Confirm domain points to server (for SSL)
5. **Database Migration** - Export and import database
6. **Content Migration** - Copy videos, albums, screenshots (unless `--no-content`)
7. **Docker Launch** - Start containers with configured settings

## Examples

### Basic Migration

```bash
# Migrate current installation
kvs migrate:to-docker \
  --domain=example.com \
  --email=admin@example.com \
  --ssl=1
```

### Specific Source

```bash
# Migrate from specific path
kvs migrate:to-docker /var/www/oldsite \
  -d newdomain.com \
  -e admin@newdomain.com \
  --ssl=1
```

### Dry Run

```bash
# Preview what would happen
kvs migrate:to-docker /var/www/oldsite \
  --domain=example.com \
  --email=admin@example.com \
  --dry-run
```

### Database Only

```bash
# Skip content files (faster migration)
kvs migrate:to-docker \
  -d example.com \
  -e admin@example.com \
  --no-content
```

### Custom Configuration

```bash
# Custom target directory and second MariaDB menu entry
kvs migrate:to-docker /var/www/site \
  -d example.com \
  -e admin@example.com \
  --target=/home/kvs \
  --db=2 \
  --ssl=1
```

### Testing Setup

```bash
# Use self-signed SSL for testing
kvs migrate:to-docker \
  -d test.local \
  -e admin@test.local \
  --ssl=3 \
  -y
```

## Migration Strategies

### Strategy 1: Direct Migration (This Command)

Best for:

- Single server migration
- Migrating on same server
- Quick migration without intermediate files

```bash
kvs migrate:to-docker /var/www/oldsite \
  -d newdomain.com \
  -e admin@newdomain.com
```

### Strategy 2: Package + Import

Best for:

- Server-to-server migration
- Creating backups
- Multiple imports

```bash
# On source server
kvs migrate:package -o backup.tar.zst

# Transfer and import on destination
kvs migrate:import backup.tar.zst -d newdomain.com -e admin@newdomain.com
```

## Aliases

- `kvs to-docker`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- Requires **root/sudo access** for Docker operations
- DNS must point to server before using Let's Encrypt or ZeroSSL
- Source installation remains unchanged (read-only operation)
- Allow 10-30 minutes for full migration depending on content size

## Requirements

- **Docker** and **Docker Compose**
- **Git** (to clone KVS-Install)
- **Root/sudo access**
- Ports **80** and **443** available
- Sufficient disk space (2x source installation size)

## Pre-migration Checklist

```bash
# 1. Scan source installation
kvs migrate:scan /var/www/oldsite

# 2. Check disk space
df -h /opt/kvs

# 3. Verify DNS (for Let's Encrypt)
dig +short example.com

# 4. Check ports are free
sudo lsof -i :80
sudo lsof -i :443

# 5. Run dry-run
kvs migrate:to-docker /var/www/oldsite \
  -d example.com \
  -e admin@example.com \
  --dry-run
```

## Troubleshooting

### Port Already in Use

```bash
# Find what's using the port
sudo lsof -i :80
sudo lsof -i :443

# Stop conflicting services
sudo systemctl stop apache2 nginx
```

### DNS Not Configured

If using Let's Encrypt before DNS:

```bash
# Option 1: Wait for DNS propagation
dig +short example.com

# Option 2: Use self-signed initially
kvs migrate:to-docker /var/www/oldsite \
  --domain=example.com \
  --email=admin@example.com \
  --ssl=3
```

### Insufficient Disk Space

```bash
# Check available space
df -h /opt/kvs

# Option 1: Use database-only migration
kvs migrate:to-docker /var/www/oldsite \
  --domain=example.com \
  --email=admin@example.com \
  --no-content

# Option 2: Clear Docker cache
docker system prune -a
```

## See Also

- [`migrate:scan`](migrate_scan.md) - Scan installation before migration
- [`migrate:package`](migrate_package.md) - Create portable package
- [`migrate:import`](migrate_import.md) - Import package
