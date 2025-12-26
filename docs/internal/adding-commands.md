# Adding Commands

This guide explains how to add new commands to KVS-CLI.

## Quick Start

1. Create command class in `src/Command/`
2. Extend `BaseCommand`
3. Register in `Application::registerKvsCommands()`
4. Add tests in `tests/`

## Command Structure

### Basic Command

```php
<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mycommand',
    description: 'Description of my command',
    aliases: ['mc', 'my:command']
)]
class MyCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->title('My Command');

        // Your logic here

        return self::SUCCESS;
    }
}
```

### Command with Arguments and Options

```php
<?php

namespace KVS\CLI\Command\Content;

use KVS\CLI\Command\BaseCommand;
use KVS\CLI\Constants;
use KVS\CLI\Output\Formatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'widget',
    description: 'Manage widgets',
    aliases: ['widgets']
)]
class WidgetCommand extends BaseCommand
{
    private const DEFAULT_FIELDS = ['widget_id', 'name', 'status'];

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action: list, show')
            ->addArgument('id', InputArgument::OPTIONAL, 'Widget ID for show action')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit results', Constants::DEFAULT_CONTENT_LIMIT)
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filter by status')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format', 'table')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Fields to display')
            ->addOption('no-truncate', null, InputOption::VALUE_NONE, 'Don\'t truncate values');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        return match ($action) {
            'list' => $this->listWidgets($input, $output),
            'show' => $this->showWidget($input, $output),
            default => $this->showUsage(),
        };
    }

    private function listWidgets(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->getDatabaseConnection();
        if (!$db) {
            $this->io->error('Database connection failed');
            return self::FAILURE;
        }

        // Build query
        $sql = "SELECT * FROM " . $this->table('widgets');
        $params = [];

        if ($status = $input->getOption('status')) {
            $sql .= " WHERE status_id = ?";
            $params[] = (int)$status;
        }

        $sql .= " LIMIT " . (int)$input->getOption('limit');

        // Execute
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $widgets = $stmt->fetchAll();

        if (empty($widgets)) {
            $this->io->info('No widgets found');
            return self::SUCCESS;
        }

        // Format output
        $formatter = new Formatter(
            $input->getOptions(),
            self::DEFAULT_FIELDS
        );
        $formatter->display($widgets, $output);

        return self::SUCCESS;
    }

    private function showWidget(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        if (!$id) {
            $this->io->error('Widget ID is required');
            return self::FAILURE;
        }

        $db = $this->getDatabaseConnection();
        $stmt = $db->prepare("SELECT * FROM " . $this->table('widgets') . " WHERE widget_id = ?");
        $stmt->execute([(int)$id]);
        $widget = $stmt->fetch();

        if (!$widget) {
            $this->io->error("Widget #$id not found");
            return self::FAILURE;
        }

        $this->io->title("Widget #$id");
        $this->renderTable(['Field', 'Value'], [
            ['ID', $widget['widget_id']],
            ['Name', $widget['name']],
            ['Status', $widget['status_id']],
        ]);

        return self::SUCCESS;
    }

    private function showUsage(): int
    {
        $this->io->error('Invalid action. Use: list, show');
        return self::FAILURE;
    }
}
```

## Registration

Add your command to `Application::registerKvsCommands()`:

```php
public function registerKvsCommands(Configuration $config): void
{
    // ... existing commands ...

    // Add your new command
    $this->add(new WidgetCommand($config));
}
```

## Using BaseCommand Features

### Database Connection

```php
$db = $this->getDatabaseConnection();
if (!$db) {
    $this->io->error('Database connection failed');
    return self::FAILURE;
}

// Use PDO
$stmt = $db->prepare("SELECT * FROM " . $this->table('videos') . " WHERE status_id = ?");
$stmt->execute([1]);
$results = $stmt->fetchAll();
```

### Table Prefixing

```php
// Returns "ktvs_videos" (with configured prefix)
$tableName = $this->table('videos');
```

### Styled Output

```php
// Titles and sections
$this->io->title('Main Title');
$this->io->section('Sub Section');

// Messages
$this->io->success('Operation completed');
$this->io->error('Something went wrong');
$this->io->warning('Be careful');
$this->io->info('FYI...');
$this->io->note('Remember that...');

// Tables
$this->renderTable(['Column 1', 'Column 2'], [
    ['Value 1', 'Value 2'],
    ['Value 3', 'Value 4'],
]);

// Lists
$this->io->listing(['Item 1', 'Item 2', 'Item 3']);

// Progress
$this->io->progressStart(100);
$this->io->progressAdvance();
$this->io->progressFinish();

// Questions
$answer = $this->io->ask('What is your name?');
$confirmed = $this->io->confirm('Continue?', true);
```

