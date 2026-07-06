# kvs plugin

Manage installed KVS plugins.

## Synopsis

```bash
kvs plugin [<action>] [<id>] [options]
```

## Description

The `plugin` command provides read-only access to installed KVS plugins. Activation and
deactivation are handled in the KVS admin panel.

## Actions

### list

List all installed plugins.

```bash
kvs plugin list [options]
```

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--status=<status>` | all | Filter by status: `active`, `inactive`, `all` |
| `--type=<type>` | - | Filter by type: `manual`, `cron`, `api`, `process_object` |
| `--fields=<fields>` | `id,name,version,status,types` | Comma-separated fields |
| `--field=<field>` | - | Display a single field value |
| `--format=<format>` | table | Output format: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `--no-truncate` | - | Disable truncation of long text fields |

### show

Show details for one plugin.

```bash
kvs plugin show <id> [options]
```

### path

Print one plugin directory path.

```bash
kvs plugin path <id>
```

### status

Show plugin statistics.

```bash
kvs plugin status [options]
```

## Default Fields

- `id` - Plugin ID
- `name` - Plugin name
- `version` - Plugin version
- `status` - Plugin status

## Available Fields

- `id`
- `name`
- `title`
- `author`
- `version`
- `kvs_version`
- `status`
- `enabled`
- `types`
- `files_ok`
- `syntax_ok`
- `compatible`
- `valid`
- `description`
- `path`

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
kvs plugin list --fields=id,name,version,status
kvs plugin list --field=path
```

### Details And Paths

```bash
kvs plugin show backup
kvs plugin path backup
kvs plugin status
```

## Aliases

- `kvs plugins`
- `kvs plug`

## See Also

- [`system:status`](system_status.md) - System status
- [`config`](config.md) - Configuration
