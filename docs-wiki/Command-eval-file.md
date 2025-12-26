# kvs eval-file

Execute PHP file with KVS context loaded.

## Synopsis

```bash
kvs eval-file <file>
```

## Description

The `eval-file` command executes a PHP file with the KVS context pre-loaded. This is useful for running scripts, migrations, or maintenance tasks.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `file` | Yes | Path to PHP file |

## Available Variables

The following variables are available in your script:

| Variable | Description |
|----------|-------------|
| `$kvsPath` | Path to KVS installation |
| `$kvsConfig` | Configuration object |
| `$db` | PDO database connection |
| `$config` | Configuration array |

## Available Classes

Same as [[Command-eval|eval]] command:
- `Video`, `User`, `Album`, `Category`, `Tag`, `DVD`, `Model_`
- `DB::query()`, `DB::escape()`, `DB::exec()`

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

- [[Command-eval|eval]] - Execute inline PHP code
- [[Command-shell|shell]] - Interactive shell
