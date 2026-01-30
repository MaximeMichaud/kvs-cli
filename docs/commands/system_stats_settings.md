# kvs system:stats-settings

**[EXPERIMENTAL]** Manage KVS statistics collection settings.

## Synopsis

```bash
kvs system:stats-settings [<action>] [options]
```

## Description

The `system:stats-settings` command configures which statistics KVS collects and how long to retain them. This includes traffic stats, player analytics, video/album views, search queries, and performance metrics.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `show`, `set` (default: `show`) |

## Options

### General

| Option | Description |
|--------|-------------|
| `--format=FORMAT` | Output format: `table`, `json` |
| `--force` | Skip experimental feature confirmation |

### Traffic Statistics

| Option | Description |
|--------|-------------|
| `--traffic` | Collect traffic stats (0\|1) |
| `--traffic-countries` | Collect country data (0\|1) |
| `--traffic-devices` | Collect device data (0\|1) |
| `--traffic-embed-domains` | Collect embed domain data (0\|1) |
| `--traffic-keep` | Retention period in days (0=forever) |

### Player Statistics

| Option | Description |
|--------|-------------|
| `--player` | Collect player stats (0\|1) |
| `--player-countries` | Collect country data (0\|1) |
| `--player-devices` | Collect device data (0\|1) |
| `--player-embed-profiles` | Collect embed profile data (0\|1) |
| `--player-keep` | Retention period in days (0=forever) |
| `--player-reporting` | Enable player reporting (0\|1) |

### Video Statistics

| Option | Description |
|--------|-------------|
| `--videos` | Collect video stats (0\|1) |
| `--videos-unique` | Collect unique views (0\|1) |
| `--videos-embeds-unique` | Collect unique embed views (0\|1) |
| `--videos-plays` | Collect video plays (0\|1) |
| `--videos-files` | Collect video file stats (0\|1) |
| `--videos-keep` | Retention period in days (0=forever) |
| `--videos-countries-mode` | Country filter (`none`, `include`, `exclude`) |
| `--videos-countries` | Country codes (comma-separated, e.g., `US,CA,GB`) |

### Album Statistics

| Option | Description |
|--------|-------------|
| `--albums` | Collect album stats (0\|1) |
| `--albums-unique` | Collect unique views (0\|1) |
| `--albums-images` | Collect album image stats (0\|1) |
| `--albums-keep` | Retention period in days (0=forever) |
| `--albums-countries-mode` | Country filter (`none`, `include`, `exclude`) |
| `--albums-countries` | Country codes (comma-separated) |

### Memberzone Statistics

| Option | Description |
|--------|-------------|
| `--memberzone` | Collect memberzone stats (0\|1) |
| `--memberzone-video-files` | Collect video file downloads (0\|1) |
| `--memberzone-album-images` | Collect album image views (0\|1) |
| `--memberzone-keep` | Retention period in days (0=forever) |

### Search Statistics

| Option | Description |
|--------|-------------|
| `--search` | Collect search stats (0\|1) |
| `--search-keep` | Retention period in days (0=forever) |
| `--search-inactive` | Mark inactive searches (0\|1) |
| `--search-lowercase` | Convert to lowercase (0\|1) |
| `--search-max-length` | Max search length (0=unlimited) |
| `--search-stop-symbols` | Symbols to remove from searches |
| `--search-countries-mode` | Country filter (`none`, `include`, `exclude`) |
| `--search-countries` | Country codes (comma-separated) |

### Performance Statistics

| Option | Description |
|--------|-------------|
| `--performance` | Collect performance stats (0\|1) |

## Actions

### show

Display current statistics collection settings.

```bash
kvs stats-settings show
kvs stats-settings show --format=json
```

### set

Update statistics collection settings.

```bash
# Enable traffic stats
kvs stats-settings set --traffic=1

# Configure video stats
kvs stats-settings set --videos=1 --videos-unique=1

# Set retention period
kvs stats-settings set --videos-keep=30
```

## Country Filter Modes

| Mode | Description |
|------|-------------|
| `none` | No filtering (collect all) |
| `include` | Only collect from specified countries |
| `exclude` | Collect from all except specified countries |

## Examples

### View Current Settings

```bash
# Table format
kvs stats-settings show

# JSON format
kvs stats-settings show --format=json
```

### Enable Statistics Collection

