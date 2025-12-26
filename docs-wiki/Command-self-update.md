# kvs self-update

Update KVS-CLI to the latest version.

## Synopsis

```bash
kvs self-update [options]
```

## Description

The `self-update` command updates KVS-CLI to the latest available version. It checks GitHub releases and downloads the new PHAR file.

**Note:** Only works for PHAR installations. For source installations, use `git pull`.

## Options

| Option | Description |
|--------|-------------|
| `--stable` | Force update to latest stable release |
| `--preview` | Include pre-release (beta) versions |
| `--dev` | Update to latest dev build from CI |
| `--check` | Only check for updates, don't install |
| `-y, --yes` | Skip confirmation prompt |

## Examples

### Check for Updates

```bash
kvs self-update --check
```

Output:

```
Current version: 1.0.0
Checking for updates...

New version available: 1.1.0 (current: 1.0.0)

Run 'kvs self-update' to install the update.
```

### Update to Latest Stable

```bash
kvs self-update
```

Output:

```
Current version: 1.0.0
Checking for updates...

New version available: 1.1.0 (current: 1.0.0)

Update to version 1.1.0? [Y/n] y

Downloading from https://github.com/.../kvs.phar...
Verifying new version...
New version verified.
Installing update...

Updated KVS CLI to 1.1.0.
```

### Skip Confirmation

```bash
kvs self-update --yes
```

### Include Pre-release Versions

```bash
kvs self-update --preview
```

This includes alpha, beta, and release candidate versions.

### Update to Dev Build

```bash
kvs self-update --dev
```

**Warning:** Dev builds may be unstable.

Output:

```
Current version: 1.0.0
Fetching latest dev build from CI...
Extracting...
Verifying...
New version verified.

⚠ Dev builds may be unstable.
Update to latest dev build? [y/N] y

Installing...
Updated to dev build: KVS-CLI 1.1.0-dev.abc1234
```

### Permission Issues

If you get permission errors:

```bash
sudo kvs self-update
```

Or fix ownership:

```bash
sudo chown $USER:$USER /usr/local/bin/kvs
kvs self-update
```

### Source Installation

For source installations, `self-update` won't work:

```bash
kvs self-update
```

Output:

```
Self-update only works for PHAR installations.
If installed via git, use: git pull && composer install
```

## Aliases

- `kvs selfupdate`
- `kvs self:update`

## See Also

- [[Installation|Installation]] - Installation methods
