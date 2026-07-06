# kvs system:cache

Manage system cache.

## Synopsis

```bash
kvs system:cache [options]
```

## Description

The `system:cache` command allows you to clear and view cache statistics for your KVS installation.

## Options

### --clear

Clear cache files.

```bash
kvs cache --clear [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--type=<type>` | all | Cache type to clear (`file` or `db`) |

**Cache Types:**

| Type | Description |
|------|-------------|
| `file` | Clear filesystem caches |
| `db` | Clear database cache tables |

### --stats

Show cache statistics.

```bash
kvs cache --stats
```

## Examples

### Clear All Caches

```bash
kvs cache --clear
```

Output:

```
Cleared 156 files from engine
Cleared 89 files from template-c
Cache cleared successfully.
```

### Clear Specific Cache

```bash
# Clear only filesystem cache
kvs cache --clear --type=file

# Clear only database cache
kvs cache --clear --type=db
```

### View Statistics

```bash
kvs cache --stats
```

Output:

```
Cache Statistics
================

Type          Files    Size      Last Modified
──────────────────────────────────────────────
Blocks        156      12.3 MB   5 minutes ago
Config        12       45.6 KB   1 hour ago
Templates     89       2.1 MB    30 minutes ago
──────────────────────────────────────────────
Total         257      14.4 MB
```

### Scripting Examples

```bash
# Clear cache and verify
kvs cache --clear && kvs cache --stats

# Clear cache before deployment
kvs maintenance on
kvs cache --clear
# ... deploy changes ...
kvs maintenance off
```

## Aliases

- `kvs cache`

## See Also

- [`system:status`](system-status.md) - Show system status
- [`maintenance`](maintenance.md) - Maintenance mode
