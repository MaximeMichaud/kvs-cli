# kvs system:server

**[EXPERIMENTAL]** Manage KVS storage servers and server groups.

## Synopsis

```bash
kvs system:server [<action>] [<id>] [options]
```

## Description

The `system:server` command manages KVS storage servers used for hosting video and album content. It supports listing, viewing, enabling/disabling servers, reading and applying group weight vectors, and viewing statistics.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `enable`, `disable`, `activate`, `deactivate`, `stats`, `group`, `weights`, `set-weights` (default: `list`) |
| `id` | No | Server or group ID, depending on the action |

## Options

| Option | Description |
|--------|-------------|
| `--type=TYPE` | Filter by content type (`video`, `album`) |
| `--status=STATUS` | Filter by status (`active`, `disabled`) |
| `--connection=TYPE` | Filter by connection type (`local`, `mount`, `ftp`, `s3`) |
| `--group=ID` | Filter by group ID |
| `--errors` | Show only servers with errors |
| `--limit=N` | Number of results (default: 50) |
| `--format=FORMAT` | Output format: `table`, `csv`, `json`, `yaml`, `count` |
| `--fields=FIELDS` | Comma-separated list of fields |
| `--no-truncate` | Disable truncation |
| `--weight=SERVER_ID:WEIGHT` | Complete group weight entry for `set-weights`; repeat once per server |
| `--if-revision=SHA256` | Apply weights only if the group still has this revision |
| `--ignore-revision` | Explicitly bypass revision checking during manual recovery |
| `--dry-run` | Validate `set-weights`, including temporary-file creation, without changing KVS |
| `--force` | Skip experimental feature confirmation |

## Actions

### list

List all storage servers with optional filtering.

```bash
kvs server list
kvs server list --type=video
kvs server list --status=active
kvs server list --connection=s3
kvs server list --errors
```

### show <id>

Display detailed information about a specific server.

```bash
kvs server show 1
kvs server show 3
```

### enable <id>

Enable/activate a storage server.

```bash
kvs server enable 1
```

### disable <id>

Disable/deactivate a storage server.

```bash
kvs server disable 1
```

### activate <id>

Alias for `enable`.

```bash
kvs server activate 1
```

### deactivate <id>

Alias for `disable`.

```bash
kvs server deactivate 1
```

### weights <group-id>

Read every load-balancing weight in a storage server group and calculate its canonical revision.

```bash
kvs server weights 3 --format=json --force
```

The revision includes each server's ID, group, status, streaming type, weight, and country pool. Legacy decimal weights are readable, but `set-weights` accepts only new integer values.

### set-weights <group-id>

Apply a complete group weight vector as one guarded operation.

```bash
kvs server set-weights 3 \
  --weight=12:4 \
  --weight=13:2 \
  --weight=14:1 \
  --if-revision=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
  --format=json \
  --force
```

Every server in the group must appear exactly once. Weights must be integers from `1` to `99999`, and their sum cannot exceed `99999`. Values are relative weights, not percentages.

Use `--dry-run` to validate the complete operation without updating the database or publishing `cluster.dat`. A real mutation requires `--if-revision`, unless `--ignore-revision` is supplied explicitly for manual recovery.

The command uses a MariaDB advisory lock, rechecks the revision immediately before writing, updates only `lb_weight`, publishes `cluster.dat` atomically, verifies both resources, and attempts compensating recovery on failure. It preserves the installed KVS cluster field set and ordering, including version-specific fields that are not present in older KVS releases.

### stats

Show storage statistics overview across all servers.

```bash
kvs server stats
```

### group [<id>]

List server groups or show details of a specific group.

```bash
kvs server group           # List all groups
kvs server group 1         # Show group 1 details
```

## Content Types

| Type | Description | Content Type ID |
|------|-------------|-----------------|
| `video` | Video storage servers | 1 |
| `album` | Album/image storage servers | 2 |

## Connection Types

| Type | Description |
|------|-------------|
| `local` | Local filesystem |
| `mount` | Mounted/network filesystem |
| `ftp` | FTP connection |
| `s3` | Amazon S3 or S3-compatible storage |

## Streaming Types

| ID | Type | Description |
|----|------|-------------|
| 0 | Nginx | Nginx streaming server |
| 1 | Apache | Apache streaming server |
| 4 | CDN | Content Delivery Network |
| 5 | Backup | Backup server |

## Examples

### List Servers

```bash
# All servers
kvs server list

# Only video servers
kvs server list --type=video

# Only active servers
kvs server list --status=active

# S3 servers only
kvs server list --connection=s3

# Servers with errors
kvs server list --errors
```

### Server Details

```bash
# Show server 1 configuration
kvs server show 1

# View as JSON
kvs server list --format=json
```

### Enable/Disable

```bash
# Enable server 1
kvs server enable 1
kvs server activate 1

# Disable server 2
kvs server disable 2
kvs server deactivate 2
```

### Statistics

```bash
# Show storage overview
kvs server stats
```

### Server Groups

```bash
# List all server groups
kvs server group

# Show group 1 details
kvs server group 1
```

## Aliases

- `kvs server`
- `kvs servers`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- Changes to server configuration take effect immediately
- Disabling a server stops content delivery from that server
- Use `--errors` filter to identify problematic servers
- `set-weights` never changes status, country pools, streaming settings, or error counters
- JSON output never contains streaming keys or serialized cluster data

## See Also

- [`system:status`](system_status.md) - Overall system status
- [`system:check`](system_check.md) - Health checks including storage
- [`system:conversion`](system_conversion.md) - Manage conversion servers
