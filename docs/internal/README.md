# Internal Architecture

This section documents the internal architecture of KVS-CLI for developers who want to contribute or understand how the tool works.

## Documentation Index

| Document | Description |
|----------|-------------|
| [Architecture Overview](architecture.md) | High-level system architecture |
| [Adding Commands](adding-commands.md) | How to add new commands |
| [Testing](testing.md) | Test suite documentation |
| [Building](building.md) | PHAR building process |

## Quick Architecture Overview

```
kvs-cli/
├── bin/kvs              # Entry point
├── src/
│   ├── Application.php  # Main application class
│   ├── Constants.php    # Centralized constants
│   ├── utils.php        # Utility functions
│   ├── Bootstrap/       # Bootstrap steps
│   ├── Command/         # All commands
│   ├── Config/          # Configuration management
│   └── Output/          # Formatters
├── tests/               # Test suite
├── build.php           # PHAR builder
└── composer.json       # Dependencies
```

## Key Concepts

### Symfony Console

KVS-CLI is built on [Symfony Console](https://symfony.com/doc/current/components/console.html). All commands extend either `Symfony\Component\Console\Command\Command` or `KVS\CLI\Command\BaseCommand`.

### Bootstrap System

The application uses a modular bootstrap system:

1. **LoadConfiguration** - Loads KVS path and config
2. **ValidateKvsInstallation** - Validates KVS is present
3. **RegisterCommands** - Registers all KVS commands

### Command Categories

Commands are organized in `src/Command/`:

```
Command/
├── BaseCommand.php          # Base class
├── Content/                 # Content commands
│   ├── VideoCommand.php
│   ├── UserCommand.php
│   └── ...
├── System/                  # System commands
│   ├── StatusCommand.php
│   ├── CheckCommand.php
│   └── ...
└── Traits/                  # Reusable traits
    └── ToggleStatusTrait.php
```

### Output Formatting

Two main formatters handle output:

- **StatusFormatter** - Color-coded status labels
- **Formatter** - Multi-format output (table, JSON, CSV, etc.)

## Development Setup

```bash
# Clone repository
git clone https://github.com/MaximeMichaud/kvs-cli.git
cd kvs-cli

# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Run from source
./bin/kvs --version
```

## Code Quality

```bash
# Run all checks
composer check

# Individual checks
composer phpcs      # Code style (PSR-12)
composer phpstan    # Static analysis (level 10)
composer test       # Tests
```
