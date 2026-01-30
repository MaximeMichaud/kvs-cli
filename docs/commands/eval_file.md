# kvs eval-file

Execute PHP file with KVS context loaded.

## Synopsis

```bash
kvs eval-file <file> [options]
```

## Description

The `eval-file` command executes a PHP file with the KVS context pre-loaded. This is useful for running scripts, migrations, or maintenance tasks.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `file` | Yes | Path to PHP file |

## Options

| Option | Description |
|--------|-------------|
| `--skip-kvs` | Skip loading KVS context (faster, for standalone scripts) |
| `--args=VALUE` | Arguments to pass to the script (can be used multiple times) |

## Available Variables

The following variables are available in your script:

| Variable | Description |
|----------|-------------|
| `$kvsPath` | Path to KVS installation |
| `$kvsConfig` | Configuration object |
| `$db` | PDO database connection |
| `$config` | Configuration array |

## Available Classes

Same as [`eval`](eval.md) command:
- `Video`, `User`, `Album`, `Category`, `Tag`, `DVD`, `Model_`
- `DB::query()`, `DB::escape()`, `DB::exec()`

## Script Arguments

When using `--args`, the arguments are available in your script as `$argv`:

```php
<?php
// script.php
echo "Arguments: " . implode(', ', $argv) . "\n";
echo "First arg: " . ($argv[1] ?? 'none') . "\n";
```

Run:
```bash
kvs eval-file script.php --args="arg1" --args="arg2"
```

Output:
```
Arguments: script.php, arg1, arg2
First arg: arg1
```

## Examples

### Basic Script

Create `script.php`:

```php
<?php
// script.php

echo "KVS Path: " . $kvsPath . "\n";
echo "Videos: " . Video::count() . "\n";
echo "Users: " . User::count() . "\n";
```

Run:

```bash
kvs eval-file script.php
```

### Script with Arguments

Create `migrate.php`:

```php
<?php
// migrate.php

$action = $argv[1] ?? 'help';
$dryRun = in_array('--dry-run', $argv);

echo "Migration: $action\n";
echo "Dry run: " . ($dryRun ? 'yes' : 'no') . "\n";

if ($action === 'videos') {
    echo "Migrating videos...\n";
    // Your migration logic
}
```

Run:

```bash
# Run migration
kvs eval-file migrate.php --args="videos"

# Dry run
kvs eval-file migrate.php --args="videos" --args="--dry-run"
```

### Standalone Script (No KVS Context)

Create `standalone.php`:

```php
<?php
// standalone.php - doesn't need KVS
echo "This script doesn't use KVS.\n";
// Your code here
```

Run:

```bash
# Faster execution - skips KVS loading
kvs eval-file standalone.php --skip-kvs
```

### Migration Script

Create `migration.php`:

```php
<?php
// migration.php

echo "Running migration...\n";

// Add new column
$sql = "ALTER TABLE ktvs_videos ADD COLUMN custom_field VARCHAR(255)";
try {
    $db->exec($sql);
    echo "✓ Column added\n";
} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
```

Run:

```bash
kvs eval-file migration.php
```

### Data Processing Script

Create `process-videos.php`:

```php
<?php
// process-videos.php

$videos = Video::all(100);

foreach ($videos as $video) {
    echo "Processing video #{$video['video_id']}: {$video['title']}\n";

    // Your processing logic here
}

echo "Processed " . count($videos) . " videos\n";
```

### Report Generation

Create `report.php`:

```php
<?php
// report.php

$stats = [
    'total_videos' => Video::count(),
    'active_videos' => Video::count('status_id = 1'),
    'error_videos' => Video::count('status_id = 2'),
    'total_users' => User::count(),
    'premium_users' => User::count('status_id = 3'),
];

echo "=== KVS Report ===\n\n";
foreach ($stats as $key => $value) {
    $label = str_replace('_', ' ', ucfirst($key));
    echo "$label: $value\n";
}
```

Run:

```bash
kvs eval-file report.php
```

### Cleanup Script

Create `cleanup.php`:

```php
<?php
// cleanup.php

// Find orphaned records
$sql = "SELECT v.video_id, v.title
        FROM ktvs_videos v
        LEFT JOIN ktvs_users u ON v.user_id = u.user_id
        WHERE u.user_id IS NULL";

$orphans = DB::query($sql);

if (empty($orphans)) {
    echo "No orphaned videos found.\n";
    exit;
}

echo "Found " . count($orphans) . " orphaned videos:\n";
foreach ($orphans as $video) {
    echo "  - #{$video['video_id']}: {$video['title']}\n";
}
```

## Aliases

- `kvs eval:file`

## See Also

- [`eval`](eval.md) - Execute inline PHP code
- [`shell`](shell.md) - Interactive shell