### Execute KVS Scripts

```php
$output = $this->executePhpScript('/admin/include/cron.php');
if ($output === null) {
    $this->io->error('Script execution failed');
}
```

## Using Formatters

### StatusFormatter

```php
use KVS\CLI\Output\StatusFormatter;

// Get formatted status with color
$status = StatusFormatter::video($widget['status_id']);
// Returns: "<fg=green>Active</>" for status 1

// Without color (for JSON/CSV)
$status = StatusFormatter::video($widget['status_id'], false);
// Returns: "Active"
```

### Formatter

```php
use KVS\CLI\Output\Formatter;

$formatter = new Formatter(
    $input->getOptions(),    // Options array
    ['id', 'name', 'status'] // Default fields
);

$formatter->display($items, $output);
```

## Using Traits

### ToggleStatusTrait

For enable/disable functionality:

```php
use KVS\CLI\Command\Traits\ToggleStatusTrait;

class WidgetCommand extends BaseCommand
{
    use ToggleStatusTrait;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        if ($action === 'enable') {
            return $this->toggleEntityStatus(
                entityName: 'Widget',
                tableName: 'widgets',
                idColumn: 'widget_id',
                nameColumn: 'name',
                id: $input->getArgument('id'),
                status: 1,
                commandName: 'widget'
            );
        }

        // ...
    }
}
```

## Using Constants

```php
use KVS\CLI\Constants;

// Limits
$limit = $input->getOption('limit') ?? Constants::DEFAULT_CONTENT_LIMIT;

// Status values
$isActive = $status === Constants::VIDEO_ACTIVE;

// Table prefix
$prefix = Constants::DEFAULT_TABLE_PREFIX;
```

## Using Utilities

```php
use function KVS\CLI\Utils\truncate;
use function KVS\CLI\Utils\format_bytes;
use function KVS\CLI\Utils\format_date;
use function KVS\CLI\Utils\build_where_clause;

// Truncate text
$shortTitle = truncate($title, 50);

// Format file size
$size = format_bytes(1024 * 1024 * 100); // "100 MB"

// Relative date
$ago = format_date('2024-01-15 10:30:00'); // "5 minutes ago"

// Build WHERE clause safely
$params = [];
$where = build_where_clause([
    'status_id' => 1,
    'user_id' => [1, 2, 3],
], $params);
// Returns: "`status_id` = ? AND `user_id` IN (?,?,?)"
// $params = [1, 1, 2, 3]
```

## Testing Commands

Create a test in `tests/`:

```php
<?php

namespace Tests;

use KVS\CLI\Command\Content\WidgetCommand;
use KVS\CLI\Config\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class WidgetCommandTest extends TestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kvsPath = getenv('KVS_TEST_PATH') ?: __DIR__ . '/../kvs';

        if (!is_dir($kvsPath)) {
            $this->markTestSkipped('KVS installation not found');
        }

        $config = new Configuration(['path' => $kvsPath]);
        $command = new WidgetCommand($config);

        $app = new Application();
        $app->add($command);

        $this->tester = new CommandTester($command);
    }

    public function testListWidgets(): void
    {
        $this->tester->execute(['action' => 'list']);

        $this->assertEquals(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();

        $this->assertStringContainsString('widget_id', $output);
    }

    public function testShowWidget(): void
    {
        $this->tester->execute(['action' => 'show', 'id' => '1']);

        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    public function testListJsonFormat(): void
    {
        $this->tester->execute(['action' => 'list', '--format' => 'json']);

        $output = $this->tester->getDisplay();
        $json = json_decode($output, true);

        $this->assertIsArray($json);
    }
}
```

## Checklist

Before submitting a new command:

- [ ] Command extends `BaseCommand`
- [ ] Uses `#[AsCommand]` attribute
- [ ] Has meaningful description and aliases
- [ ] Handles database connection failures
- [ ] Uses `Formatter` for output
- [ ] Uses `StatusFormatter` for status fields
- [ ] Uses constants instead of magic numbers
- [ ] Registered in `Application::registerKvsCommands()`
- [ ] Has tests
- [ ] Tests pass
- [ ] PHPStan passes
- [ ] Code style passes
