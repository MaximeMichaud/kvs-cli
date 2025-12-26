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

Add to your shell profile:

```bash
# ~/.bashrc or ~/.zshrc
export KVS_PATH=/var/www/kvs
```

## Database Configuration

KVS-CLI reads database credentials from your KVS installation:

```
/path/to/kvs/admin/include/setup_db.php
```

This file contains:

```php
define('DB_HOST', '127.0.0.1');
define('DB_LOGIN', 'kvs_user');
define('DB_PASS', 'password');
define('DB_DEVICE', 'kvs_database');
```

### Environment Variable Overrides

Override database settings without modifying KVS files:

| Variable | Description | Example |
|----------|-------------|---------|
| `KVS_DB_HOST` | Database host | `127.0.0.1` |
| `KVS_DB_PORT` | Database port | `3306` |
| `KVS_DB_USER` | Database username | `kvs_user` |
| `KVS_DB_PASS` | Database password | `secret` |
| `KVS_DB_NAME` | Database name | `kvs_prod` |

Example:

```bash
export KVS_DB_HOST=db.example.com
export KVS_DB_USER=readonly_user
export KVS_DB_PASS=readonly_pass
kvs video list
```

### Multiple Environments

Manage multiple KVS installations with shell aliases:

```bash
# ~/.bashrc
alias kvs-prod='KVS_PATH=/var/www/kvs-prod kvs'
alias kvs-dev='KVS_PATH=/var/www/kvs-dev kvs'
alias kvs-staging='KVS_PATH=/var/www/kvs-staging kvs'
```

Usage:

```bash
kvs-prod video list
kvs-dev system:status
kvs-staging check
```

## Global Options

All commands support these global options:

| Option | Description |
|--------|-------------|
| `--path=<path>` | Path to KVS installation |
| `--help` | Display help for the command |
| `--quiet` | Suppress non-essential output |
| `--verbose` | Increase verbosity |
| `--version` | Display version |
| `--ansi` | Force ANSI output |
| `--no-ansi` | Disable ANSI output |

## Output Format Options

Most list commands support:

| Option | Values | Description |
|--------|--------|-------------|
| `--format` | `table`, `json`, `csv`, `yaml`, `count`, `ids` | Output format |
| `--fields` | Comma-separated | Fields to display |
| `--no-truncate` | - | Don't truncate long values |

### Format Examples

```bash
# Table (default)
kvs video list

# JSON for scripting
kvs video list --format=json

# CSV for spreadsheets
kvs video list --format=csv > videos.csv

# YAML for readability
kvs video list --format=yaml

# Count only
kvs video list --format=count

# IDs only (for piping)
kvs video list --format=ids
```

### Field Selection

```bash
# Specific fields
kvs video list --fields=video_id,title,status

# With field aliases
kvs video list --fields=id,title,views
```

**Field Aliases:**

| Alias | Resolves to |
|-------|-------------|
| `id` | `video_id`, `album_id`, `user_id`, etc. |
| `status` | `status_id` |
| `views` | `video_viewed`, `album_viewed`, etc. |

## Pagination Options

| Option | Default | Description |
|--------|---------|-------------|
| `--limit` | 20 | Maximum results |
| `--offset` | 0 | Skip first N results |

```bash
# First 50 videos
kvs video list --limit=50

# Videos 51-100
kvs video list --limit=50 --offset=50

# All videos (be careful with large datasets)
kvs video list --limit=0
```

## Status Filters

Most content commands support `--status`:

### Video Status

| Value | Status | Description |
|-------|--------|-------------|
| 0 | Disabled | Video is disabled |
| 1 | Active | Video is active |
| 2 | Error | Video has processing errors |

### User Status

| Value | Status | Description |
|-------|--------|-------------|
| 0 | Disabled | Account disabled |
| 1 | Not Confirmed | Email not confirmed |
| 2 | Active | Active user |
| 3 | Premium | Premium user |
| 4 | VIP | VIP user |
| 6 | Webmaster | Webmaster account |

### Album Status

