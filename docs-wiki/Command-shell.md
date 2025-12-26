# kvs shell

Interactive PHP shell with KVS context loaded.

## Synopsis

```bash
kvs shell [options]
```

## Description

The `shell` command starts an interactive PHP REPL (Read-Eval-Print Loop) with the KVS context pre-loaded. This provides a powerful environment for exploring data, debugging, and running ad-hoc queries.

Powered by [PsySH](https://psysh.org/).

## Options

| Option | Description |
|--------|-------------|
| `-i, --includes=<file>` | Additional files to include (can be used multiple times) |
| `-b, --bootstrap=<file>` | Bootstrap file to load |

## Available Variables

| Variable | Description |
|----------|-------------|
| `$kvsConfig` | Configuration object |
| `$kvsPath` | Path to KVS installation |
| `$db` | PDO database connection |

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

## Built-in Commands

| Command | Description |
|---------|-------------|
| `help` | Show PsySH help |
| `exit` or `quit` | Exit the shell |
| `clear` | Clear the screen |
| `doc <class>` | Show documentation |
| `show <object>` | Show source code |

## Examples

### Starting the Shell

```bash
kvs shell
```

Output:

```
========================================
         KVS Interactive Shell
========================================
PHP 8.2.15 on Linux

Variables:
  $kvsConfig - KVS configuration
  $db        - Database connection

Classes:
  Video, User, Album, Category, Tag, DVD
  DB::query() - Run SQL queries

Type 'help' for PsySH help
Type 'exit' or Ctrl+D to quit
========================================

kvs>>>
```

### Basic Operations

```php
kvs>>> Video::count()
=> 15432

kvs>>> User::count('status_id = 3')
=> 234

kvs>>> Video::find(123)
=> [
     "video_id" => 123,
     "title" => "Example Video",
     "status_id" => 1,
     ...
   ]

kvs>>> Video::all(5)
=> [
     [...],
     [...],
     [...]
   ]
```

### Database Queries

```php
kvs>>> DB::query("SELECT COUNT(*) as total FROM ktvs_videos")
=> [
     ["total" => "15432"]
   ]

kvs>>> $db->query("SHOW TABLES")->fetchAll()
=> [
     ["Tables_in_kvs" => "ktvs_videos"],
     ["Tables_in_kvs" => "ktvs_users"],
     ...
   ]
```

### Exploring Data

```php
kvs>>> $video = Video::find(123)
=> [...]

kvs>>> $video['title']
=> "Example Video"

kvs>>> $users = User::all(10)
=> [...]

kvs>>> array_column($users, 'username')
=> ["john", "jane", "bob", ...]
```

### Using Configuration

```php
kvs>>> $kvsConfig->get('project_version')
=> "6.3.2"

kvs>>> $kvsConfig->getKvsPath()
=> "/var/www/kvs"

kvs>>> $kvsPath
=> "/var/www/kvs"
```

### Helper Functions

```php
kvs>>> dump(Video::find(1))
// Pretty prints the array

kvs>>> dd(User::find(5))
// Dumps and dies
```

### Including Additional Files

```bash
kvs shell --includes=helpers.php --includes=models.php
```

### Using Bootstrap File

Create `bootstrap.php`:

```php
<?php
// Custom helpers
function active_videos() {
    return Video::count('status_id = 1');
}

function format_video($video) {
    return "#{$video['video_id']}: {$video['title']}";
}
```

Run:

```bash
kvs shell --bootstrap=bootstrap.php
```

```php
kvs>>> active_videos()
=> 12345

kvs>>> format_video(Video::find(1))
=> "#1: First Video"
```

## Tips

### Command History

Use up/down arrows to navigate command history.

### Multi-line Input

For multi-line code, the shell will wait for complete statements:

```php
kvs>>> $videos = Video::all(5);
kvs>>> foreach ($videos as $v) {
...>     echo $v['title'] . "\n";
...> }
```

### Tab Completion

Press Tab for auto-completion of:
- Variable names
- Class names
- Method names

## Aliases

- `kvs console`
- `kvs repl`

## See Also

- [[Command-eval|eval]] - Execute inline PHP code
- [[Command-eval-file|eval-file]] - Execute PHP file
- [[Command-config|config]] - View configuration
