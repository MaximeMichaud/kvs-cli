# kvs system:antispam

**[EXPERIMENTAL]** Manage KVS anti-spam settings.

## Synopsis

```bash
kvs system:antispam [<action>] [options]
```

## Description

The `system:antispam` command manages KVS anti-spam protection including blacklists (words, domains, IPs) and content submission rules.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `show`, `set`, `add`, `remove`, `blacklist` (default: `show`) |

## Options

### General

| Option | Description |
|--------|-------------|
| `--format=FORMAT` | Output format: `table`, `json` |
| `--force` | Skip experimental feature confirmation |

### Blacklist Management

| Option | Description |
|--------|-------------|
| `--words=LIST` | Blacklisted words (comma-separated) |
| `--words-ignore-feedbacks` | Ignore feedbacks for word check (0\|1) |
| `--domains=LIST` | Blocked email domains (comma-separated) |
| `--ips=LIST` | Blocked IP addresses (comma-separated) |
| `--blacklist-action=ACTION` | Blacklist action (`delete`, `deactivate`) |
| `--clear-words` | Clear all blacklisted words |
| `--clear-domains` | Clear all blocked domains |
| `--clear-ips` | Clear all blocked IPs |

### Duplicate Detection

| Option | Description |
|--------|-------------|
| `--duplicates-comments` | Delete comment duplicates (0\|1) |
| `--duplicates-messages` | Delete message duplicates (0\|1) |

### Content Rules (Videos, Albums, Posts, Playlists, DVDs, Comments, Messages, Feedbacks)

For each content type, use prefix: `videos-`, `albums-`, `posts-`, `playlists-`, `dvds-`, `comments-`, `messages-`, `feedbacks-`

| Suffix | Description | Format |
|--------|-------------|--------|
| `-captcha` | Force captcha after threshold | `count/seconds` |
| `-disable` | Deactivate content after threshold | `count/seconds` |
| `-delete` | Auto-delete content after threshold | `count/seconds` |
| `-error` | Show error after threshold | `count/seconds` |
| `-history` | Analysis scope | `all` or `user` |

## Actions

### show

Display current anti-spam settings.

```bash
kvs antispam show
kvs antispam show --format=json
```

### set

Replace entire blacklist or update settings.

```bash
# Replace blacklist
kvs antispam set --words="spam,scam,viagra"
kvs antispam set --domains="spam.com,temp.mail"
kvs antispam set --ips="1.2.3.4,5.6.7.8"

# Clear blacklist
kvs antispam set --clear-words
kvs antispam set --clear-domains --clear-ips
```

### add

Add to existing blacklist.

```bash
kvs antispam add --words="newspam"
kvs antispam add --domains="bad.com"
kvs antispam add --ips="1.2.3.4"
```

### remove

Remove from blacklist.

```bash
kvs antispam remove --words="spam"
kvs antispam remove --domains="spam.com"
kvs antispam remove --ips="1.2.3.4"
```

### blacklist

Show detailed blacklist.

```bash
kvs antispam blacklist
```

## Rule Format

Rules use `count/seconds` format:

- `5/60` = 5 items within 60 seconds
- `10/300` = 10 items within 5 minutes
- `3/86400` = 3 items within 24 hours

## Content Sections

| Section | Description |
|---------|-------------|
| `videos` | Video uploads |
| `albums` | Album/image uploads |
| `posts` | Blog posts |
| `playlists` | User playlists |
| `dvds` | DVD/channel creation |
| `comments` | Comments on content |
| `messages` | Private messages |
| `feedbacks` | Feedback submissions |

## Rule Types

| Type | Description | Effect |
|------|-------------|--------|
| `captcha` | Force captcha | Show CAPTCHA after threshold |
| `disable` | Deactivate | Mark content as inactive |
| `delete` | Auto-delete | Permanently remove content |
| `error` | Show error | Block submission with error |

## Examples

### View Settings

