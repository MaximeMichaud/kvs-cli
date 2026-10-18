# kvs eval

Execute PHP code with KVS context loaded.

## Synopsis

```bash
kvs eval '<code>'
```

## Description

The `eval` command executes PHP code with the KVS context pre-loaded. This provides access to database connections, configuration, and helper classes.

For local development, enable code execution in the current shell:

```bash
export KVS_ENV=dev
```

These commands require an allowed development or test environment, or an explicit `KVS_ALLOW_EVAL=true` override. The environment check also applies to `--skip-kvs`.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `code` | Yes | PHP code to execute |

## Options

| Option | Description |
|--------|-------------|
| `--skip-kvs` | Skip loading KVS context |

## Available Variables

| Variable | Description |
|----------|-------------|
| `$kvsPath` | Path to KVS installation |
| `$kvsConfig` | KVS CLI configuration object |
| `$db` | MySQLi connection, or `null` when unavailable |
| `$kvs_db` | Alias for `$db` |
| `$config` | KVS CMS configuration array |
| `$dbConfig` | Database configuration |

## Available Classes

| Class | Description |
|-------|-------------|
| `Video` | Video model |
| `User` | User model |
| `Album` | Album model |
| `Category` | Category model |
| `Tag` | Tag model |
| `DVD` | DVD model |
| `Model_` | Model (performer) model |
| `DB` | Database helper |

## Model Methods

These are lightweight read helpers using the configured table prefix. They do not load every KVS admin function or perform CMS mutation bookkeeping.

All model classes support:

```php
Video::find($id)      // Find by ID
Video::all($limit)    // Get all (with limit)
Video::count($where)  // Count records
```

## DB Helper Methods

```php
DB::query($sql)           // SELECT: array of associative rows; false on error
DB::query($sql, $params)  // Prepared statement with positional ? parameters
DB::escape($value)        // Escape a value, without adding quotes
DB::exec($sql)            // Affected-row count, or false on error
```

`DB::query()` returns a boolean or an affected-row count for statements without a result set. A helper may print an error and return `false` without making the command fail. Check results with `=== false` and throw an exception or exit with a nonzero code when failure must stop a script. `DB::exec()` can successfully return `0`.

## Examples

### Basic Queries

```bash
# Count videos
kvs eval 'echo Video::count();'

# Count active videos
kvs eval 'echo Video::count("status_id = 1");'

# Get video by ID
kvs eval 'print_r(Video::find(123));'

# Get first 5 videos
kvs eval 'print_r(Video::all(5));'
```

### Database Queries

```bash
# Direct SQL query
kvs eval '$prefix = $kvsConfig->getTablePrefix(); print_r(DB::query("SELECT COUNT(*) as total FROM {$prefix}videos"));'

# With prepared statement
kvs eval '$prefix = $kvsConfig->getTablePrefix(); print_r(DB::query("SELECT * FROM {$prefix}videos WHERE status_id = ?", [1]));'

# Get single value
kvs eval '$prefix = $kvsConfig->getTablePrefix(); echo $db->query("SELECT COUNT(*) FROM {$prefix}videos")->fetch_row()[0];'
```

### Configuration Access

```bash
# Get KVS path
kvs eval 'echo $kvsPath;'

# Get config value
kvs eval 'echo $kvsConfig->get("project_version");'

# Get database host
kvs eval 'echo $dbConfig["host"];'
```

### Complex Operations

```bash
# Count content by type
kvs eval '
echo "Videos: " . Video::count() . "\n";
echo "Albums: " . Album::count() . "\n";
echo "Users: " . User::count() . "\n";
'

# Return array (will be var_dumped)
kvs eval 'return [
    "videos" => Video::count(),
    "albums" => Album::count(),
    "users" => User::count()
];'

# Find recent videos
kvs eval '
$prefix = $kvsConfig->getTablePrefix();
$sql = "SELECT video_id, title FROM {$prefix}videos
        WHERE added_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY added_date DESC LIMIT 10";
print_r(DB::query($sql));
'
```

### Scripting Examples

```bash
# Get video count in variable
VIDEO_COUNT=$(kvs eval 'echo Video::count();')
echo "Total videos: $VIDEO_COUNT"

# Check for errors
ERROR_COUNT=$(kvs eval 'echo Video::count("status_id = 2");')
if [ "$ERROR_COUNT" -gt 0 ]; then
    echo "Warning: $ERROR_COUNT videos have errors"
fi
```

## Aliases

- `kvs eval-php`

## See Also

- [`eval-file`](eval_file.md) - Execute PHP file
- [`shell`](shell.md) - Interactive shell
- [`config`](config.md) - View configuration
