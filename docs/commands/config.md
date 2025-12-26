# kvs config

Manage KVS configuration.

## Synopsis

```bash
kvs config <action> [<key>] [<value>] [options]
```

## Description

The `config` command allows you to view, get, set, and edit KVS configuration values.

## Actions

### list

List all configuration values.

```bash
kvs config list [options]
```

**Options:**

| Option | Description |
|--------|-------------|
| `--file=<file>` | Config file: all, db, main, paths |
| `--show-protected` | Show protected values (passwords) |
| `--json` | Output as JSON |

### get

Get a specific configuration value.

```bash
kvs config get <key>
```

### set

Set a configuration value.

```bash
kvs config set <key> <value>
```

**Options:**

| Option | Description |
|--------|-------------|
| `--backup` | Create backup before changes |

### edit

Open configuration file in editor.

```bash
kvs config edit [options]
```

**Options:**

| Option | Description |
|--------|-------------|
| `--file=<file>` | Config file to edit: db, main, paths |

## Configuration Keys

### Database Configuration (`db.*`)

| Key | Description |
|-----|-------------|
| `db.host` | Database server host |
| `db.login` | Database username |
| `db.pass` | Database password (protected) |
| `db.device` | Database name |

### Main Configuration (`main.*`)

| Key | Description |
|-----|-------------|
| `main.project_version` | KVS version |
| `main.project_url` | Site URL |
| `main.project_path` | Installation path |
| `main.php_path` | PHP binary path |
| `main.ffmpeg_path` | FFmpeg path |
| `main.image_magick_path` | ImageMagick path |
| `main.memcache_server` | Memcached server |
| `main.memcache_port` | Memcached port |

## Examples

### List Configuration

```bash
# List all config
kvs config list

# List only database config
kvs config list --file=db

# List as JSON
kvs config list --json

# Show passwords
kvs config list --show-protected
```

Output:

```
KVS Configuration
=================

Project Configuration
---------------------
 Project Name      My Video Site
 Version           6.3.2
 Installation Path /var/www/kvs
 Project URL       https://example.com

Database Configuration
----------------------
 Host       127.0.0.1
 Database   kvs_production
 Login      kvs_user
 Password   **********
```

### Get Specific Value

```bash
# Get KVS version
kvs config get project_version

# Get database host
kvs config get db.host

# Get with main prefix
kvs config get main.project_url
```

### Set Configuration

```bash
# Set database host
kvs config set db.host 192.168.1.100

# Set with backup
kvs config set db.host 192.168.1.100 --backup
```

Output:

```
Backup created: /var/www/kvs/admin/include/setup_db.php.backup.20240115143022
Configuration updated: db.host = 192.168.1.100
Testing database connection...
Database connection successful.
```

### Edit Configuration

```bash
# Edit database config in default editor
kvs config edit --file=db

# Edit main config
kvs config edit --file=main
```

The file opens in `$EDITOR` (or `nano` by default). After saving:

```
Configuration file edited successfully.
Validating PHP syntax... OK
```

### Scripting Examples

```bash
# Get version for scripting
VERSION=$(kvs config get project_version)
echo "KVS version: $VERSION"

# Export config as JSON
kvs config list --json > config-backup.json

# Check database host
if [ "$(kvs config get db.host)" = "localhost" ]; then
    echo "Using local database"
fi
```

## Protected Values

Some configuration values are considered sensitive and are hidden by default:

- `db.pass` - Database password
- `db.password` - Database password (alias)
- `license.key` - License key
- `security.key` - Security key

Use `--show-protected` to display these values:

```bash
kvs config get db.pass --show-protected
```

## Similar Key Suggestions

If you mistype a key, suggestions are provided:

```bash
kvs config get db.hostt
```

Output:

```
Configuration key not found: db.hostt
Did you mean one of these?
  - db.host
```

## Aliases

- `kvs conf`
- `kvs cfg`

## See Also

- [`system:status`](system-status.md) - Show system status
- [`system:check`](system-check.md) - Run health checks
