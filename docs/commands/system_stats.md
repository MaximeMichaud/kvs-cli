# kvs system:stats

Show KVS site statistics (views, ratings, content).

## Synopsis

```bash
kvs system:stats [options]
```

## Description

The `system:stats` command displays comprehensive site statistics including content counts, views, ratings, and top performers across all content types.

## Options

| Option | Description |
|--------|-------------|
| `--videos` | Show detailed video statistics |
| `--albums` | Show detailed album statistics |
| `--users` | Show detailed user statistics |
| `--categories` | Show category statistics |
| `--tags` | Show tag statistics |
| `--models` | Show model/performer statistics |
| `--dvds` | Show DVD/channel statistics |
| `-t, --top=N` | Number of top items to show (default: 10) |
| `-p, --period=PERIOD` | Time period: `today`, `week`, `month`, `year`, `all` (default: `all`) |

## Time Periods

| Period | Description |
|--------|-------------|
| `today` | Statistics for today only |
| `week` | Last 7 days |
| `month` | Last 30 days |
| `year` | Last 365 days |
| `all` | All-time statistics (default) |

## Examples

### Overview Statistics

```bash
# Show overall site stats
kvs stats

# Stats for specific period
kvs stats --period=month
kvs stats --period=week
```

### Content-Specific Statistics

```bash
# Detailed video statistics
kvs stats --videos

# Album statistics
kvs stats --albums

# User statistics
kvs stats --users
```

### Category and Tag Statistics

```bash
# Top categories
kvs stats --categories

# Top tags
kvs stats --tags

# Top 20 categories
kvs stats --categories --top=20
```

### Model and DVD Statistics

```bash
# Top models/performers
kvs stats --models

# DVD/channel statistics
kvs stats --dvds
```

### Combined Statistics

```bash
# Videos and categories
kvs stats --videos --categories

# All content types
kvs stats --videos --albums --users --models
```

### Time-Based Statistics

```bash
# This week's top videos
kvs stats --videos --period=week

# This month's stats
kvs stats --period=month --videos --albums

# Today's activity
kvs stats --period=today
```

## Sample Output

### Overall Statistics

```
KVS Site Statistics
===================

Content Overview
----------------
 Videos          1,523 (1,401 active)
 Albums          456 (423 active)
 Users           89 (78 active)
 Comments        3,245
 Categories      42
 Tags            156
 Models          234
 DVDs            67

Activity (All Time)
-------------------
 Total Views     2,345,678
 Video Views     1,987,543
 Album Views     358,135
 Avg Video Rating 4.2/5
 Avg Album Rating 4.5/5

Top 10 Videos (by views)
------------------------
 1. Amazing Video Title      125,432 views
 2. Another Great Video      98,765 views
 3. Popular Content Here      87,543 views
 ...
```

### Video Statistics

```bash
$ kvs stats --videos
```

```
Video Statistics
================

Overview
--------
 Total Videos    1,523
 Active          1,401
 Disabled        110
 Errors          12

Views
-----
 Total Views     1,987,543
 Average Views   1,305 per video
 Top Video       125,432 views

Ratings
-------
 Average Rating  4.2/5
 Total Ratings   45,678
 Top Rated       4.9/5

Top 10 Videos (by views)
------------------------
 ID    Title                    Views      Rating
 123   Amazing Video Title      125,432    4.5/5
 456   Another Great Video      98,765     4.7/5
 ...
```

### Category Statistics

```bash
$ kvs stats --categories --top=5
```

```
Category Statistics
===================

Overview
--------
 Total Categories  42
 Active           38
 Inactive         4

Top 5 Categories (by content)
------------------------------
 ID  Category      Videos  Albums  Total
 1   Action        523     102     625
 2   Drama         412     89      501
 3   Comedy        301     67      368
 ...
```

## Use Cases

### Content Planning

```bash
# Identify popular categories
kvs stats --categories

# Find trending tags
kvs stats --tags --period=month

# Top performing models
kvs stats --models --top=20
```

### Performance Monitoring

```bash
# Track weekly growth
kvs stats --period=week

# Compare monthly stats
kvs stats --period=month --videos --albums
```

### User Engagement

```bash
# Active users this week
kvs stats --users --period=week

# Comment activity
kvs stats --period=month
```

## Aliases

- `kvs stats`

## Notes

- Statistics are calculated in real-time
- Large sites may take a few seconds to generate stats
- Use `--period` to focus on recent activity
- Combine multiple flags to see comprehensive stats

## See Also

- [`system:status`](system_status.md) - System health status
- [`video`](video.md) - Video management
- [`album`](album.md) - Album management
- [`user`](user.md) - User management
