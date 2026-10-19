# KVS CLI

[![CI](https://github.com/MaximeMichaud/kvs-cli/actions/workflows/ci.yml/badge.svg)](https://github.com/MaximeMichaud/kvs-cli/actions/workflows/ci.yml)
[![Release](https://github.com/MaximeMichaud/kvs-cli/actions/workflows/release.yml/badge.svg)](https://github.com/MaximeMichaud/kvs-cli/actions/workflows/release.yml)

Command-line interface for [KVS (Kernel Video Sharing)](https://www.kernel-video-sharing.com/) CMS.

KVS CLI manages an existing application. Installation and migration are provided by
[KVS-install](https://github.com/MaximeMichaud/KVS-install).

Tested with KVS 6.3.2, 6.4.0, 7.0.0.

> **⚠️ Beta Notice**: This CLI has been tested with limited datasets. Read operations should work reliably, but exercise caution with any create, update, or delete operations. Always backup your data before performing write operations.

## Features

- **Intelligent path detection** - Auto-detects KVS installation by walking up directory tree
- **Flexible path options** - `--path` parameter, `KVS_PATH` environment variable, or auto-detection
- **Multiple output formats** - JSON, CSV, YAML, table, count, ids for all list commands
- **Interactive shell** - PHP REPL with KVS context loaded
- **Advanced eval** - Execute PHP with Model helpers, DB queries, and KVS context variables
- **Shell completion** - Tab completion for bash, zsh, and fish
- **Self-update** - Update directly from CLI with stable, preview, or dev builds
- **PHAR distribution** - Bundled PHP dependencies
- **Docker launcher** - Use the PHP runtime of KVS-install without PHP on the host

## Installation

One installer works with PHP on the host and Docker-only KVS-install setups:

```bash
curl -fsSL https://raw.githubusercontent.com/MaximeMichaud/kvs-cli/main/install.sh | bash
kvs --version
```

The installer verifies the PHAR checksum and uses local PHP 8.1+ when its required
extensions are available. Otherwise, it installs a launcher that runs PHP inside
an existing KVS container. It uses `sudo` only when the installation directory
requires it. No PHP packages are installed on the host.

For a Docker site when PHP is also installed on the host, append `-s -- --docker`
to `bash`. With several Docker sites, select one using `--path=/host/path/to/site`
or `KVS_DOCKER_CONTAINER`. See [installation options](docs/installation.md) for
user-local installation and manual PHAR downloads.

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

Repeat the installation command to update any installation. For Docker launcher
installations, this updates both files on the host; `self-update` is unavailable
through the launcher.

For native PHAR installations:

```bash
kvs self-update              # Check and update to latest version
kvs self-update --check      # Only check for available updates
kvs self-update --preview    # Include pre-release versions
kvs self-update --dev        # Update to latest dev build from CI
kvs self-update --yes        # Update without confirmation
```

If owned by root, use `sudo kvs self-update`.

## Shell Completion

Tab completion is available for bash, zsh, and fish:

```bash
# Bash
kvs completion bash >> ~/.bash_completion

# Zsh
kvs completion zsh > ~/.zsh/completion/_kvs

# Fish
kvs completion fish > ~/.config/fish/completions/kvs.fish
```

Restart your shell or source your config file to enable.

## Commands

Run `kvs list` for all available commands.

### System Commands

```bash
kvs system:status              # System information
kvs system:check               # Run health checks
kvs system:cache --clear       # Clear cache
kvs system:cron                # Run cron tasks
kvs system:backup --create     # Create backup
kvs system:benchmark           # Run performance benchmarks
kvs queue list                 # View background tasks queue
kvs queue stats                # Queue statistics
kvs maintenance on|off|status  # Maintenance mode
kvs info                       # CLI environment info
```

### Content Commands

```bash
kvs video list
kvs video show <id>
kvs video stats
kvs user list
kvs user show <id>
kvs user stats
kvs album list
kvs category list
kvs tag list
kvs comment list
kvs model list
kvs model stats
kvs dvd list
kvs dvd stats
kvs playlist list
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

Content list commands and many read-only list commands support multiple output
formats. Check `kvs <command> --help` for the exact formats accepted by a
specific command.

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
- KVS 6.3.2 - 7.0.0
- MySQL/MariaDB

## Development

### Setup

Install PHP 8.1+, Composer, Python 3, [pre-commit](https://pre-commit.com/),
Docker, and the test prerequisites: a MySQL/MariaDB client, Fish, Zsh, and Bash completion.
The PHP test runtime needs PDO MySQL, PDO SQLite, MySQLi, GD, Intl, Mbstring, and XML.
SQLite 3.44+ is required by tests that use ordered aggregate functions.
Then install the project dependencies and activate the hooks:

```bash
composer install
composer prepare
```

`composer prepare` runs `pre-commit install --install-hooks`. Hook tools use pinned
versions in isolated environments; no global PHP or Python dependencies are installed.
GitHub Actions validation uses the official actionlint Docker image so its embedded
ShellCheck version is the same locally and in CI.
The Bash compatibility check runs the `bash:3.2` Docker image. The minimum dependency
check builds the PHP 8.1 test image from `tools/php81.Dockerfile`; subsequent runs
reuse Docker layers and the Composer download cache. It has no access to the host
Docker socket.

### Git Hooks

Every commit and push runs all repository checks: PHP syntax, PHPCS, PHPStan level 10,
EditorConfig, ShellCheck, Bash style, Markdown, JSON/YAML/XML validation, secret
scanning, Composer validation and audit, the complete PHPUnit suite, the lowest
dependency analysis and test suite on PHP 8.1 in a temporary Docker container, and installer tests on
native Bash and Bash 3.2.
The commit message and pushed commits must follow Conventional Commits.

The commit hook temporarily stashes unstaged tracked changes and checks all tracked
files, so a working copy cannot hide an error in the staged commit. A failed check
blocks the commit. Formatting hooks may update files; review and stage those changes
before trying again.

To run the repository checks manually:

```bash
pre-commit run --all-files --hook-stage manual --show-diff-on-failure
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
