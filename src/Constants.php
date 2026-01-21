<?php

declare(strict_types=1);

namespace KVS\CLI;

/**
 * Centralized constants for KVS CLI.
 *
 * This class contains all magic numbers and default values
 * that were previously hardcoded throughout the codebase.
 */
final class Constants
{
    // ========================================
    // OUTPUT FORMATTING
    // ========================================

    /** Default character limit for truncating text in tables */
    public const DEFAULT_TRUNCATE_LENGTH = 50;

    /** Character limit for config section values (longer for readability) */
    public const CONFIG_TRUNCATE_LENGTH = 80;

    /** Character limit for comment text display */
    public const COMMENT_TRUNCATE_LENGTH = 100;

    /** Character limit for CPU model names in benchmark output */
    public const CPU_MODEL_TRUNCATE_LENGTH = 40;

    /** Symfony Table style used throughout the app */
    public const TABLE_STYLE = 'box';

    /** JSON encoding flags for consistent output */
    public const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

    // ========================================
    // PAGINATION / LIMITS
    // ========================================

    /** Default number of results for list commands */
    public const DEFAULT_LIMIT = 50;

    /** Default limit for video/album lists */
    public const DEFAULT_CONTENT_LIMIT = 20;

    /** Default limit for comment lists */
    public const DEFAULT_COMMENT_LIMIT = 50;

    /** Limit for "top" queries (top tags, recent comments, etc.) */
    public const TOP_QUERY_LIMIT = 10;

    /** Limit for stats sampling queries (averages, etc.) */
    public const STATS_SAMPLE_LIMIT = 100;

    // ========================================
    // FIELD DETECTION
    // ========================================

    /** Field names that should be treated as IDs (for formatting) */
    public const ID_FIELD_NAMES = [
        'id',
        'user_id',
        'video_id',
        'album_id',
        'comment_id',
        'category_id',
        'tag_id',
        'model_id',
        'dvd_id',
    ];

    // ========================================
    // RATING SYSTEM
    // ========================================

    /** Maximum rating value (for display as X/5) */
    public const RATING_SCALE = 5;

    // ========================================
    // DATABASE
    // ========================================

    /** Default database charset */
    public const DB_CHARSET = 'utf8mb4';

    /** Default table prefix (fallback if not in config) */
    public const DEFAULT_TABLE_PREFIX = 'ktvs_';

    // ========================================
    // OBJECT TYPES (for comments, etc.)
    // ========================================

    /** Object type ID for videos */
    public const OBJECT_TYPE_VIDEO = 1;

    /** Object type ID for albums */
    public const OBJECT_TYPE_ALBUM = 2;

    /** Object type ID for content sources */
    public const OBJECT_TYPE_CONTENT_SOURCE = 3;

    /** Object type ID for models */
    public const OBJECT_TYPE_MODEL = 4;

    /** Object type ID for DVDs */
    public const OBJECT_TYPE_DVD = 5;

    /** Object type ID for posts */
    public const OBJECT_TYPE_POST = 12;

    /** Object type ID for playlists */
    public const OBJECT_TYPE_PLAYLIST = 13;

    // ========================================
    // SYSTEM THRESHOLDS
    // ========================================

    /** Disk usage percentage that triggers critical alert */
    public const DISK_CRITICAL_PERCENT = 90;

    /** Disk usage percentage that triggers warning */
    public const DISK_WARNING_PERCENT = 80;

    /** Hours after which backup is considered stale */
    public const BACKUP_WARNING_HOURS = 24;

    /** Default memcached port */
    public const DEFAULT_MEMCACHE_PORT = 11211;

    // ========================================
    // PROCESS TIMEOUTS (in seconds)
    // ========================================

    /** Timeout for database operations (1 hour) */
    public const DB_PROCESS_TIMEOUT = 3600;

    /** Timeout for file backup operations (2 hours) */
    public const FILE_BACKUP_TIMEOUT = 7200;

    /** Timeout for HTTP requests (30 seconds) */
    public const HTTP_REQUEST_TIMEOUT = 30;

    /** Timeout for file downloads (5 minutes) */
    public const DOWNLOAD_TIMEOUT = 300;

    // ========================================
    // TIME INTERVALS (for SQL queries)
    // ========================================

    /** Days for "recent" comment statistics */
    public const RECENT_DAYS = 7;

    /** Hours for "recent activity" queries */
    public const RECENT_HOURS = 24;

    // ========================================
    // CONTENT DIRECTORY NAMES (KVS structure)
    // ========================================

    /** Base content directory name */
    public const CONTENT_DIR = 'contents';

    /** Video source files directory */
    public const CONTENT_VIDEOS_SOURCES = 'videos_sources';

    /** Video screenshots directory */
    public const CONTENT_VIDEOS_SCREENSHOTS = 'videos_screenshots';

    /** Album source files directory */
    public const CONTENT_ALBUMS_SOURCES = 'albums_sources';

    /** Category images directory */
    public const CONTENT_CATEGORIES = 'categories';

    /** Model images directory */
    public const CONTENT_MODELS = 'models';

    /** DVD images directory */
    public const CONTENT_DVDS = 'dvds';

    /** User avatars directory */
    public const CONTENT_AVATARS = 'avatars';

    // ========================================
    // GITHUB / RELEASES
    // ========================================

    /** GitHub repository (owner/repo format) */
    public const GITHUB_REPO = 'MaximeMichaud/kvs-cli';

    /** GitHub API base URL */
    public const GITHUB_API_URL = 'https://api.github.com';

    /** PHAR filename */
    public const PHAR_NAME = 'kvs.phar';

    /** GitHub Actions artifact name for nightly builds */
    public const GITHUB_ARTIFACT_NAME = 'kvs-cli-phar';

    /** Nightly.link URL template for downloading artifacts */
    public const NIGHTLY_LINK_URL = 'https://nightly.link/%s/actions/runs/%s/%s.zip';

    /** Default MySQL port */
    public const DEFAULT_MYSQL_PORT = 3306;

    // ========================================
    // END OF LIFE API (endoflife.date)
    // ========================================

    /** Base URL for endoflife.date API */
    public const EOL_API_BASE = 'https://endoflife.date/api';

    /** Cache TTL for EOL data in seconds (24 hours) */
    public const EOL_CACHE_TTL = 86400;

    /** Cache directory for EOL data */
    public const EOL_CACHE_DIR = '/tmp/kvs-cli-eol-cache';

    /** Months before EOL to show warning */
    public const EOL_WARNING_MONTHS = 6;

    // ========================================
    // BENCHMARK API
    // ========================================

    /** Benchmark API URL (empty = disabled) */
    public const BENCHMARK_API_URL = 'https://kvs-benchmark.maximemichaud.workers.dev/api/benchmarks';

    /** Timeout for benchmark API requests in seconds */
    public const BENCHMARK_API_TIMEOUT = 30;
}
