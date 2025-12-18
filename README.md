# KVS CLI

[![CI](https://github.com/MaximeMichaud/kvs-cli/actions/workflows/ci.yml/badge.svg)](https://github.com/MaximeMichaud/kvs-cli/actions/workflows/ci.yml)
[![Release](https://github.com/MaximeMichaud/kvs-cli/actions/workflows/release.yml/badge.svg)](https://github.com/MaximeMichaud/kvs-cli/actions/workflows/release.yml)

Command-line interface for [KVS (Kernel Video Sharing)](https://www.kernel-video-sharing.com/) CMS.

Tested with KVS 6.3.2.

## Features

- **Intelligent path detection** - Auto-detects KVS installation by walking up directory tree
- **Flexible path options** - `--path` parameter, `KVS_PATH` environment variable, or auto-detection
- **Multiple output formats** - JSON, CSV, YAML, table, count, ids for all list commands
- **Interactive shell** - PHP REPL with KVS context loaded
- **Advanced eval** - Execute PHP with Model helpers, DB queries, and KVS context variables
- **PHAR distribution** - Single executable file, no dependencies required

## Installation

```bash
# Download latest release (includes pre-releases)
curl -sL $(curl -s https://api.github.com/repos/MaximeMichaud/kvs-cli/releases | grep -o 'https://[^"]*kvs\.phar' | head -1) -o kvs.phar

# Make executable and install globally
chmod +x kvs.phar
mv kvs.phar /usr/local/bin/kvs

# Verify installation
kvs --version
```

> **Note**: Requires PHP 8.1+ with `phar` extension enabled.

## Usage

```bash
# Auto-detect KVS from current directory
cd /path/to/kvs
kvs system:status

# Or specify path
kvs --path=/path/to/kvs system:status

# Environment variable
export KVS_PATH=/path/to/kvs
kvs system:status
```

## Updating

```bash
kvs self-update              # Check and update to latest version
kvs self-update --check      # Only check for available updates
kvs self-update --preview    # Include pre-release versions
kvs self-update --yes        # Update without confirmation
```

If owned by root, use `sudo kvs self-update`.

## Commands

Run `kvs list` for all available commands.

### System Commands

```bash
kvs system:status              # System information
kvs system:cache clear         # Clear cache
kvs system:cron                # Run cron tasks
kvs system:backup              # Create backup
kvs maintenance on|off|status  # Maintenance mode
```

### Content Commands

```bash
kvs video list
kvs video show <id>
kvs user list
kvs user show <id>
kvs album list
kvs category list
kvs tag list
kvs comment list
kvs model list
kvs dvd list
```

### Database Commands

```bash
kvs db:export                  # Export database
kvs db:import backup.sql       # Import database
```

### Development Commands

```bash
kvs shell                      # Interactive PHP shell
kvs eval '<code>'              # Execute PHP code
kvs eval-file script.php       # Execute PHP file
kvs config list                # List configuration
kvs config get <key>           # Get config value
kvs dev:debug on|off           # Debug mode
kvs dev:log                    # View logs
```

## Output Formats

All list commands support multiple output formats:

```bash
kvs user list                      # Table (default)
kvs user list --format=json        # JSON
kvs user list --format=csv         # CSV
kvs user list --format=yaml        # YAML
kvs user list --format=count       # Count only
kvs user list --format=ids         # Space-separated IDs
kvs user list --fields=id,email    # Custom fields
kvs user list --field=email        # Single field per line
```

## Eval Command

Execute PHP code with full KVS context:

```bash
# Model helpers
kvs eval 'echo User::count() . "\n";'
kvs eval 'print_r(User::find(1));'
kvs eval 'foreach(Video::all(10) as $v) { echo $v["title"] . "\n"; }'

# Raw database query
kvs eval 'print_r(DB::query("SHOW TABLES"));'

# KVS variables
kvs eval 'echo $kvsPath;'
kvs eval 'print_r($config);'
```

Available Model classes: `User`, `Video`, `Album`, `Category`, `Tag`, `DVD`, `Model_`

## Path Detection

Priority order:

1. `--path` parameter (highest)
2. `KVS_PATH` environment variable
3. Auto-detection (walks up directory tree)

## Requirements

- PHP 8.1+
- KVS 6.3.2+ installation
- MySQL/MariaDB

## Development

### Setup

```bash
composer install
pip install pre-commit
pre-commit install
```

### Pre-commit Hooks

This project uses [pre-commit](https://pre-commit.com/) to run checks before each commit:

- **PHPCS** - Code style (PSR-12)
- **PHPStan** - Static analysis
- **PHPUnit** - Tests

To run all checks manually:

```bash
pre-commit run --all-files
```

### Testing

```bash
composer test           # Run tests
composer phpcs          # Check code style
composer phpstan        # Static analysis
composer check          # All checks
```

## Acknowledgments

Inspired by [WP-CLI](https://wp-cli.org/).

## License

MIT
