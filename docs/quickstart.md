# Quick Start Guide

Get up and running with KVS-CLI in 5 minutes.

## Prerequisites

- KVS-CLI [installed](installation.md)
- Access to a KVS installation

## Step 1: Navigate to Your KVS Installation

```bash
cd /var/www/kvs
```

Or set the path globally:

```bash
export KVS_PATH=/var/www/kvs
```

## Step 2: Verify Connection

Check that KVS-CLI can connect to your installation:

```bash
kvs system:status
```

You should see output like:

```
KVS System Status
=================

Installation
------------
 KVS Path      /var/www/kvs
 Admin Path    /var/www/kvs/admin
 KVS Version   6.3.2

Database
--------
 Host          127.0.0.1
 Database      kvs_production
 MySQL Version 8.0.35
 Total Tables  156
 Database Size 2.4 GB
```

## Step 3: Explore Content

### List Videos

```bash
# Show first 20 videos
kvs video list

# Show more videos
kvs video list --limit=50

# Filter by status
kvs video list --status=1   # Active only
kvs video list --status=2   # Errors only

# Output as JSON
kvs video list --format=json
```

### View Video Details

```bash
kvs video show 123
```

Output:

```
Video #123
==========
 ID        123
 Title     Example Video Title
 Status    Active
 Duration  5:32
 Views     15,432
 Rating    4.5/5
 Added     2024-01-15 14:30:00
```

### List Users

```bash
# All users
kvs user list

# Premium users only
kvs user list --status=3

# Export to CSV
kvs user list --format=csv > users.csv
```

### View Comments

```bash
# All comments
kvs comment list

# Comments on specific video
kvs comment list --video=123

# Comment statistics
kvs comment stats
```

## Step 4: System Administration

### Run Health Checks

```bash
kvs check
```

This checks:
- PHP version and extensions
- Database connectivity
- Required tools (FFmpeg, ImageMagick)
- Disk space
- Cron job status
- And more...

### View Configuration

```bash
# List all config
kvs config list

# Get specific value
kvs config get project_version
kvs config get db.host
```

### Manage Cache

```bash
# Clear all caches
kvs system:cache --clear

# Clear specific cache type
kvs system:cache --clear --type=file
```

### Maintenance Mode

```bash
# Enable maintenance mode
kvs maintenance on

# Check status
kvs maintenance status

# Disable
kvs maintenance off
```

## Step 5: Database Operations

### Export Database

```bash
# Full export
kvs db:export

# Compressed export
kvs db:export --compress=gzip -o backup.sql.gz

# Structure only (no data)
kvs db:export --no-data
```

### Import Database

```bash
# Import from file
kvs db:import backup.sql

# Import compressed file
kvs db:import backup.sql.gz
```

## Step 6: Development Tools

### Execute PHP Code

```bash
# Simple query
kvs eval 'echo Video::count();'

# Database query
kvs eval 'print_r(DB::query("SELECT COUNT(*) FROM ktvs_videos"));'
```

### Interactive Shell

```bash
kvs shell
```

Then:

```
kvs>>> Video::count()
=> 1523

kvs>>> User::find(5)
=> ["user_id" => 5, "username" => "admin", ...]

kvs>>> exit
```

## Common Workflows

### Export Active Videos to JSON

```bash
kvs video list --status=1 --format=json > active_videos.json
```

### Count Content by Status

```bash
echo "Active videos: $(kvs video list --status=1 --format=count)"
echo "Error videos: $(kvs video list --status=2 --format=count)"
echo "Active users: $(kvs user list --status=2 --format=count)"
```

### Backup Before Maintenance

```bash
# Enable maintenance
kvs maintenance on

# Backup database
kvs db:export --compress=gzip -o "backup-$(date +%Y%m%d).sql.gz"

# Do your work...

# Disable maintenance
kvs maintenance off
```

### Monitor System Health

```bash
# Quick status check
kvs system:status

# Detailed health check
kvs check

# View recent logs
kvs log cron --tail=50
```

## Tips and Tricks

### Use Aliases

Add to your `.bashrc`:

```bash
alias kv='kvs video'
alias ku='kvs user'
alias ks='kvs system:status'
```

### Combine with Unix Tools

```bash
# Count videos by status
kvs video list --format=json | jq '.[] | .status_id' | sort | uniq -c

# Find large videos
kvs video list --format=json | jq '.[] | select(.filesize > 1000000000)'

# Export user emails
kvs user list --fields=email --format=csv | tail -n +2 > emails.txt
```

### Scripting

```bash
#!/bin/bash
# daily-backup.sh

DATE=$(date +%Y%m%d)
BACKUP_DIR=/backups/kvs

kvs maintenance on
kvs db:export --compress=gzip -o "$BACKUP_DIR/db-$DATE.sql.gz"
kvs maintenance off

echo "Backup complete: $BACKUP_DIR/db-$DATE.sql.gz"
```

## Next Steps

- Read the full [Command Reference](commands/)
- Learn about [Configuration Options](configuration.md)
- Explore the [Internal Architecture](internal/) if you want to contribute
