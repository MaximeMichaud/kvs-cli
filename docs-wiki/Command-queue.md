# queue

Manage KVS background tasks queue (video/album conversion, processing, etc.).

## Usage

```bash
kvs queue <action> [id] [options]
```

**Aliases:** `system:queue`

## Actions

| Action | Description |
|--------|-------------|
| `list` | List active tasks in queue (default) |
| `show` | Show details for a specific task |
| `stats` | Show queue statistics |
| `history` | Show completed/deleted tasks history |

## Options

| Option | Description |
|--------|-------------|
| `--status=<status>` | Filter by status: `pending`, `processing`, `failed` |
| `--type=<id>` | Filter by task type ID |
| `--video=<id>` | Filter by video ID |
| `--album=<id>` | Filter by album ID |
| `--server=<id>` | Filter by conversion server ID |
| `--limit=<n>` | Number of results (default: 20) |
| `--format=<format>` | Output format: `table`, `csv`, `json`, `yaml`, `count` |

## Task Status Values

| Status | Description |
|--------|-------------|
| `pending` | Tasks waiting to be processed (status_id=0) |
| `processing` | Tasks currently being processed (status_id=1) |
| `failed` | Tasks that failed with error (status_id=2) |

## Common Task Types

| ID | Type |
|----|------|
| 1 | New Video (full conversion) |
| 2 | Delete Video |
| 3 | Upload Video Format |
| 4 | Create Video Format |
| 5 | Delete Video Format File |
| 6 | Delete Video Format |
| 7 | Create Screenshot Format |
| 8 | Create Timeline Screenshots |
| 9 | Delete Screenshot Format |
| 10 | New Album |
| 11 | Delete Album |
| 12 | Create Album Format |
| 13 | Delete Album Format |
| 14 | Upload Album Images |
| 15 | Change Storage (Video) |
| 16 | Create Screenshots ZIP |
| 17 | Delete Screenshots ZIP |
| 18 | Create Images ZIP |
| 19 | Delete Images ZIP |
| 22 | Album Images Manipulation |
| 23 | Change Storage (Album) |
| 24 | Create Overview Screenshots |
| 26 | Update Resolution Type |
| 27 | Sync Storage Server |
| 28 | Delete Overview Screenshots |
| 29 | Recreate Screenshot Formats |
| 30 | Recreate Album Formats |
| 31 | Recreate Player Preview |
| 50 | Videos Import |
| 51 | Albums Import |
| 52 | Videos Mass Edit |
| 53 | Albums Mass Edit |

## Error Codes

| Code | Error |
|------|-------|
| 1 | General Failure |
| 2 | Download Failed |
| 3 | Conversion Failed |
| 4 | Upload Failed |
| 5 | File System Error |
| 6 | Format Error |
| 7 | Manual Cancellation |
| 8 | Plugin Error |
| 9 | Server Error |

## Examples

### List all active tasks

```bash
kvs queue list
```

### List pending tasks

```bash
kvs queue list --status=pending
```

### List failed tasks

```bash
kvs queue list --status=failed
```

### List tasks for a specific video

```bash
kvs queue list --video=123
```

### List new video conversion tasks

```bash
kvs queue list --type=1
```

### Show task details

```bash
kvs queue show 456
```

### Show queue statistics

```bash
kvs queue stats
```

Output includes:
- Queue status breakdown (pending/processing/failed)
- Tasks by type (top 10)
- Failed tasks by error code
- Last 24 hours metrics

### Show task history

```bash
kvs queue history --limit=50
```

### Export as JSON

```bash
kvs queue list --format=json
```
