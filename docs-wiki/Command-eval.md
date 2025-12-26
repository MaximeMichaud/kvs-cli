# kvs eval

Execute PHP code with KVS context loaded.

## Synopsis

```bash
kvs eval '<code>'
```

## Description

The `eval` command executes PHP code with the KVS context pre-loaded. This provides access to database connections, configuration, and helper classes.

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
| `$kvsConfig` | Configuration object |
| `$db` | PDO database connection |
| `$config` | Configuration array |
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

All model classes support:

```php
Video::find($id)      // Find by ID
Video::all($limit)    // Get all (with limit)
Video::count($where)  // Count records
```

## DB Helper Methods

```php
DB::query($sql)           // Execute query, return results
DB::query($sql, $params)  // With prepared statement
DB::escape($value)        // Escape value
DB::exec($sql)            // Execute without results
```

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
kvs eval 'print_r(DB::query("SELECT COUNT(*) as total FROM ktvs_videos"));'

# With prepared statement
kvs eval 'print_r(DB::query("SELECT * FROM ktvs_videos WHERE status_id = ?", [1]));'

# Get single value
kvs eval 'echo $db->query("SELECT COUNT(*) FROM ktvs_videos")->fetchColumn();'
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
$sql = "SELECT video_id, title FROM ktvs_videos
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

- [[Command-eval-file|eval-file]] - Execute PHP file
- [[Command-shell|shell]] - Interactive shell
- [[Command-config|config]] - View configuration
