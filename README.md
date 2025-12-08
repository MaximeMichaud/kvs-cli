# KVS CLI

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
# Download latest release
wget https://github.com/MaximeMichaud/kvs-cli/releases/latest/download/kvs.phar

# Install globally
sudo mv kvs.phar /usr/local/bin/kvs
sudo chmod +x /usr/local/bin/kvs

# Verify installation
kvs --version
```

> **Note**: Requires PHP 8.1+ with `phar` extension enabled.

## Usage

```bash
# Auto-detect KVS from current directory
cd /var/www/kvs
kvs system:status

# Or specify path
kvs --path=/var/www/kvs system:status

# Environment variable
export KVS_PATH=/var/www/kvs
kvs system:status
```

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
kvs eval 'echo User::count();'
kvs eval 'print_r(User::find(123));'
kvs eval 'foreach(Video::all(10) as $v) { echo $v["title"] . "\n"; }'

# Database queries
kvs eval 'print_r(DB::query("SELECT * FROM ktvs_users WHERE status_id = ?", [2]));'

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

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Acknowledgments

Inspired by [WP-CLI](https://wp-cli.org/).

## License

MIT
