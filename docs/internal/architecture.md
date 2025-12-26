# Architecture Overview

This document describes the internal architecture of KVS-CLI.

## Directory Structure

```
kvs-cli/
├── bin/
│   └── kvs                     # CLI entry point
├── src/
│   ├── Application.php         # Main application class
│   ├── Constants.php           # Centralized constants
│   ├── utils.php              # Utility functions (WP-CLI inspired)
│   ├── Bootstrap/              # Bootstrap system
│   │   ├── BootstrapStep.php   # Interface
│   │   ├── BootstrapState.php  # State container
│   │   ├── LoadConfiguration.php
│   │   ├── ValidateKvsInstallation.php
│   │   └── RegisterCommands.php
│   ├── Command/                # All commands
│   │   ├── BaseCommand.php     # Abstract base class
│   │   ├── Content/            # Content management
│   │   ├── System/             # System administration
│   │   └── Traits/             # Reusable traits
│   ├── Config/
│   │   └── Configuration.php   # Configuration management
│   └── Output/
│       ├── Formatter.php       # Output formatting
│       └── StatusFormatter.php # Status labels
├── tests/                      # Test suite
├── build.php                  # PHAR builder
├── composer.json
├── phpunit.xml
└── VERSION
```

## Core Components

### Application (`src/Application.php`)

The main application class extends Symfony Console Application:

```php
class Application extends \Symfony\Component\Console\Application
{
    public const NAME = 'KVS-CLI';
    public const VERSION = '1.0.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
        $this->addGlobalOption();
        $this->registerDefaultCommands();
    }

    public function registerKvsCommands(Configuration $config): void
    {
        // Registers all KVS-specific commands
    }
}
```

**Responsibilities:**
- Bootstrap process coordination
- Command registration
- Global option handling (`--path`)
- Error handling for KVS detection

### Bootstrap System (`src/Bootstrap/`)

A modular, testable bootstrap system using the Chain of Responsibility pattern:

```php
interface BootstrapStep
{
    public function process(BootstrapState $state): BootstrapState;
}
```

**Steps:**

1. **LoadConfiguration** - Loads KVS configuration from path
2. **ValidateKvsInstallation** - Verifies KVS files exist
3. **RegisterCommands** - Adds all commands to application

**State Container:**

```php
class BootstrapState
{
    public function setValue(string $key, mixed $value): void;
    public function getValue(string $key): mixed;
    public function addError(string $error): void;
    public function hasErrors(): bool;
}
```

### Configuration (`src/Config/Configuration.php`)

Manages KVS configuration detection and access:

```php
class Configuration
{
    public function getKvsPath(): string;
    public function getAdminPath(): string;
    public function getContentPath(): string;
    public function getDatabaseConfig(): array;
    public function get(string $key, mixed $default = null): mixed;
    public function isKvsInstalled(): bool;
}
```

**Path Detection Priority:**
1. `--path` CLI option
2. `KVS_PATH` environment variable
3. Current working directory (walks up tree)

### BaseCommand (`src/Command/BaseCommand.php`)

Abstract base class for all KVS commands:

```php
abstract class BaseCommand extends Command
{
    protected Configuration $config;
    protected SymfonyStyle $io;

    protected function getDatabaseConnection(): ?PDO;
    protected function renderTable(array $headers, array $rows): void;
    protected function table(string $name): string;
    protected function executePhpScript(string $path): ?string;
}
```

**Provides:**
- Database connection management
- Styled output (`$io`)
- Configuration access (`$config`)
- Table name prefixing

### Output Formatters (`src/Output/`)

#### StatusFormatter

Centralized status formatting with color support:

```php
class StatusFormatter
{
    // Status constants
    public const VIDEO_ACTIVE = 1;
    public const USER_PREMIUM = 3;
    // ...

    public static function video(int $status, bool $color = true): string;
    public static function user(int $status, bool $color = true): string;
    public static function album(int $status, bool $color = true): string;
    // ...
}
```

