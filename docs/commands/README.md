# Command Reference

Complete reference for all KVS-CLI commands.

## Command Index

### Content Management

| Command | Aliases | Description |
|---------|---------|-------------|
| [`video`](video.md) | `videos`, `content:video` | Manage videos |
| [`album`](album.md) | `albums`, `content:album` | Manage photo albums |
| [`user`](user.md) | `users`, `content:user` | Manage users |
| [`user:purge`](user-purge.md) | `users:purge` | Bulk delete users |
| [`comment`](comment.md) | `comments`, `content:comment` | Manage comments |
| [`category`](category.md) | `categories`, `content:category` | Manage categories |
| [`tag`](tag.md) | `tags`, `content:tag` | Manage tags |
| [`model`](model.md) | `models`, `content:model` | Manage models/performers |
| [`dvd`](dvd.md) | `dvds`, `content:dvd` | Manage DVDs/channels |

### System Administration

| Command | Aliases | Description |
|---------|---------|-------------|
| [`system:status`](system-status.md) | `status` | Show system status |
| [`system:check`](system-check.md) | `check` | Run health checks |
| [`system:cache`](system-cache.md) | `cache` | Manage cache |
| [`system:cron`](system-cron.md) | `cron` | Run cron jobs |
| [`system:backup`](system-backup.md) | `backup` | Create/restore backups |
| [`system:benchmark`](system-benchmark.md) | `benchmark`, `bench` | Run performance benchmarks |
| [`system:queue`](queue.md) | `queue` | Manage background tasks queue |
| [`maintenance`](maintenance.md) | `maint` | Maintenance mode |

### Video Operations

| Command | Aliases | Description |
|---------|---------|-------------|
| [`video:formats`](video-formats.md) | `formats` | Manage video formats |
| [`video:screenshots`](video-screenshots.md) | `screenshots` | Manage screenshots |

### Database Operations

| Command | Aliases | Description |
|---------|---------|-------------|
| [`db:export`](db-export.md) | `database:export`, `db:dump` | Export database |
| [`db:import`](db-import.md) | `database:import`, `db:restore` | Import database |

### Development Tools

| Command | Aliases | Description |
|---------|---------|-------------|
| [`eval`](eval.md) | `eval-php` | Execute PHP code |
| [`eval-file`](eval-file.md) | `eval:file` | Execute PHP file |
| [`shell`](shell.md) | `console`, `repl` | Interactive shell |
| [`config`](config.md) | `conf`, `cfg` | Manage configuration |
| [`plugin`](plugin.md) | `plugins` | List plugins |
| [`dev:debug`](dev-debug.md) | `debug` | Debug information |
| [`dev:log`](dev-log.md) | `log`, `logs` | View logs |

### Utility Commands

| Command | Aliases | Description |
|---------|---------|-------------|
| [`cli:info`](cli-info.md) | `info` | Display CLI environment info |
| [`self-update`](self-update.md) | `selfupdate`, `self:update` | Update KVS-CLI |
| [`completion`](completion.md) | - | Shell completion |

## Global Options

All commands support:

```
-h, --help            Display help for the command
-q, --quiet           Suppress non-essential output
-v, --verbose         Increase verbosity (-v, -vv, -vvv)
-V, --version         Display version
    --ansi            Force ANSI output
    --no-ansi         Disable ANSI output
    --path=PATH       Path to KVS installation
```

## Common Options

Most list commands support:

```
--format=FORMAT       Output format (table, json, csv, yaml, count, ids)
--fields=FIELDS       Comma-separated list of fields
--limit=N             Limit number of results (default: 20)
--offset=N            Skip first N results
--no-truncate         Don't truncate long values
--status=ID           Filter by status ID
```

## Output Formats

| Format | Description | Use Case |
|--------|-------------|----------|
| `table` | Formatted table (default) | Human reading |
| `json` | Pretty JSON | Scripting, APIs |
| `csv` | CSV with headers | Spreadsheets |
| `yaml` | YAML format | Config files |
| `count` | Just the count | Quick stats |
| `ids` | Space-separated IDs | Piping to other commands |

## Status Values

### Video Status
- `0` - Disabled (yellow)
- `1` - Active (green)
- `2` - Error (red)

### User Status
- `0` - Disabled (red)
- `1` - Not Confirmed (yellow)
- `2` - Active (green)
- `3` - Premium (cyan)
- `4` - VIP (magenta)
- `6` - Webmaster (blue)

### Album Status
- `0` - Disabled (yellow)
- `1` - Active (green)

### Category/Tag Status
- `0` - Inactive (yellow)
- `1` - Active (green)

## Getting Help

```bash
# List all commands
kvs list

# Get help for a command
kvs help video
kvs video --help
kvs video list --help
```
