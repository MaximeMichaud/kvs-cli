# kvs plugin

List installed KVS plugins.

## Synopsis

```bash
kvs plugin <action> [options]
```

## Description

The `plugin` command allows you to list and view information about installed KVS plugins.

## Actions

### list

List all installed plugins.

```bash
kvs plugin list [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--fields=<fields>` | - | Comma-separated fields |
| `--format=<format>` | table | Output format |

## Default Fields

- `plugin_id` - Plugin ID
- `name` - Plugin name
- `version` - Plugin version
- `status` - Plugin status

## Examples

### List Plugins

```bash
kvs plugin list
```

Output:

```
Installed Plugins
=================

 ID  Name              Version  Status
 1   SEO Optimization  2.1.0    Active
 2   Social Sharing    1.5.2    Active
 3   Analytics         1.0.0    Disabled
```

### Output Formats

```bash
# JSON
kvs plugin list --format=json

# CSV
kvs plugin list --format=csv

# Count
kvs plugin list --format=count
```

### Field Selection

```bash
kvs plugin list --fields=name,version
```

## Aliases

- `kvs plugins`

## See Also

- [[Command-system-status|system:status]] - System status
- [[Command-config|config]] - Configuration
