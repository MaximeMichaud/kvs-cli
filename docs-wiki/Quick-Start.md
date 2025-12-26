# Quick Start

Get up and running with KVS-CLI in 5 minutes.

## Prerequisites

- KVS-CLI [[Installation|installed]]
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
 KVS Version   6.3.2

Database
--------
 Host          127.0.0.1
 Database      kvs_production
 MySQL Version 8.0.35
```

## Step 3: Explore Content

### List Videos

```bash
kvs video list
kvs video list --limit=50
kvs video list --status=1        # Active only
kvs video list --format=json
```

### View Video Details

```bash
kvs video show 123
```

### List Users

```bash
kvs user list
kvs user list --status=3         # Premium users
kvs user list --format=csv > users.csv
```

### View Comments

```bash
kvs comment list
kvs comment list --video=123
kvs comment stats
```

## Step 4: System Administration

### Run Health Checks

```bash
kvs check
```

Checks PHP, database, FFmpeg, disk space, cron status, and more.

### View Configuration

```bash
kvs config list
kvs config get project_version
```

### Manage Cache

```bash
kvs cache clear
```

### Maintenance Mode

```bash
kvs maintenance on
kvs maintenance status
kvs maintenance off
```

## Step 5: Database Operations

### Export Database

```bash
kvs db:export
kvs db:export --compress=gzip -o backup.sql.gz
```

### Import Database

```bash
kvs db:import backup.sql
```

## Step 6: Development Tools

### Execute PHP Code

```bash
kvs eval 'echo Video::count();'
kvs eval 'print_r(DB::query("SELECT COUNT(*) FROM ktvs_videos"));'
```

### Interactive Shell

```bash
kvs shell
```

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
```

### Backup Before Maintenance

```bash
kvs maintenance on
kvs db:export --compress=gzip -o "backup-$(date +%Y%m%d).sql.gz"
# Do your work...
kvs maintenance off
```

## Tips

### Use Aliases

Add to your `.bashrc`:

```bash
alias kv='kvs video'
alias ku='kvs user'
alias ks='kvs system:status'
```

### Combine with Unix Tools

```bash
kvs video list --format=json | jq '.[] | .status_id' | sort | uniq -c
kvs user list --fields=email --format=csv | tail -n +2 > emails.txt
```

## Next Steps

- [[Home]] - Full command reference
- [[Configuration]] - Advanced options