#### Formatter

Multi-format output handler:

```php
class Formatter
{
    public function display(array $items, OutputInterface $output): void;
    public function displayTable(array $items, OutputInterface $output): void;
    public function displayJson(array $items, OutputInterface $output): void;
    public function displayCsv(array $items, OutputInterface $output): void;
}
```

**Supported formats:** table, json, csv, yaml, count, ids

### Constants (`src/Constants.php`)

Centralized constants to eliminate magic numbers:

```php
class Constants
{
    // Output
    public const DEFAULT_TRUNCATE_LENGTH = 50;
    public const TABLE_STYLE = 'box';

    // Pagination
    public const DEFAULT_LIMIT = 50;
    public const DEFAULT_CONTENT_LIMIT = 20;

    // Database
    public const DEFAULT_TABLE_PREFIX = 'ktvs_';
    public const DB_CHARSET = 'utf8mb4';

    // Status codes
    public const OBJECT_TYPE_VIDEO = 1;
    public const OBJECT_TYPE_ALBUM = 2;

    // GitHub
    public const GITHUB_REPO = 'MaximeMichaud/kvs-cli';
}
```

### Utility Functions (`src/utils.php`)

WP-CLI inspired helper functions:

```php
namespace KVS\CLI\Utils;

function truncate(string $string, int $length): string;
function format_bytes(int $bytes): string;
function format_duration(int $seconds): string;
function format_date(string $date): string;
function build_where_clause(array $filters, array &$params): string;
function report_batch_operation_results(...): array;
```

## Command Structure

### Content Commands

Located in `src/Command/Content/`:

```
VideoCommand     - video list, show
AlbumCommand     - album list, show
UserCommand      - user list, show
UserPurgeCommand - user:purge
CommentCommand   - comment list, show, stats
CategoryCommand  - category list, show, enable, disable
TagCommand       - tag list, enable, disable
ModelCommand     - model list
DvdCommand       - dvd list
```

### System Commands

Located in `src/Command/System/`:

```
StatusCommand      - system:status
CheckCommand       - system:check
CacheCommand       - system:cache
CronCommand        - system:cron
BackupCommand      - system:backup
MaintenanceCommand - maintenance
```

### Reusable Traits

Located in `src/Command/Traits/`:

```php
trait ToggleStatusTrait
{
    protected function toggleEntityStatus(
        string $entityName,
        string $tableName,
        string $idColumn,
        string $nameColumn,
        ?string $id,
        int $status,
        string $commandName
    ): int;
}
```

Used by `CategoryCommand` and `TagCommand` for enable/disable operations.

## Data Flow

```
User Input
    ↓
bin/kvs (entry point)
    ↓
Application::run()
    ↓
Bootstrap Steps:
  1. LoadConfiguration
  2. ValidateKvsInstallation
  3. RegisterCommands
    ↓
Command::execute()
    ↓
BaseCommand helpers:
  - getDatabaseConnection()
  - table()
  - renderTable()
    ↓
Formatter::display()
    ↓
Output (table/json/csv/etc.)
```

## Database Access

Commands access the database through PDO:

```php
// In command execute()
$db = $this->getDatabaseConnection();

$stmt = $db->prepare("SELECT * FROM " . $this->table('videos') . " WHERE status_id = ?");
$stmt->execute([1]);
$videos = $stmt->fetchAll();
```

The `table()` method adds the configured prefix (default: `ktvs_`).

## Error Handling

### Bootstrap Errors

If KVS is not found, the application:
1. Shows helpful error message with solutions
2. Lists standalone commands that work without KVS
3. Exits with failure code

### Command Errors

Commands use SymfonyStyle for consistent error output:

```php
$this->io->error('Something went wrong');
$this->io->warning('This might be a problem');
$this->io->success('Operation completed');

return Command::FAILURE; // or Command::SUCCESS
```

## Testing Architecture

See [Testing](testing.md) for details.

## Build Process

See [Building](building.md) for PHAR creation details.
