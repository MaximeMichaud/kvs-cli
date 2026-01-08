# Installation

This guide covers all methods of installing KVS-CLI.

## Requirements

- **PHP 8.1+**
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
# Download the latest stable release
curl -LO https://github.com/MaximeMichaud/kvs-cli/releases/latest/download/kvs.phar

# Make it executable
chmod +x kvs.phar

# Verify it works
php kvs.phar --version
```

#### Install Globally

To use `kvs` from anywhere:

```bash
# Move to a directory in your PATH
sudo mv kvs.phar /usr/local/bin/kvs

# Now use it as 'kvs'
kvs --version
```

#### Alternative Locations

```bash
# User-local installation (no sudo required)
mkdir -p ~/.local/bin
mv kvs.phar ~/.local/bin/kvs
echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.bashrc
source ~/.bashrc
```

### Method 2: From Source (Development)

For development or to get the latest features:

```bash
# Clone the repository
git clone https://github.com/MaximeMichaud/kvs-cli.git
cd kvs-cli

# Install dependencies
composer install

# Run from source
./bin/kvs --version

# Optional: Create a symlink
sudo ln -s $(pwd)/bin/kvs /usr/local/bin/kvs
```

### Method 3: Composer Global

```bash
composer global require maximemichaud/kvs-cli

# Ensure global composer bin is in PATH
export PATH="$HOME/.composer/vendor/bin:$PATH"
```

## Verifying Installation

```bash
# Check version
kvs --version

# Should output something like:
# KVS-CLI 1.0.0
```

## Configuration

KVS-CLI automatically detects your KVS installation. You have several options:

### Option 1: Run from KVS Directory (Recommended)

```bash
cd /var/www/kvs
kvs system:status
```

KVS-CLI will automatically detect the installation.

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
source ~/.bashrc
```

## Database Configuration

KVS-CLI reads database credentials from your KVS installation's `admin/include/setup_db.php` file. No additional configuration is needed.

### Environment Variable Overrides

You can override database settings with environment variables:

```bash
export KVS_DB_HOST=127.0.0.1
export KVS_DB_USER=kvs_user
export KVS_DB_PASS=secret
export KVS_DB_NAME=kvs_production
```

This is useful for connecting to different databases without modifying KVS config files.

## Updating KVS-CLI

### PHAR Installation

```bash
# Check for updates
kvs self-update --check

# Update to latest stable
kvs self-update

# Update to latest dev build
kvs self-update --dev

# Skip confirmation
kvs self-update --yes
```

### Source Installation

```bash
cd /path/to/kvs-cli
git pull
composer install
```

## Shell Completion

Enable tab completion for better productivity:

### Bash

```bash
# System-wide
kvs completion bash | sudo tee /etc/bash_completion.d/kvs

# User-only
kvs completion bash >> ~/.bash_completion
```

### Zsh

```bash
# Add to .zshrc
echo 'eval "$(kvs completion zsh)"' >> ~/.zshrc

# Or save to completion directory
kvs completion zsh > ~/.zsh/completion/_kvs
```

### Fish

```bash
kvs completion fish > ~/.config/fish/completions/kvs.fish
```

## Troubleshooting

### "KVS installation not found"

KVS-CLI couldn't detect your installation. Solutions:

1. Navigate to the KVS directory:
   ```bash
   cd /path/to/kvs
   kvs system:status
   ```

2. Use the `--path` option:
   ```bash
   kvs --path=/path/to/kvs system:status
   ```

3. Set the environment variable:
   ```bash
   export KVS_PATH=/path/to/kvs
   ```

### "Database connection failed"

Check your database settings in `admin/include/setup_db.php`:

```php
define('DB_HOST', '127.0.0.1');
define('DB_LOGIN', 'kvs_user');
define('DB_PASS', 'password');
define('DB_DEVICE', 'kvs_database');
```

Test the connection:

```bash
kvs config get db.host
kvs system:status
```

### Permission Errors

If you get permission errors with self-update:

```bash
# Use sudo
sudo kvs self-update

# Or fix ownership
sudo chown $USER:$USER /usr/local/bin/kvs
```

### PHP Version Issues

Check your PHP version:

```bash
php --version

# If too old, specify PHP binary
/usr/bin/php8.2 /usr/local/bin/kvs --version
```

## Next Steps

- Read the [Quick Start Guide](quickstart.md) to learn basic usage
- Explore the [Command Reference](commands/) for all available commands
- Check [Configuration](configuration.md) for advanced options
