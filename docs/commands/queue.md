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
| `history` | Show completed/cancelled/failed task history |
| `retry <id>` | Requeue one failed task from the active queue |
| `wait <id>` | Wait for a task to reach a terminal state |

## Options

| Option | Description |
|--------|-------------|
| `--status=<status>` | Filter by task status. Active queue values differ from history values. |
| `--type=<id>` | Filter by task type ID |
| `--error-code=<id>` | Filter by native KVS task error code |
| `--video=<id>` | Filter by video ID |
| `--album=<id>` | Filter by album ID |
| `--server=<id>` | Filter by conversion server ID |
| `--limit=<n>` | Number of results (default: 20) |
| `--format=<format>` | Read actions: `table`, `csv`, `json`, `yaml`, `count`. Retry and wait: `table`, `json`. |
| `--dry-run` | Retry only: preview the restart without changing the database or logs |
| `--yes`, `-y` | Retry only: skip confirmation; required for unattended or JSON retries |
| `--timeout=<seconds>` | Wait only: non-negative integer, default `300`; `0` checks once |
| `--interval=<seconds>` | Wait only: polling interval from `0.1` to `60`, default `1` |

List filters, `--fields`, and `--no-truncate` are not accepted by retry or wait.

## Retry a failed task

```bash
kvs queue retry 123 --dry-run
kvs queue retry 123 --yes --format=json
kvs queue wait 123 --timeout=1800 --format=json
```

Retry accepts exactly one task ID. It refuses pending, processing, missing, and
archived tasks, including archived failures. Fix the underlying conversion or
storage problem before retrying.

The restart follows the native KVS admin behavior: failed associated content
returns to processing, the task returns to pending, the previous server moves to
`last_server_id`, `times_restarted` increases, and the task message is cleared.
The payload, priority, timestamps, and previous error code are preserved, as in
KVS. Task and content logs and the failed-task notification are updated too.

Retry requires writable KVS task/content logs and InnoDB tables for the affected
rows. Changes are committed together; a log or database error rolls back the
restart. Run the CLI as a user that can write the installation's existing logs.
Concurrent CLI retries are serialized with a database advisory lock so the
shared failed-task count stays consistent. A busy retry waits up to ten seconds
before returning an error without changing the task.
The JSON response describes the committed restart, not conversion completion:
workers may immediately claim the task. Use `wait` for the final result.

## Wait for completion

```bash
kvs queue wait 123 --timeout=600 --interval=0.5
kvs queue wait 123 --timeout=0 --format=json
```

Wait checks both the active queue and task history. It tolerates a short gap of
up to two seconds while KVS moves a task between them. It does not run cron,
restart a task, or change task state. A timeout or Ctrl+C stops the CLI only;
the KVS task continues independently. Polling never sleeps beyond the timeout.

| Exit code | Result |
|-----------|--------|
| `0` | Completed successfully |
| `1` | Task failed, invalid input/state, or another command error |
| `2` | Task not found in the active queue or history |
| `3` | Task was cancelled |
| `124` | Timeout while the task remained pending or processing |
| `130` | Interrupted by SIGINT |
| `143` | Interrupted by SIGTERM |

Retry and wait emit one JSON object when `--format=json` is used. Wait includes
`task_id`, `outcome`, `status_id`, `is_history`, `error_code`, `message`,
`elapsed_seconds`, and `exit_code`. Native error details are retained on failure.
Retry returns exit code `0` after a restart, preview, or declined confirmation,
and `1` on failure; its `outcome` distinguishes these results.

## Active Queue Status Values

| Status | Aliases | Description |
|--------|---------|-------------|
| `pending` | `scheduled`, `0` | Tasks waiting to be processed (status_id=0) |
| `processing` | `in-process`, `in_process`, `1` | Tasks currently being processed (status_id=1) |
| `failed` | `error`, `2` | Tasks that failed with error (status_id=2) |

## History Status Values

| Status | Aliases | Description |
|--------|---------|-------------|
| `failed` | `error`, `2` | Tasks that failed with error (status_id=2) |
| `completed` | `3` | Tasks completed successfully (status_id=3) |
| `cancelled` | `canceled`, `deleted`, `4` | Tasks cancelled or deleted before completion (status_id=4) |

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
| 1 | Database consistency error |
| 2 | Conversion server connection error |
| 3 | Unexpected error |
| 4 | Storage server connection error |
| 5 | Filesystem error |
| 6 | Unexpected error |
| 7 | Conversion error |
| 8 | Screenshots error |
| 9 | Source file error |

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

### Show completed history tasks

```bash
kvs queue history --status=completed
```

### Show cancelled history tasks

```bash
kvs queue history --status=cancelled
```

### Export as JSON

```bash
kvs queue list --format=json
```