| Value | Status | Description |
|-------|--------|-------------|
| 0 | Disabled | Album is disabled |
| 1 | Active | Album is active |

### Category/Tag Status

| Value | Status | Description |
|-------|--------|-------------|
| 0 | Inactive | Not displayed |
| 1 | Active | Displayed |

## Configuration Files

### KVS Setup Files

KVS-CLI reads these files from your KVS installation:

| File | Purpose |
|------|---------|
| `admin/include/setup_db.php` | Database credentials |
| `admin/include/setup.php` | Main configuration |
| `admin/include/version.php` | KVS version |
| `admin/include/setup_paths.php` | Content paths |

### Viewing Configuration

```bash
# List all configuration
kvs config list

# Get specific key
kvs config get project_version
kvs config get db.host
kvs config get main.project_url

# JSON output
kvs config list --json
```

### Configuration Keys

**Database (`db.*`):**

| Key | Description |
|-----|-------------|
| `db.host` | Database server |
| `db.login` | Username |
| `db.pass` | Password (protected) |
| `db.device` | Database name |

**Main Configuration (`main.*`):**

| Key | Description |
|-----|-------------|
| `main.project_version` | KVS version |
| `main.project_url` | Site URL |
| `main.project_path` | Installation path |
| `main.ffmpeg_path` | FFmpeg binary |
| `main.image_magick_path` | ImageMagick path |
| `main.memcache_server` | Memcached server |

## Shell Configuration

### Bash Completion

```bash
# Enable completion
kvs completion bash | sudo tee /etc/bash_completion.d/kvs

# User-only
kvs completion bash >> ~/.bash_completion
```

### Zsh Completion

```bash
# Add to .zshrc
echo 'eval "$(kvs completion zsh)"' >> ~/.zshrc
```

### Custom Aliases

Add to your `.bashrc` or `.zshrc`:

```bash
# Quick commands
alias kstatus='kvs system:status'
alias kcheck='kvs check'
alias kvideo='kvs video'
alias kuser='kvs user'

# Common operations
alias kbackup='kvs db:export --compress=gzip'
alias kclear='kvs cache clear'

# Multi-environment
alias kprod='KVS_PATH=/var/www/kvs-prod kvs'
alias kdev='KVS_PATH=/var/www/kvs-dev kvs'
```

## Scripting Best Practices

### Error Handling

```bash
#!/bin/bash
set -e  # Exit on error

# Check if KVS is accessible
if ! kvs system:status > /dev/null 2>&1; then
    echo "Error: Cannot connect to KVS"
    exit 1
fi

# Continue with operations
kvs video list --format=json > videos.json
```

### JSON Processing

```bash
# With jq
kvs video list --format=json | jq '.[] | {id: .video_id, title: .title}'

# Count by status
kvs video list --format=json | jq 'group_by(.status_id) | map({status: .[0].status_id, count: length})'
```

### Piping Commands

```bash
# Get IDs and process them
for id in $(kvs video list --format=ids); do
    echo "Processing video $id"
    kvs video show "$id"
done
```

### Cron Jobs

```bash
# /etc/cron.d/kvs-backup
0 2 * * * www-data /usr/local/bin/kvs --path=/var/www/kvs db:export --compress=gzip -o /backups/kvs-$(date +\%Y\%m\%d).sql.gz

# Clear cache daily
0 3 * * * www-data /usr/local/bin/kvs --path=/var/www/kvs cache clear
```

## Troubleshooting

### Debug Mode

```bash
# Verbose output
kvs -v video list

# Very verbose
kvs -vvv video list
```

### Testing Configuration

```bash
# Test database connection
kvs config list

# Test KVS detection
kvs system:status

# Run health checks
kvs check
```

### Common Issues

**"KVS installation not found":**
- Verify KVS_PATH is set correctly
- Check that `admin/include/setup_db.php` exists
- Use `--path` to specify explicitly

**"Database connection failed":**
- Check credentials in `setup_db.php`
- Verify MySQL/MariaDB is running
- Test with `kvs config get db.host`

**"Permission denied":**
- Run with appropriate user (`www-data`, etc.)
- Check file permissions on KVS directory
