# kvs system:status

Show KVS system status and information.

## Synopsis

```bash
kvs system:status
```

## Description

The `system:status` command displays comprehensive information about your KVS installation, including system configuration, database status, content statistics, and health checks.

## Sections

### Installation

Shows KVS installation paths and version:

```
Installation
------------
 KVS Path      /var/www/kvs
 Admin Path    /var/www/kvs/admin
 Content Path  /var/www/kvs/contents
 KVS Version   6.3.2
```

### Database

Shows database connection information:

```
Database
--------
 Host          127.0.0.1
 Database      kvs_production
 MySQL Version 8.0.35
 Total Tables  156
 Database Size 2.4 GB
```

### System

Shows server and PHP information:

```
System
------
 Operating System   CachyOS Linux (kernel 6.18.1)
 Web Server         CLI
 PHP Version        8.2.15
 PHP Memory Limit   256M
 Max Execution Time 120 seconds
 Upload Max Size    128M
 Post Max Size      128M
 Disk Usage         45.2 GB / 500 GB (9.0%)
```

### Content Statistics

Shows content counts:

```
Content Statistics
------------------
 Videos      15,432
 Albums      3,210
 Users       8,765
 Categories  45
 Tags        1,234
 Models      567
 DVDs        89
```

### Services Status

Shows external tool availability:

```
Services Status
---------------
 ✓ FFmpeg       /usr/bin/ffmpeg       6.0.0
 ✓ ImageMagick  /usr/bin/convert      7.1.0
 ✓ MySQLDump    /usr/bin/mysqldump    8.0.35
 ✓ Memcached    127.0.0.1:11211       Connected
```

### Video Processing

Shows conversion queue status:

```
Video Processing
----------------
 Pending       12
 Processing    2
 Failed (24h)  0
 Average Time  3m 45s
```

### Storage Breakdown

Shows content directory sizes:

```
Storage Breakdown
-----------------
 Videos Sources    156.7 GB  12,543 files
 Screenshots       23.4 GB   45,231 files
 Albums            12.3 GB   8,765 files
 Categories        45.2 MB   89 files
 Models            123.4 MB  234 files
 Avatars           56.7 MB   456 files
 ---               ---       ---
 Total Content     192.8 GB
```

### System Health

Shows health check results:

```
System Health
-------------
 ✓ Database connectivity    OK
 ✓ Admin data directory     Writable
 ✓ Content directory        Writable
 ✓ Disk space              9.0% used
 ✓ PHP extensions          All required extensions loaded
```

### Security

Shows security-related settings:

```
Security
--------
 ✓ Maintenance mode    DISABLED
 ✓ Debug mode          DISABLED
 ⚠ Database backups    Last backup 3 days ago
 ✓ PHP display_errors  DISABLED
```

## Examples

```bash
# Show full status
kvs system:status

# Combine with check for detailed analysis
kvs system:status && kvs check
```

## Aliases

- `kvs status`

## See Also

- [[Command-system-check|system:check]] - Run health checks
- [[Command-config|config]] - View configuration
- [[Command-system-cache|system:cache]] - Manage cache
