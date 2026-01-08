# KVS-CLI Documentation

**KVS-CLI** is a command-line interface for managing [KVS (Kernel Video Sharing)](http://kernel-team.com) installations. Inspired by [WP-CLI](https://wp-cli.org/), it provides a powerful set of commands for content management, system administration, and development tasks.

## Quick Links

| Section | Description |
|---------|-------------|
| [Installation](installation.md) | How to install and configure KVS-CLI |
| [Quick Start](quickstart.md) | Get up and running in 5 minutes |
| [Command Reference](commands/) | Complete list of all commands |
| [Configuration](configuration.md) | Configuration options and environment variables |
| [Internal Architecture](internal/) | For developers: how KVS-CLI works |

## Requirements

- **PHP 8.1+**
- **KVS 6.x** installation
- **MySQL/MariaDB** database access
- **Linux/macOS** (Windows via WSL)

## Installation

### PHAR (Recommended)

```bash
# Download latest release
curl -LO https://github.com/MaximeMichaud/kvs-cli/releases/latest/download/kvs.phar

# Make executable
chmod +x kvs.phar

# Move to PATH (optional)
sudo mv kvs.phar /usr/local/bin/kvs

# Verify installation
kvs --version
```

### From Source

```bash
git clone https://github.com/MaximeMichaud/kvs-cli.git
cd kvs-cli
composer install
./bin/kvs --version
```

## Basic Usage

```bash
# Navigate to your KVS installation
cd /path/to/kvs

# Check system status
kvs system:status

# List videos
kvs video list

# Show user details
kvs user show 5

# Run health checks
kvs check
```

## Command Categories

### Content Management

| Command | Description |
|---------|-------------|
| [`video`](commands/video.md) | Manage videos (list, show) |
| [`album`](commands/album.md) | Manage photo albums |
| [`user`](commands/user.md) | Manage users |
| [`comment`](commands/comment.md) | Manage comments |
| [`category`](commands/category.md) | Manage categories |
| [`tag`](commands/tag.md) | Manage tags |
| [`model`](commands/model.md) | Manage models/performers |
| [`dvd`](commands/dvd.md) | Manage DVDs/channels |

### System Administration

| Command | Description |
|---------|-------------|
| [`system:status`](commands/system_status.md) | Show system status |
| [`system:check`](commands/system_check.md) | Run health checks |
| [`system:cache`](commands/system_cache.md) | Manage cache |
| [`system:cron`](commands/system_cron.md) | Run cron jobs |
| [`system:backup`](commands/system_backup.md) | Create/restore backups |
| [`maintenance`](commands/maintenance.md) | Enable/disable maintenance mode |

### Database Operations

| Command | Description |
|---------|-------------|
| [`db:export`](commands/db_export.md) | Export database to SQL |
| [`db:import`](commands/db_import.md) | Import database from SQL |

### Development Tools

| Command | Description |
|---------|-------------|
| [`eval`](commands/eval.md) | Execute PHP code |
| [`shell`](commands/shell.md) | Interactive PHP shell |
| [`config`](commands/config.md) | View/edit configuration |
| [`dev:debug`](commands/dev_debug.md) | Debug information |
| [`dev:log`](commands/dev_log.md) | View logs |

### Utility Commands

| Command | Description |
|---------|-------------|
| [`self-update`](commands/self_update.md) | Update KVS-CLI |
| [`completion`](commands/completion.md) | Generate shell completion |

## Common Options

Most commands support these options:

```
--format=<format>    Output format: table, json, csv, yaml, count
--fields=<fields>    Comma-separated list of fields to display
--limit=<n>          Limit number of results (default: 20)
--offset=<n>         Skip first N results
--no-truncate        Don't truncate long text
--path=<path>        Path to KVS installation
```

## Output Formats

KVS-CLI supports multiple output formats for easy integration with other tools:

```bash
# Default table format
kvs video list

# JSON for scripting
kvs video list --format=json

# CSV for spreadsheets
kvs video list --format=csv

# Just count
kvs video list --format=count

# IDs only (for piping)
kvs video list --format=ids | xargs -I {} kvs video show {}
```

## Environment Variables

| Variable | Description |
|----------|-------------|
| `KVS_PATH` | Path to KVS installation |
| `KVS_DB_HOST` | Database host override |
| `KVS_DB_USER` | Database user override |
| `KVS_DB_PASS` | Database password override |
| `KVS_DB_NAME` | Database name override |

## Shell Completion

Enable tab completion for your shell:

```bash
# Bash
kvs completion bash | sudo tee /etc/bash_completion.d/kvs

# Zsh
kvs completion zsh > ~/.zsh/completion/_kvs

# Fish
kvs completion fish > ~/.config/fish/completions/kvs.fish
```

## Getting Help

```bash
# General help
kvs help

# Command-specific help
kvs help video
kvs video --help

# List all commands
kvs list
```

## Contributing

See [CONTRIBUTING.md](../CONTRIBUTING.md) for development setup and guidelines.

## License

KVS-CLI is open-source software licensed under the [MIT License](../LICENSE).
