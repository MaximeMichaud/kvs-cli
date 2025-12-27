# cli:info

Display information about the KVS CLI environment.

## Usage

```bash
kvs cli:info [options]
```

**Aliases:** `info`

## Options

| Option | Description | Default |
|--------|-------------|---------|
| `--format=<format>` | Output format: `list` or `json` | list |

## Output

The command displays:

| Field | Description |
|-------|-------------|
| OS | Operating system name, version, and architecture |
| Shell | Current shell (bash, zsh, etc.) |
| PHP binary | Path to PHP executable |
| PHP version | PHP version number |
| php.ini used | Path to loaded php.ini file |
| MySQL binary | Path to mysql/mariadb client |
| MySQL version | MySQL/MariaDB client version |
| KVS CLI root | Root directory of KVS CLI |
| KVS CLI PHAR | Path to PHAR file (if running as PHAR) |
| KVS CLI version | Current KVS CLI version |
| KVS path | Detected KVS installation path |
| KVS version | Detected KVS version |

## Examples

### Display environment info

```bash
kvs cli:info
```

Output:
```
OS:              Linux 6.1.0-18-amd64 #1 SMP PREEMPT_DYNAMIC Debian 6.1.76-1 (2024-02-01) x86_64
Shell:           /bin/bash
PHP binary:      /usr/bin/php
PHP version:     8.3.6
php.ini used:    /etc/php/8.3/cli/php.ini
MySQL binary:    /usr/bin/mariadb
MySQL version:   10.11.6 (MariaDB)
KVS CLI root:    /usr/local/bin/kvs.phar
KVS CLI PHAR:    /usr/local/bin/kvs
KVS CLI version: 1.0.0
KVS path:        /var/www/html/kvs
KVS version:     6.3.2
```

### Output as JSON

```bash
kvs cli:info --format=json
```

Output:
```json
{
    "os": "Linux 6.1.0-18-amd64 #1 SMP PREEMPT_DYNAMIC ...",
    "shell": "/bin/bash",
    "php_binary": "/usr/bin/php",
    "php_version": "8.3.6",
    "php_ini": "/etc/php/8.3/cli/php.ini",
    "mysql_binary": "/usr/bin/mariadb",
    "mysql_version": "10.11.6 (MariaDB)",
    "kvs_cli_root": "/usr/local/bin/kvs.phar",
    "kvs_cli_phar_path": "/usr/local/bin/kvs",
    "kvs_cli_version": "1.0.0",
    "kvs_path": "/var/www/html/kvs",
    "kvs_version": "6.3.2"
}
```

## Use Cases

### Diagnostic Information

Useful for troubleshooting and support requests:

```bash
kvs info
```

### Scripting

Get specific values for scripts:

```bash
kvs info --format=json | jq -r '.php_version'
```

### Verify Installation

Check that KVS CLI is properly installed and can detect your KVS installation:

```bash
kvs info
```

If `KVS path` shows `<not detected>`, you need to either:
- Run the command from within your KVS directory
- Use the `--path` option
- Set the `KVS_PATH` environment variable
