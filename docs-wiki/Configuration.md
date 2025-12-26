# Configuration

KVS-CLI is designed to work with minimal configuration by automatically detecting your KVS installation settings.

## KVS Path Detection

KVS-CLI searches for your KVS installation in this order:

1. **`--path` CLI option** (highest priority)
   ```bash
   kvs --path=/var/www/kvs system:status
   ```

2. **`KVS_PATH` environment variable**
   ```bash
   export KVS_PATH=/var/www/kvs
   kvs system:status
   ```

3. **Current directory** (walks up the directory tree)
   ```bash
   cd /var/www/kvs
   kvs system:status
   ```

### Setting KVS_PATH Permanently

```bash
# ~/.bashrc or ~/.zshrc
export KVS_PATH=/var/www/kvs
```

## Database Configuration

KVS-CLI reads database credentials from:

```
/path/to/kvs/admin/include/setup_db.php
```

### Environment Variable Overrides

| Variable | Description | Example |
|----------|-------------|---------|
| `KVS_DB_HOST` | Database host | `127.0.0.1` |
| `KVS_DB_PORT` | Database port | `3306` |
| `KVS_DB_USER` | Database username | `kvs_user` |
| `KVS_DB_PASS` | Database password | `secret` |
| `KVS_DB_NAME` | Database name | `kvs_prod` |

```bash
export KVS_DB_HOST=db.example.com
export KVS_DB_USER=readonly_user
kvs video list
```

### Multiple Environments

```bash
# ~/.bashrc
alias kvs-prod='KVS_PATH=/var/www/kvs-prod kvs'
alias kvs-dev='KVS_PATH=/var/www/kvs-dev kvs'
alias kvs-staging='KVS_PATH=/var/www/kvs-staging kvs'
```

## Global Options

| Option | Description |
|--------|-------------|
| `--path=<path>` | Path to KVS installation |
| `--help` | Display help for the command |
| `--quiet` | Suppress non-essential output |
| `--verbose` | Increase verbosity (-v, -vv, -vvv) |
| `--version` | Display version |
| `--ansi` | Force ANSI output |
| `--no-ansi` | Disable ANSI output |

## Output Format Options

| Option | Values | Description |
|--------|--------|-------------|
| `--format` | `table`, `json`, `csv`, `yaml`, `count`, `ids` | Output format |
| `--fields` | Comma-separated | Fields to display |
| `--no-truncate` | - | Don't truncate long values |

### Examples

```bash
kvs video list                           # Table (default)
kvs video list --format=json             # JSON
kvs video list --format=csv > videos.csv # CSV
kvs video list --format=count            # Count only
kvs video list --format=ids              # IDs for piping
```

### Field Selection

```bash
kvs video list --fields=video_id,title,status
```

**Field Aliases:** `id` → `video_id`, `status` → `status_id`, `views` → `video_viewed`

## Pagination Options

| Option | Default | Description |
|--------|---------|-------------|
| `--limit` | 20 | Maximum results |
| `--offset` | 0 | Skip first N results |

```bash
kvs video list --limit=50             # First 50
kvs video list --limit=50 --offset=50 # 51-100
kvs video list --limit=0              # All (careful!)
```

## Status Filters

### Video Status

| Value | Status |
|-------|--------|
| 0 | Disabled |
| 1 | Active |
| 2 | Error |

### User Status

| Value | Status |
|-------|--------|
| 0 | Disabled |
| 1 | Not Confirmed |
| 2 | Active |
| 3 | Premium |
| 4 | VIP |
| 6 | Webmaster |

### Album/Category/Tag Status

| Value | Status |
|-------|--------|
| 0 | Disabled/Inactive |
| 1 | Active |

## Configuration Keys

### Viewing Configuration

```bash
kvs config list
kvs config get project_version
kvs config get db.host
kvs config list --json
```

### Database Keys (`db.*`)

| Key | Description |
|-----|-------------|
| `db.host` | Database server |
| `db.login` | Username |
| `db.device` | Database name |

### Main Keys (`main.*`)

| Key | Description |
|-----|-------------|
| `main.project_version` | KVS version |
| `main.project_url` | Site URL |
| `main.project_path` | Installation path |
| `main.ffmpeg_path` | FFmpeg binary |
| `main.memcache_server` | Memcached server |

## Custom Aliases

Add to `.bashrc` or `.zshrc`:

```bash
alias kstatus='kvs system:status'
alias kcheck='kvs check'
alias kvideo='kvs video'
alias kbackup='kvs db:export --compress=gzip'
```

## Scripting

### Error Handling

```bash
#!/bin/bash
set -e

if ! kvs system:status > /dev/null 2>&1; then
    echo "Error: Cannot connect to KVS"
    exit 1
fi

kvs video list --format=json > videos.json
```

### JSON Processing

```bash
kvs video list --format=json | jq '.[] | {id: .video_id, title: .title}'
```

### Cron Jobs

```bash
# /etc/cron.d/kvs-backup
0 2 * * * www-data /usr/local/bin/kvs --path=/var/www/kvs db:export --compress=gzip -o /backups/kvs-$(date +\%Y\%m\%d).sql.gz
```

## Troubleshooting

### Debug Mode

```bash
kvs -v video list     # Verbose
kvs -vvv video list   # Very verbose
```

### Common Issues

**"KVS installation not found":**
- Verify KVS_PATH is set correctly
- Check that `admin/include/setup_db.php` exists

**"Database connection failed":**
- Check credentials in `setup_db.php`
- Verify MySQL/MariaDB is running

**"Permission denied":**
- Run with appropriate user (`www-data`, etc.)
