# KVS CLI - Bug Report

**Date:** 2025-12-18
**Version:** 1.0
**Tested on:** Production database (freehd.porn)

---

## Summary

| Priority | Bug | Status |
|----------|-----|--------|
| HIGH | Rating display shows raw DB value | ✅ Fixed |
| HIGH | `--fields` option shows empty ID/Status columns | Open |
| MEDIUM | `--approved`/`--pending` options missing for comments | Open |
| MEDIUM | `config get` fails for some keys | Open |
| LOW | Content path warning in system:status | Open |

---

## HIGH Priority

### 1. Rating Display Bug ✅ FIXED

**Status:** Fixed on 2025-12-18

**Affected Commands:**
- `kvs video show <id>`
- `kvs video stats`
- `kvs album show <id>`

**Problem:**
Rating displayed raw database value instead of normalized 0-5 scale.

**Root Cause:**
KVS stores cumulative rating points (each vote adds 0-5 points). The code was dividing by `RATING_DIVISOR` (20) instead of dividing by `rating_amount`.

**Fix Applied:**
Changed rating calculation from:
```php
$video['rating'] / Constants::RATING_DIVISOR  // WRONG
```
To:
```php
$video['rating'] / $video['rating_amount']    // CORRECT (matching KVS)
```

**Files Fixed:**
- `src/Command/Content/VideoCommand.php` - showVideo(), showStats(), getFieldValue()
- `src/Command/Content/AlbumCommand.php` - showAlbum()

---

### 2. --fields Option Shows Empty Columns

**Affected Commands:**
- `kvs video list --fields=id,title,views`
- `kvs album list --fields=id,title,images,views`
- `kvs user list --fields=id,username,email,status`
- `kvs category list --fields=id,title,videos,albums`
- `kvs tag list --fields=id,tag,videos,albums`

**Problem:**
When using custom `--fields`, certain columns appear empty even though data exists.

**Example:**
```
kvs video list --fields=id,title,views

┌────┬────────────────────────────────────────────────────┬───────┐
│ Id │ Title                                              │ Views │
├────┼────────────────────────────────────────────────────┼───────┤
│    │ Submissive Hottie Miss Estigia Receives BDSM Play  │ 0     │
│    │ Handsome Stud Jay Smooth Destroys His Blonde Po... │ 0     │
└────┴────────────────────────────────────────────────────┴───────┘
```

**Root Cause:**
The `pick_fields()` function in `Utils.php` doesn't map field aliases:
- `id` → `video_id`, `album_id`, `user_id`, etc.
- `status` → `status_id`
- `views` → `video_viewed`, `album_viewed`
- `images` → `image_count`

**Files to Fix:**
- `src/Utils.php` - `pick_fields()` function
- OR `src/Output/Formatter.php` - add field alias mapping

**Suggested Fix:**
Add field alias map in Formatter or Utils:
```php
const FIELD_ALIASES = [
    'id' => ['video_id', 'album_id', 'user_id', 'category_id', 'tag_id', 'model_id'],
    'status' => ['status_id'],
    'views' => ['video_viewed', 'album_viewed', 'profile_viewed'],
    'images' => ['image_count'],
    'videos' => ['video_count'],
    'albums' => ['album_count'],
];
```

---

## MEDIUM Priority

### 3. Missing Comment Filter Options

**Affected Command:**
- `kvs comment list`

**Problem:**
The `--approved` and `--pending` options are not implemented but would be useful.

**Error:**
```
The "--approved" option does not exist.
The "--pending" option does not exist.
```

**Suggested Implementation:**
```php
->addOption('approved', null, InputOption::VALUE_NONE, 'Show only approved comments')
->addOption('pending', null, InputOption::VALUE_NONE, 'Show only pending comments')
```

**File to Fix:**
- `src/Command/Content/CommentCommand.php`

**Note:** Need to check KVS database schema for comment approval field name (likely `is_approved` or `status_id`).

---

### 4. Config Get Key Not Found

**Affected Command:**
- `kvs config get project_url`
- `kvs config get project_title`

**Problem:**
Some configuration keys return "not found" error even though they exist in KVS config.

**Error:**
```
[ERROR] Configuration key not found: project_url
[ERROR] Configuration key not found: project_title
```

**Root Cause:**
The Configuration class may not be loading all config arrays, or the key names differ from what's expected.

**Files to Check:**
- `src/Config/Configuration.php`
- KVS config file: `/var/www/freehd.porn/admin/include/setup.php`

**Workaround:**
Use `kvs config list` to see all available keys.

---

## LOW Priority

### 5. Content Path Warning

**Affected Command:**
- `kvs system:status`

**Problem:**
Warning about content path not found.

**Warning:**
```
[WARNING] Content path not found: /var/www/content
```

**Root Cause:**
The system:status command checks for `/var/www/content` but actual content path is different on this installation.

**File to Fix:**
- `src/Command/System/StatusCommand.php`

**Note:** Should read content path from KVS configuration instead of hardcoding.

---

## Working Correctly

The following features work as expected:

### Commands
- ✅ `kvs video list` (all filters except --fields bug)
- ✅ `kvs video show <id>` (except rating)
- ✅ `kvs album list`
- ✅ `kvs album show <id>` (except rating)
- ✅ `kvs user list`
- ✅ `kvs user show <id>`
- ✅ `kvs comment list`
- ✅ `kvs comment stats`
- ✅ `kvs category list`
- ✅ `kvs category show <id>`
- ✅ `kvs tag list`
- ✅ `kvs tag stats`
- ✅ `kvs model list`
- ✅ `kvs model show <id>`
- ✅ `kvs model stats`
- ✅ `kvs config list`
- ✅ `kvs system:status` (except warnings)

### Output Formats
- ✅ `--format=table`
- ✅ `--format=json`
- ✅ `--format=csv`
- ✅ `--format=yaml`
- ✅ `--format=count`

### Filters
- ✅ `--status=active|disabled|error`
- ✅ `--limit=N`
- ✅ `--search=term`
- ✅ `--user=N`
- ✅ `--video=N` (for comments)
- ✅ `--no-truncate`

---

## Test Data Summary

| Entity | Count | Notes |
|--------|-------|-------|
| Videos | 48,922 | 48,838 active, 11 disabled, 73 error |
| Albums | 11,805 | All active |
| Users | 103 | All status_id=2 (Active) |
| Comments | 0 | No comments in database |
| Categories | 1,360 | All active |
| Tags | 41,890 | All active |
| Models | 11,309 | All active |

---

## Recommended Fix Order

1. **Rating Display** - Quick fix, high visibility
2. **--fields Mapping** - Impacts usability significantly
3. **Config get keys** - Medium effort
4. **Comment filters** - Nice to have
5. **Content path** - Low priority warning

---

*Report generated from CLI testing session*