```bash
# Enable traffic stats
kvs stats-settings set --traffic=1 --traffic-countries=1

# Enable video stats
kvs stats-settings set --videos=1 --videos-unique=1

# Enable search stats
kvs stats-settings set --search=1
```

### Configure Retention

```bash
# Keep video stats for 30 days
kvs stats-settings set --videos-keep=30

# Keep traffic stats for 90 days
kvs stats-settings set --traffic-keep=90

# Keep search stats forever
kvs stats-settings set --search-keep=0
```

### Country Filtering

```bash
# Only collect from US, CA, GB
kvs stats-settings set \
  --videos-countries-mode=include \
  --videos-countries="US,CA,GB"

# Exclude specific countries
kvs stats-settings set \
  --videos-countries-mode=exclude \
  --videos-countries="CN,RU"

# Clear country filter
kvs stats-settings set \
  --videos-countries-mode=none \
  --videos-countries="clear"
```

### Search Configuration

```bash
# Convert searches to lowercase
kvs stats-settings set --search-lowercase=1

# Limit search length
kvs stats-settings set --search-max-length=100

# Remove special symbols
kvs stats-settings set --search-stop-symbols="!@#$%"
```

### Comprehensive Setup

```bash
# Enable all major statistics
kvs stats-settings set \
  --traffic=1 \
  --traffic-countries=1 \
  --player=1 \
  --player-countries=1 \
  --videos=1 \
  --videos-unique=1 \
  --albums=1 \
  --albums-unique=1 \
  --search=1 \
  --performance=1

# Set retention periods
kvs stats-settings set \
  --traffic-keep=30 \
  --player-keep=90 \
  --videos-keep=365 \
  --albums-keep=365 \
  --search-keep=180
```

## Sample Output

```
Statistics Collection Settings
===============================

Traffic Statistics
------------------
 Enabled           Yes
 Countries         Yes
 Devices           Yes
 Embed Domains     Yes
 Retention         30 days

Player Statistics
-----------------
 Enabled           Yes
 Countries         Yes
 Devices           Yes
 Embed Profiles    No
 Reporting         Yes
 Retention         90 days

Video Statistics
----------------
 Enabled           Yes
 Unique Views      Yes
 Unique Embeds     Yes
 Video Plays       Yes
 File Stats        Yes
 Retention         365 days
 Country Filter    Include: US, CA, GB

Album Statistics
----------------
 Enabled           Yes
 Unique Views      Yes
 Image Stats       Yes
 Retention         365 days
 Country Filter    None

Search Statistics
-----------------
 Enabled           Yes
 Inactive Mark     Yes
 Lowercase         Yes
 Max Length        100
 Stop Symbols      !@#$%
 Retention         180 days
 Country Filter    None

Performance Stats
-----------------
 Enabled           Yes
```

## Performance Considerations

### Database Impact

- More statistics = larger database
- Use retention periods to manage size
- Disable unused statistics to improve performance

### Recommended Settings

**Small Sites (<1,000 videos)**:
```bash
kvs stats-settings set \
  --traffic=1 --traffic-keep=90 \
  --videos=1 --videos-keep=0 \
  --search=1 --search-keep=0
```

**Medium Sites (1,000-10,000 videos)**:
```bash
kvs stats-settings set \
  --traffic=1 --traffic-keep=30 \
  --player=1 --player-keep=90 \
  --videos=1 --videos-keep=365 \
  --search=1 --search-keep=180
```

**Large Sites (>10,000 videos)**:
```bash
kvs stats-settings set \
  --traffic=1 --traffic-keep=7 \
  --player=1 --player-keep=30 \
  --videos=1 --videos-keep=90 \
  --search=1 --search-keep=30
```

## Aliases

- `kvs stats-settings`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- Changes take effect immediately
- Use retention periods to prevent database bloat
- Country filtering reduces data collection significantly
- Performance stats may impact site speed on high-traffic sites

## Best Practices

1. **Start Conservative** - Enable only what you need
2. **Monitor Size** - Check database size regularly
3. **Set Retention** - Don't keep stats forever unless necessary
4. **Filter Countries** - Use country filtering if you target specific regions
5. **Review Regularly** - Adjust settings based on actual needs

## See Also

- [`system:stats`](system_stats.md) - View collected statistics
- [`system:status`](system_status.md) - System status
- [`system:check`](system_check.md) - Health checks
