# kvs dev:debug

Debug KVS system information.

## Synopsis

```bash
kvs dev:debug [options]
```

## Description

The `dev:debug` command provides debugging information about your KVS installation and system configuration.

## Options

| Option | Description |
|--------|-------------|
| `--check` | Run system checks |
| `--info` | Show debug information |
| `--test-db` | Test database connection |

## Examples

### Show Debug Information

```bash
kvs debug --info
```

Output:

```
Debug Information
=================

Environment
-----------
 PHP Version     8.2.15
 PHP Binary      /usr/bin/php
 Memory Limit    256M
 Timezone        UTC
 OS              Linux 6.18.1-2-cachyos

KVS
---
 Path            /var/www/kvs
 Version         6.3.2
 Config Loaded   Yes

Database
--------
 Host            127.0.0.1
 Database        kvs_production
 Connection      OK
 Latency         2.3ms
```

### Test Database Connection

```bash
kvs debug --test-db
```

Output:

```
Testing database connection...

Connection: OK
Server: MySQL 8.0.35
Latency: 2.3ms

Test query: OK
```

### Run System Checks

```bash
kvs debug --check
```

Similar to `kvs check` but with more verbose output.

## Aliases

- `kvs debug`

## See Also

- [[Command-system-check|system:check]] - Health checks
- [[Command-system-status|system:status]] - System status
- [[Command-dev-log|dev:log]] - View logs
