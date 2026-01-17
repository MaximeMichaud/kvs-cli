# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.1] - 2025-01-16

### Fixed

- StackScorer: LTS versions (e.g., MariaDB 11.8) incorrectly marked as "Outdated" when compared against non-LTS rolling releases
- Added "LTS Current" status for users running the latest LTS version

## [1.3.0] - 2025-01-16

### Added

- New `system:stats` command for comprehensive site statistics (videos, albums, users, tags, models, DVDs)
- FpmConfigReader utility to retrieve real PHP-FPM settings instead of CLI values
- Benchmark experiment mode with Stack Score, Config Score, and Efficiency Score
- Upgrade recommendations in benchmark results
- Storage detection improvements for cloud VPS environments
- Composer audit in CI for security vulnerability detection

### Changed

- `system:status` and `system:check` now use FpmConfigReader for accurate PHP settings
- Updated PHP requirements for KVS 6.2.1+ (now supports PHP 8.1)
- PHPStan upgraded from level 9 to level 10 with strict type compliance
- Benchmark `--export` is now opt-in instead of auto-export
- Replaced hardcoded values with constants throughout codebase
- Extracted duplicate code to shared helpers (EvalSecurityTrait, InputHelperTrait)

### Fixed

- CacheCommand: Invalid `TRUNCATE TABLE IF EXISTS` SQL syntax
- MaintenanceCommand: Incorrect status check when DISABLE_WEBSITE = 0
- PluginCommand/CheckCommand: Version comparison using str_replace instead of version_compare
- ImportCommand: Missing port handling for host:port format
- PHP 8.1 and 8.5 compatibility issues
- Database configuration validation edge cases
- Benchmark Config Score thresholds for KVS requirements

### Removed

- Dead code identified by static analysis
- Unused PHPStan baseline file

## [1.2.0] - 2024-12-15

### Added

- Initial stable release
- Core commands: video, album, user, comment, category, tag
- Database commands: db:export, db:import
- System commands: system:status, system:check, benchmark, maintenance, cache
- Docker support for remote KVS installations
- Multiple output formats (table, json, csv, count)

[1.3.1]: https://github.com/MaximeMichaud/kvs-cli/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/MaximeMichaud/kvs-cli/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/MaximeMichaud/kvs-cli/releases/tag/v1.2.0
