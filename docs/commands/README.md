# Command Reference

Complete reference for all KVS-CLI commands.

## Command Index

### Content Management

| Command | Aliases | Description |
|---------|---------|-------------|
| [`video`](video.md) | `videos`, `content:video` | Manage videos |
| [`album`](album.md) | `albums`, `content:album` | Manage photo albums |
| [`user`](user.md) | `users`, `content:user` | Manage users |
| [`user:purge`](user_purge.md) | `users:purge` | Bulk delete users |
| [`comment`](comment.md) | `comments`, `content:comment` | Manage comments |
| [`category`](category.md) | `categories`, `content:category` | Manage categories |
| [`tag`](tag.md) | `tags`, `content:tag` | Manage tags |
| [`model`](model.md) | `models`, `content:model` | Manage models/performers |
| [`dvd`](dvd.md) | `dvds`, `content:dvd` | Manage DVDs/channels |
| [`playlist`](playlist.md) | `playlists`, `content:playlist` | Manage playlists |

### System Administration

| Command | Aliases | Description |
|---------|---------|-------------|
| [`system:status`](system_status.md) | `status` | Show system status |
| [`system:check`](system_check.md) | `check` | Run health checks |
| [`system:cache`](system_cache.md) | `cache` | Manage cache |
| [`system:cron`](system_cron.md) | `cron` | Run cron jobs |
| [`system:backup`](system_backup.md) | `backup` | Create/restore backups |
| [`system:benchmark`](system_benchmark.md) | `benchmark`, `bench` | Run performance benchmarks |
| [`system:queue`](queue.md) | `queue` | Manage background tasks queue |
| [`system:server`](system_server.md) | `server`, `servers` | [EXPERIMENTAL] Manage storage servers |
| [`system:conversion`](system_conversion.md) | `conversion` | [EXPERIMENTAL] Manage conversion servers |
| [`system:email`](system_email.md) | `email` | [EXPERIMENTAL] Manage email settings |
| [`system:antispam`](system_antispam.md) | `antispam` | [EXPERIMENTAL] Manage anti-spam settings |
| [`system:stats`](system_stats.md) | `stats` | Show site statistics |
| [`system:stats-settings`](system_stats_settings.md) | `stats-settings` | [EXPERIMENTAL] Manage statistics collection |
| [`maintenance`](maintenance.md) | `maint` | Maintenance mode |

### Settings

| Command | Aliases | Description |
|---------|---------|-------------|
| [`settings:options`](settings_options.md) | `options`, `option` | [EXPERIMENTAL] Manage system options |
| [`settings:video-format`](settings_video_format.md) | `video-format`, `vformat` | [EXPERIMENTAL] Manage video format settings |

### Video Operations

| Command | Aliases | Description |
|---------|---------|-------------|
| [`video:formats`](video_formats.md) | `formats` | Manage video formats |
| [`video:screenshots`](video_screenshots.md) | `screenshots` | Manage screenshots |

### Database Operations

| Command | Aliases | Description |
|---------|---------|-------------|
| [`db:export`](db_export.md) | `database:export`, `db:dump` | Export database |
| [`db:import`](db_import.md) | `database:import`, `db:restore` | Import database |

### Development Tools

| Command | Aliases | Description |
|---------|---------|-------------|
| [`eval`](eval.md) | `eval-php` | Execute PHP code |
| [`eval-file`](eval_file.md) | `eval:file` | Execute PHP file |
| [`shell`](shell.md) | `console`, `repl` | Interactive shell |
| [`config`](config.md) | `conf`, `cfg` | Manage configuration |
| [`plugin`](plugin.md) | `plugins` | List plugins |
| [`dev:debug`](dev_debug.md) | `debug` | Debug information |
| [`dev:log`](dev_log.md) | `log`, `logs` | View logs |

### Migration Tools

| Command | Aliases | Description |
|---------|---------|-------------|
| [`migrate:scan`](migrate_scan.md) | `scan` | [EXPERIMENTAL] Scan installation for migration |
| [`migrate:package`](migrate_package.md) | `package` | [EXPERIMENTAL] Create migration package |
| [`migrate:import`](migrate_import.md) | `import` | [EXPERIMENTAL] Import migration package |
| [`migrate:to-docker`](migrate_to_docker.md) | `to-docker` | [EXPERIMENTAL] Migrate to Docker |

### Utility Commands

| Command | Aliases | Description |
|---------|---------|-------------|
| [`cli:info`](cli_info.md) | `info` | Display CLI environment info |
| [`self-update`](self_update.md) | `selfupdate`, `self:update` | Update KVS-CLI |
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
