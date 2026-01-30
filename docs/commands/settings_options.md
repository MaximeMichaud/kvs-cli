# kvs settings:options

**[EXPERIMENTAL]** Manage KVS system options.

## Synopsis

```bash
kvs settings:options [<action>] [<name>] [<value>] [options]
```

## Description

The `settings:options` command manages system-wide configuration options stored in the `ktvs_options` table. These options control various aspects of KVS behavior including features, anti-spam, statistics, and system flags.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `get`, `set` (default: `list`) |
| `name` | Conditional | Option name (required for `get` and `set`) |
| `value` | Conditional | New value (required for `set`) |

## Options

| Option | Description |
|--------|-------------|
| `-p, --prefix=PREFIX` | Filter by prefix (e.g., `ENABLE`, `ANTISPAM`) |
| `-c, --category=CATEGORY` | Filter by category (see below) |
| `-s, --search=SEARCH` | Search in option names |
| `--with-value=VALUE` | Filter by exact value match |
| `--enabled` | Show only enabled options (value=1) |
| `--disabled` | Show only disabled options (value=0) |
| `--fields=FIELDS` | Fields to display |
| `--format=FORMAT` | Output format: `table`, `csv`, `json`, `yaml`, `count` |
| `--force` | Skip experimental feature confirmation |

## Categories

| Category | Description |
|----------|-------------|
| `website` | Album, video, screenshot, category, tag, model, DVD settings |
| `memberzone` | User, tokens, premium, awards settings |
| `antispam` | Anti-spam configuration |
| `stats` | Statistics settings |
| `system` | System-wide flags (ENABLE_*, API_*, etc.) |

## Actions

### list

List options with optional filtering.

```bash
# All options
kvs options list

# Options starting with ENABLE
kvs options list --prefix=ENABLE

# Enabled options only
kvs options list --enabled

# By category
kvs options list --category=system

# Search by name
kvs options list --search=AVATAR
```

### get <name>

Get the value of a specific option.

```bash
kvs options get ENABLE_ANTI_HOTLINK
kvs options get ANTISPAM_BLACKLIST_ACTION
```

### set <name> <value>

Set a new value for an option.

```bash
kvs options set ENABLE_ANTI_HOTLINK 1
kvs options set ANTISPAM_BLACKLIST_ACTION delete
```

## Examples

### List Options

```bash
# All options
kvs options list

# All ENABLE_ flags
kvs options list --prefix=ENABLE

# Only enabled features
kvs options list --prefix=ENABLE --enabled

# Anti-spam options
kvs options list --prefix=ANTISPAM

# System category
kvs options list --category=system

# Search for specific option
kvs options list --search=AVATAR

# JSON output
kvs options list --format=json
```

### Get Option Values

```bash
# Get single option
kvs options get ENABLE_ANTI_HOTLINK

# Get multiple (using list with specific name)
kvs options list --search=VIDEO_SCREENSHOT
```

### Set Option Values

```bash
# Enable feature
kvs options set ENABLE_ANTI_HOTLINK 1

# Disable feature
kvs options set ENABLE_ANTI_HOTLINK 0

# Set text value
kvs options set ANTISPAM_BLACKLIST_ACTION delete

# Set numeric value
kvs options set MAX_UPLOAD_SIZE 1073741824
```

### Common Use Cases

```bash
# View all enabled features
kvs options list --prefix=ENABLE --enabled

# Check API settings
kvs options list --prefix=API

# Review anti-spam config
kvs options list --category=antispam

# Export all options
kvs options list --format=json > options-backup.json
```

## Sample Output

### List

```
KVS Options
===========

 Name                        Value           Category
 ENABLE_ANTI_HOTLINK        1               system
 ENABLE_VIDEO_EDIT          1               website
 ENABLE_ALBUM_EDIT          1               website
 ANTISPAM_BLACKLIST_ACTION  delete          antispam
 MAX_UPLOAD_SIZE            1073741824      website
 VIDEO_SCREENSHOT_COUNT     12              website
```

### Get

```bash
$ kvs options get ENABLE_ANTI_HOTLINK
```

```
1
```

## Common Options

### System Flags

| Option | Description | Values |
|--------|-------------|--------|
| `ENABLE_ANTI_HOTLINK` | Anti-hotlink protection | 0\|1 |
| `ENABLE_VIDEO_EDIT` | Allow video editing | 0\|1 |
| `ENABLE_ALBUM_EDIT` | Allow album editing | 0\|1 |
| `ENABLE_TOKENS` | Enable token system | 0\|1 |

### Content Settings

| Option | Description | Type |
|--------|-------------|------|
| `VIDEO_SCREENSHOT_COUNT` | Default screenshots per video | number |
| `MAX_UPLOAD_SIZE` | Max upload size in bytes | number |
| `VIDEO_AVATAR_SIZE` | Video thumbnail dimensions | text |

### Anti-Spam

| Option | Description | Values |
|--------|-------------|--------|
| `ANTISPAM_BLACKLIST_ACTION` | Action for blacklisted content | delete\|deactivate |
| `ANTISPAM_COMMENTS_CAPTCHA` | Comments captcha threshold | count/seconds |

## Aliases

- `kvs options`
- `kvs option`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- **Use with extreme caution** - changing options can break your site
- Always backup before modifying system options
- Some options require site restart or cache clear to take effect
- Invalid values may cause unexpected behavior

## Best Practices

1. **Document Changes** - Keep track of what you modify
2. **Test First** - Try changes on staging before production
3. **Backup** - Export options before making changes
4. **Verify** - Check values after setting with `get` action
5. **Read Docs** - Understand what each option does before changing

## Safety Tips

```bash
# Backup current settings
kvs options list --format=json > options-backup-$(date +%Y%m%d).json

# View before changing
kvs options get ENABLE_ANTI_HOTLINK

# Make change
kvs options set ENABLE_ANTI_HOTLINK 1

# Verify change
kvs options get ENABLE_ANTI_HOTLINK
```

## See Also

- [`system:status`](system_status.md) - System status
- [`system:check`](system_check.md) - Health checks
- [`settings:video-format`](settings_video_format.md) - Video format settings
- [`system:antispam`](system_antispam.md) - Anti-spam settings
