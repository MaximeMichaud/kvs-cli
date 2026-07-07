# kvs user

Manage users in your KVS installation.

## Synopsis

```bash
kvs user [<action>] [<id>] [options]
```

## Description

The `user` command allows you to list, view, create, delete, and inspect
statistics for KVS user accounts.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `list`, `show`, `create`, `delete`, `stats` (default: `list`) |
| `id` | Conditional | User ID or username, required for `show` and `delete` |

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--status=STATUS` | - | Filter by status (`active`, `disabled`, `premium`, `not-confirmed`, `unconfirmed`, `anonymous`, `generated`, `webmaster`, `0-6`) |
| `--limit=N` | 20 | Number of results to show |
| `--search=TEXT` | - | Search in user admin text fields |
| `--country=COUNTRY` | - | Filter by country ID or title |
| `--gender=GENDER` | - | Filter by gender (`male`, `female`, `couple`, `transsexual`, `1-4`) |
| `--ip=IP` | - | Filter by IP address |
| `--activity=ACTIVITY` | - | Filter by KVS admin activity bucket |
| `--field-filter=FIELD-FILTER` | - | KVS admin field filter, such as `filled/avatar` |
| `--banned-status=STATUS` | - | Filter by login protection status (`temporary`, `permanent`, `1`, `2`) |
| `--removal-requested` | - | Filter users who requested account deletion |
| `--trusted` | - | Filter trusted users only |
| `--untrusted` | - | Filter untrusted users only |
| `--fields=FIELDS` | - | Comma-separated fields to display |
| `--field=FIELD` | - | Display a single field value |
| `--no-truncate` | - | Do not truncate long text fields in table view |
| `--format=FORMAT` | table | Output format: `table`, `csv`, `json`, `yaml`, `count`, `ids` |
| `-y, --yes` | - | Skip confirmation prompt for `delete` |

## Actions

### list

List users with optional filtering.

```bash
kvs user list [options]
```

### show

Display details of a specific user.

```bash
kvs user show <id-or-username>
```

### create

Interactively create a new user.

```bash
kvs user create
```

### delete

Delete a user through KVS native cleanup.

```bash
kvs user delete <id-or-username>
kvs user delete <id-or-username> --yes
```

### stats

Display user totals and recent users.

```bash
kvs user stats
```

## Mutating Actions

The `create` and `delete` actions modify user data. `delete` uses KVS native
cleanup and may queue associated videos and albums for background deletion.

## Available Fields

| Field | Aliases | Description |
|-------|---------|-------------|
| `user_id` | `id` | User ID |
| `username` | - | Login username |
| `display_name` | - | Display name |
| `email` | - | Email address |
| `status_id` | - | Numeric status ID |
| `status` | - | Status label |
| `tokens_available` | - | Available tokens |
| `tokens_required` | - | Required tokens |
| `profile_viewed` | - | Profile views |
| `country_id` | - | Country ID |
| `gender_id` | `gender` | Gender |
| `birth_date` | - | Birth date |
| `ip` | - | IP address |
| `added_date` | - | Registration date |
| `last_login_date` | - | Last login date |
| `logins_count` | - | Login count |
| `activity` | `activity_score` | Activity score |
| `activity_rank` | - | Activity rank |
| `videos_count` | `videos` | Videos uploaded |
| `albums_count` | `albums` | Albums created |
| `comments_count` | - | Comments posted |
| `friends_count` | - | Friends count |
| `is_trusted` | - | Trusted flag |
| `is_removal_requested` | - | Account removal request flag |
| `removal_reason` | - | Account removal reason |
| `description` | - | Profile description |
| `city` | - | City |
| `avatar` | - | Avatar filename |
| `cover` | - | Cover filename |
| `website` | - | Website URL |
| `education` | - | Education |
| `occupation` | - | Occupation |

## Activity Filters

The `--activity` option accepts values such as:

- `new_today`
- `new_week`
- `new_month`
- `have/logins`
- `have/videos`
- `have/albums`
- `have/dvds`
- `have/playlists`
- `have/comments`
- `have/friends`
- `no/logins`
- `no/videos`
- `no/albums`
- `no/dvds`
- `no/playlists`
- `no/comments`
- `no/friends`

There are also day, week, month, and year variants for login activity, such as
`have/logins_today` and `no/logins_month`.

## Field Filters

The `--field-filter` option accepts `empty/<field>` and `filled/<field>` forms
for KVS admin user fields such as:

- `description`
- `avatar`
- `cover`
- `city`
- `website`
- `education`
- `occupation`
- `about_me`
- `interests`
- `favourite_movies`
- `favourite_music`
- `favourite_books`
- `country_id`
- `gender_id`
- `relationship_status_id`
- `orientation_id`
- `profile_viewed`
- `tokens_available`
- `tokens_required`
- `birth_date`

Custom user fields can also be filtered when they exist in the KVS installation.

## Status Values

| Value | Alias | Description |
|-------|-------|-------------|
| 0 | `disabled` | Account disabled |
| 1 | `not-confirmed`, `unconfirmed` | Email not confirmed |
| 2 | `active` | Regular active user |
| 3 | `premium` | Premium user |
| 4 | `anonymous` | Anonymous system user |
| 5 | `generated` | Generated user |
| 6 | `webmaster` | Webmaster user |

## Examples

### Basic Usage

```bash
# List first 20 users
kvs user list

# List 50 users
kvs user list --limit=50

# Show user details
kvs user show 5

# Display user statistics
kvs user stats
```

### Filtering

```bash
# Status filters
kvs user list --status=active
kvs user list --status=premium
kvs user list --status=not-confirmed

# Profile filters
kvs user list --country=France
kvs user list --gender=male
kvs user list --ip=127.0.0.1

# Activity and moderation filters
kvs user list --activity=have/logins
kvs user list --field-filter=filled/avatar
kvs user list --banned-status=temporary
kvs user list --removal-requested
kvs user list --trusted
kvs user list --untrusted
```

### Mutating Actions

```bash
# Create a user interactively
kvs user create

# Delete a user with confirmation
kvs user delete 123

# Delete a user without confirmation prompt
kvs user delete 123 --yes
```

### Output Formats

```bash
# JSON output
kvs user list --format=json

# CSV export
kvs user list --format=csv > users.csv

# Count only
kvs user list --format=count

# IDs only
kvs user list --format=ids
```

### Field Selection

```bash
# Specific fields
kvs user list --fields=user_id,username,status_id,videos_count,albums_count --format=json

# Single field
kvs user list --field=username

# Removal request export
kvs user list --removal-requested --fields=id,username,email,removal_reason --format=csv

# Full text in table output
kvs user list --no-truncate
```

## Aliases

- `kvs users`
- `kvs member`
- `kvs members`
- `kvs content:user`

## See Also

- [`user:purge`](user_purge.md) - Bulk delete users
- [`video`](video.md) - Manage videos
- [`comment`](comment.md) - Manage comments