```bash
# Show all settings
kvs antispam show

# JSON format
kvs antispam show --format=json

# View blacklist
kvs antispam blacklist
```

### Manage Blacklist

```bash
# Set blacklisted words
kvs antispam set --words="spam,scam,viagra,casino"

# Add more words
kvs antispam add --words="pills,drugs"

# Remove word
kvs antispam remove --words="spam"

# Clear all words
kvs antispam set --clear-words
```

### Block Domains

```bash
# Block email domains
kvs antispam set --domains="tempmail.com,spam.ru,fake.net"

# Add domain
kvs antispam add --domains="another-spam.com"

# Remove domain
kvs antispam remove --domains="tempmail.com"
```

### Block IPs

```bash
# Block specific IPs
kvs antispam set --ips="1.2.3.4,5.6.7.8"

# Add IP
kvs antispam add --ips="9.10.11.12"

# Remove IP
kvs antispam remove --ips="1.2.3.4"
```

### Configure Content Rules

```bash
# Force captcha after 5 comments in 60 seconds
kvs antispam set --comments-captcha=5/60

# Disable videos after 10 uploads in 5 minutes
kvs antispam set --videos-disable=10/300

# Delete messages after 3 in 24 hours
kvs antispam set --messages-delete=3/86400

# Show error for albums after threshold
kvs antispam set --albums-error=5/120
```

### Set Blacklist Action

```bash
# Delete blacklisted content
kvs antispam set --blacklist-action=delete

# Deactivate blacklisted content
kvs antispam set --blacklist-action=deactivate
```

### Enable Duplicate Detection

```bash
# Delete duplicate comments
kvs antispam set --duplicates-comments=1

# Delete duplicate messages
kvs antispam set --duplicates-messages=1
```

### Analysis Scope

```bash
# Analyze user's own history
kvs antispam set --videos-history=user

# Analyze all users' history
kvs antispam set --comments-history=all
```

## Sample Output

```
Anti-Spam Settings
==================

Blacklist
---------
 Words               spam, scam, viagra (12 total)
 Domains             tempmail.com, spam.ru (5 total)
 IPs                 1.2.3.4 (1 total)
 Action              Delete

Duplicates
----------
 Comments            Enabled
 Messages            Enabled

Video Rules
-----------
 Captcha             5 uploads / 60 seconds
 Disable             10 uploads / 300 seconds
 History             User

Comment Rules
-------------
 Captcha             5 comments / 60 seconds
 Delete              20 comments / 3600 seconds
 History             All users
```

## Use Cases

### Basic Spam Protection

```bash
# Block common spam words
kvs antispam set --words="spam,viagra,casino,pills"

# Force captcha on rapid commenting
kvs antispam set --comments-captcha=5/60
```

### Aggressive Protection

```bash
# Strict video upload limits
kvs antispam set --videos-captcha=3/300
kvs antispam set --videos-disable=5/600

# Block temporary email domains
kvs antispam set --domains="tempmail.com,guerrillamail.com"

# Delete spam comments automatically
kvs antispam set --comments-delete=10/60
```

### Moderate Protection

```bash
# Reasonable limits for legitimate users
kvs antispam set --videos-captcha=10/600
kvs antispam set --comments-captcha=10/120

# Deactivate instead of delete
kvs antispam set --blacklist-action=deactivate
```

## Aliases

- `kvs antispam`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- Test rules carefully - aggressive settings can block legitimate users
- Use `deactivate` action for reversible blocking
- `delete` action is permanent - use with caution
- Blacklisted words are case-insensitive
- Rules apply immediately after saving

## Best Practices

1. **Start Conservative** - Begin with captcha, not deletion
2. **Monitor Logs** - Watch for false positives
3. **Whitelist Staff** - Exclude admin IPs if possible
4. **Test First** - Test rules on staging before production
5. **Document Changes** - Keep track of what you block and why

## See Also

- [`comment`](comment.md) - Manage comments (approve/reject)
- [`user`](user.md) - Manage users
- [`system:status`](system_status.md) - System status
