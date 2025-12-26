# Installation

This guide covers all methods of installing KVS-CLI.

## Requirements

- **PHP 8.1 or higher** (8.2+ recommended for best performance)
- **KVS 6.x** installation
- **MySQL 8.0+ or MariaDB 10.6+**
- **Linux or macOS** (Windows users should use WSL)

### PHP Extensions Required

- `pdo_mysql` - Database connectivity
- `json` - JSON parsing
- `mbstring` - Unicode support
- `readline` - Interactive shell (optional)

## Installation Methods

### Method 1: PHAR (Recommended)

The PHAR archive is a self-contained executable that includes all dependencies.

```bash
curl -LO https://github.com/MaximeMichaud/kvs-cli/releases/latest/download/kvs.phar
chmod +x kvs.phar
php kvs.phar --version
```

#### Install Globally

To use `kvs` from anywhere:

```bash
sudo mv kvs.phar /usr/local/bin/kvs
kvs --version
```

#### User-Local Installation

```bash
mkdir -p ~/.local/bin
mv kvs.phar ~/.local/bin/kvs
echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.bashrc
source ~/.bashrc
```

### Method 2: From Source

For development or to get the latest features:

```bash
git clone https://github.com/MaximeMichaud/kvs-cli.git
cd kvs-cli
composer install
./bin/kvs --version
```

### Method 3: Composer Global

```bash
composer global require maximemichaud/kvs-cli
export PATH="$HOME/.composer/vendor/bin:$PATH"
```

## Verifying Installation

```bash
kvs --version
# KVS-CLI 1.0.0
```

## KVS Path Configuration

KVS-CLI automatically detects your KVS installation.

### Option 1: Run from KVS Directory (Recommended)

```bash
cd /var/www/kvs
kvs system:status
```

### Option 2: Use --path Option

```bash
kvs --path=/var/www/kvs system:status
```

### Option 3: Environment Variable

```bash
export KVS_PATH=/var/www/kvs
kvs system:status
```

Add to your shell profile for persistence:

```bash
echo 'export KVS_PATH=/var/www/kvs' >> ~/.bashrc
```

## Database Configuration

KVS-CLI reads database credentials from your KVS installation's `admin/include/setup_db.php` file.

### Environment Variable Overrides

```bash
export KVS_DB_HOST=127.0.0.1
export KVS_DB_USER=kvs_user
export KVS_DB_PASS=secret
export KVS_DB_NAME=kvs_production
```

## Updating KVS-CLI

```bash
kvs self-update --check    # Check for updates
kvs self-update            # Update to latest stable
kvs self-update --dev      # Update to dev build
kvs self-update --yes      # Skip confirmation
```

If installed system-wide: `sudo kvs self-update`

## Shell Completion

### Bash

```bash
kvs completion bash | sudo tee /etc/bash_completion.d/kvs
```

### Zsh

```bash
kvs completion zsh > ~/.zsh/completion/_kvs
```

### Fish

```bash
kvs completion fish > ~/.config/fish/completions/kvs.fish
```

## Troubleshooting

### "KVS installation not found"

1. Navigate to the KVS directory: `cd /path/to/kvs`
2. Use `--path` option: `kvs --path=/path/to/kvs system:status`
3. Set environment variable: `export KVS_PATH=/path/to/kvs`

### "Database connection failed"

Check `admin/include/setup_db.php` and test with:

```bash
kvs config get db.host
kvs system:status
```

### Permission Errors

```bash
sudo kvs self-update
# Or fix ownership
sudo chown $USER:$USER /usr/local/bin/kvs
```

## Next Steps

- [[Quick-Start]] - Learn basic usage
- [[Configuration]] - Advanced options
- [[Home]] - Command reference
