# kvs migrate:import

**[EXPERIMENTAL]** Import a KVS migration package into Docker.

## Synopsis

```bash
kvs migrate:import <package> [options]
```

## Description

The `migrate:import` command imports a migration package (created by `migrate:package`) into a Docker environment using KVS-Install. It sets up a complete KVS installation with Docker, imports the database, and copies content files.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `package` | Yes | Path to migration package (.tar.zst file) |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `-d, --domain=DOMAIN` | - | Target domain name (required) |
| `-e, --email=EMAIL` | - | Admin email for SSL certificates (required) |
| `-t, --target=PATH` | /opt/kvs | KVS-Install directory |
| `--ssl=TYPE` | - | SSL type: 1=Let's Encrypt, 2=ZeroSSL, 3=self-signed |
| `--db=INDEX` | 1 | KVS-Install MariaDB menu index (1=latest LTS) |
| `-y, --yes` | - | Skip confirmation prompts |
| `--force` | - | Skip experimental feature confirmation |

## SSL Options

| Type | Value | Description | Requirements |
|------|-------|-------------|--------------|
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

The package's recorded table prefix must match the prefix loaded from the
target site's `admin/include/setup.php`. KVS CLI checks this after KVS-Install
setup and before importing any package SQL or content. A missing prefix or a
mismatch stops the import with an error.

KVS-Install setup may already have created the target installation at that
point. KVS CLI does not rewrite KVS-Install's `TABLES_PREFIX` configuration or
Manticore settings. Use a package whose database tables and metadata already
match the target prefix, or use KVS-Install's existing-site import separately.

## Import Process

The command performs these steps:

1. **Extract package** - Unpack database and content
2. **Setup KVS-Install** - Clone and configure Docker environment
3. **Import database** - Restore database from package
4. **Copy content** - Transfer videos, albums, screenshots
5. **Start services** - Launch Docker containers

## Examples

### Basic Import

```bash
# Import with Let's Encrypt SSL
kvs migrate:import backup.tar.zst \
  --domain=example.com \
  --email=admin@example.com \
  --ssl=1
```

### Quick Import

```bash
# Using shorthand options
kvs migrate:import backup.tar.zst -d example.com -e admin@example.com
```

### Custom Configuration

```bash
# Custom target directory and second MariaDB menu entry
kvs migrate:import backup.tar.zst \
  -d example.com \
  -e admin@example.com \
  --target=/home/kvs \
  --db=2 \
  --ssl=1
```

### Non-interactive

```bash
# Skip all confirmation prompts
kvs migrate:import backup.tar.zst \
  -d example.com \
  -e admin@example.com \
  --ssl=3 \
  -y
```

## Complete Migration Workflow

### On Source Server

```bash
# 1. Scan installation
kvs migrate:scan

# 2. Create migration package
kvs migrate:package -o backup.tar.zst
```

### Transfer Package

```bash
# 3. Copy to destination server
scp backup.tar.zst user@newserver:/tmp/
```

### On Destination Server

```bash
# 4. Import package
kvs migrate:import /tmp/backup.tar.zst \
  --domain=newdomain.com \
  --email=admin@newdomain.com \
  --ssl=1

# 5. Verify installation
kvs --path=/opt/kvs/kvs system:status
```

## Aliases

- `kvs import`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- Requires **root/sudo access** for Docker operations
- DNS must point to server before using Let's Encrypt or ZeroSSL
- Allow 5-15 minutes for full import depending on content size

## Requirements

- **Docker** and **Docker Compose**
- **Git** (to clone KVS-Install)
- **zstd** (to extract package)
- **Root/sudo access**
- Ports **80** and **443** available

## Troubleshooting

### Port Conflicts

If ports 80/443 are in use:
```bash
# Check what's using the ports
sudo lsof -i :80
sudo lsof -i :443

# Stop conflicting services
sudo systemctl stop apache2
sudo systemctl stop nginx
```

### DNS Not Ready

If using Let's Encrypt before DNS propagates:
```bash
# Use self-signed for initial testing
kvs migrate:import backup.tar.zst -d example.com -e admin@example.com --ssl=3

# Later, update to Let's Encrypt via KVS-Install
```

## See Also

- [`migrate:package`](migrate_package.md) - Create migration package
- [`migrate:scan`](migrate_scan.md) - Scan installation before migration
- [`migrate:to-docker`](migrate_to_docker.md) - Direct migration without package
