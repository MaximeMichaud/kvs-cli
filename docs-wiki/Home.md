# KVS-CLI

**KVS-CLI** is a command-line interface for managing [KVS (Kernel Video Sharing)](https://www.kernel-video-sharing.com/) installations. Inspired by [WP-CLI](https://wp-cli.org/), it provides powerful commands for content management, system administration, and development tasks.

## Requirements

- **PHP 8.1+** (8.2+ recommended)
- **KVS 6.x** installation
- **MySQL/MariaDB** database access
- **Linux/macOS** (Windows via WSL)

## Quick Install

```bash
curl -LO https://github.com/MaximeMichaud/kvs-cli/releases/latest/download/kvs.phar
chmod +x kvs.phar
sudo mv kvs.phar /usr/local/bin/kvs
kvs --version
```

See [[Installation]] for detailed instructions.

## Basic Usage

```bash
cd /path/to/kvs
kvs system:status
kvs video list
kvs user show 5
kvs check
```

See [[Quick-Start]] for more examples.

## Command Categories

### Content Management

| Command | Description |
|---------|-------------|
| [[Command-video]] | Manage videos |
| [[Command-album]] | Manage photo albums |
| [[Command-user]] | Manage users |
| [[Command-comment]] | Manage comments |
| [[Command-category]] | Manage categories |
| [[Command-tag]] | Manage tags |
| [[Command-model]] | Manage models/performers |
| [[Command-dvd]] | Manage DVDs/channels |

### System Administration

| Command | Description |
|---------|-------------|
| [[Command-system-status]] | Show system status |
| [[Command-system-check]] | Run health checks |
| [[Command-system-cache]] | Manage cache |
| [[Command-system-cron]] | Run cron jobs |
| [[Command-system-backup]] | Create/restore backups |
| [[Command-system-benchmark]] | Run performance benchmarks |
| [[Command-queue]] | Manage background tasks queue |
| [[Command-maintenance]] | Maintenance mode |

### Database Operations

| Command | Description |
|---------|-------------|
| [[Command-db-export]] | Export database |
| [[Command-db-import]] | Import database |

### Development Tools

| Command | Description |
|---------|-------------|
| [[Command-eval]] | Execute PHP code |
| [[Command-eval-file]] | Execute PHP file |
| [[Command-shell]] | Interactive PHP shell |
| [[Command-config]] | View/edit configuration |
| [[Command-plugin]] | List plugins |
| [[Command-cli-info]] | CLI environment info |
| [[Command-dev-debug]] | Debug mode |
| [[Command-dev-log]] | View logs |

### Utility Commands

| Command | Description |
|---------|-------------|
| [[Command-self-update]] | Update KVS-CLI |
| [[Command-completion]] | Shell completion |

## Common Options

```
--format=<format>    Output format: table, json, csv, yaml, count
--fields=<fields>    Comma-separated list of fields
--limit=<n>          Limit results (default: 20)
--path=<path>        Path to KVS installation
```

## Getting Help

```bash
kvs help
kvs help video
kvs list
```

## Links

- [GitHub Repository](https://github.com/MaximeMichaud/kvs-cli)
- [Issue Tracker](https://github.com/MaximeMichaud/kvs-cli/issues)
- [Releases](https://github.com/MaximeMichaud/kvs-cli/releases)
