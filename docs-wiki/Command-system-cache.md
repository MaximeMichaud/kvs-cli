# kvs system:cache

Manage system cache.

## Synopsis

```bash
kvs system:cache <action> [options]
```

## Description

The `system:cache` command allows you to clear and view cache statistics for your KVS installation.

## Actions

### clear

Clear cache files.

```bash
kvs cache clear [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--type=<type>` | all | Cache type to clear |

**Cache Types:**

| Type | Description |
|------|-------------|
| `all` | Clear all caches |
| `blocks` | Block output cache |
| `config` | Configuration cache |
| `templates` | Compiled templates |

### stats

Show cache statistics.

```bash
kvs cache stats
```

## Examples

### Clear All Caches

```bash
kvs cache clear
```

Output:

```
Clearing all caches...
 ✓ Blocks cache cleared (156 files)
 ✓ Config cache cleared (12 files)
 ✓ Templates cache cleared (89 files)

Cache cleared successfully.
```

### Clear Specific Cache

```bash
# Clear only block cache
kvs cache clear --type=blocks

# Clear only config cache
kvs cache clear --type=config

# Clear only template cache
kvs cache clear --type=templates
```

### View Statistics

```bash
kvs cache stats
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
kvs cache clear && kvs cache stats

# Clear cache before deployment
kvs maintenance on
kvs cache clear
# ... deploy changes ...
kvs maintenance off
```

## Aliases

- `kvs cache`

## See Also

- [[Command-system-status|system:status]] - Show system status
- [[Command-maintenance|maintenance]] - Maintenance mode
