# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.0] - 2026-07-31

### Added
- **OS Reporting**: Status payloads can include an `os` block with the distribution name, version, machine-readable distribution id, kernel release and CPU architecture. Read from `/etc/os-release` on Linux, `sw_vers` on macOS, `php_uname()` elsewhere. Enabled via `status.metrics.os`.
- **Host Runtime Reporting**: New `host` block with hostname, app URL, timezone, locale, web server software, PHP SAPI and loaded extensions. Pure PHP — no shell commands, no DNS lookups. Enabled via `status.metrics.host`.
- **Pending OS Updates**: New `updates` block reporting pending package updates, pending security updates, upgradable package details, pending-reboot state and when the host last refreshed its package metadata. Supports apt, dnf, yum, apk, pacman and Homebrew. Opt-in via `status.metrics.system_updates`.
- **Update Scan Configuration**: New `status.updates` section with `include_packages` (send counts only if you would rather not report installed software), `max_packages` (list cap, reported through the `truncated` flag) and `timeout`.

### Changed
- **Shell Execution Extracted**: `ShipStatusJob::runCommandWithTimeout()` now delegates to the new `AdminIntelligence\LogShipper\Status\CommandRunner`, which also drains stderr (preventing a chatty command from deadlocking on a full pipe buffer) and can report exit codes. Behaviour of existing metrics is unchanged.

### Upgrade Notes
- **Published configs do not pick up the new metrics automatically.** Laravel's `mergeConfigFrom()` merges only at the top level, so an application that has published `config/log-shipper.php` replaces the whole `status` array and the new keys never reach it. Those installs keep the old payload until `status.metrics.os`, `status.metrics.host`, `status.metrics.system_updates` and the `status.updates` block are added by hand, or the config is republished with `--force`. Installs that never published the config get `os` and `host` enabled by default.
- **Receiving log servers see new top-level keys.** With the shipped defaults, status payloads gain `os` and `host` blocks (and `updates` where enabled). Confirm the ingest side tolerates unknown keys before rolling this out.

### Security
- Update scanning is strictly read-only: it never refreshes package metadata, never accesses the network and never requires root. All commands are fixed literals — no configuration or user input is interpolated into a command string — and binary detection rejects anything that is not a plain binary name.

## [1.4.1] - 2026-04-13

### Fixed
- **Corrective Release for 1.4.0**: Added missing `strict_types` declarations and import cleanup so the package passes newer Pint rule sets used in CI.
- **Release Quality**: Refreshed development dependencies to resolve Composer security advisories in the test and tooling stack.
- **Documentation**: Updated release notes and README guidance for the 1.4.1 follow-up release.

## [1.4.0] - 2026-04-13

### Added
- **Laravel 13 Support**: Added compatibility with Laravel 13 (`illuminate/*` `^13.0`). Supports Laravel 10, 11, 12, and 13.

## [1.3.0] - 2026-02-21

### Added
- **All-Disk Monitoring**: `disk_space` in the status payload now includes a `disks` array containing metrics for every mounted filesystem (Linux/macOS via `df -kP`, Windows via `wmic`). The top-level `total`, `free`, `used`, and `percent_used` fields are preserved for backward compatibility.

### Changed
- **Disk Space Payload Structure**: `system.disk_space` now returns an object with both the primary-disk fields (`total`, `free`, `used`, `percent_used`) and a `disks` array, each entry containing `path`, `total`, `free`, `used`, and `percent_used`.

## [1.2.0] - 2025-12-18

### Added
- **IP Address Obfuscation**: Added privacy-compliant IP obfuscation with two methods: `mask` (zeros last octet/64 bits) and `hash` (one-way hash). Configurable via `ip_obfuscation.enabled` and `ip_obfuscation.method`.
- **HTTPS Enforcement**: Production environments now automatically reject HTTP endpoints and require HTTPS to prevent credential leaks.
- **Payload Size Limits**: Added configurable maximum payload size (default 1MB) with automatic truncation to prevent DoS attacks via oversized logs.
- **Enhanced Security**: Improved sanitization with strict key matching to avoid false positives. Added filtering for PDO/database objects to prevent credential leaks.
- **Buffer Overflow Protection**: CacheBuffer now implements ring buffer strategy (max 1000 items) to prevent memory exhaustion.
- **Atomic Redis Operations**: RedisBuffer now uses Lua script for atomic batch operations, eliminating N round-trips and improving performance.
- **Enhanced Status Metrics**: Added CPU usage, improved disk space metrics, package version tracking, folder size calculation, and optional Node/npm/Composer/security audit metrics.
- **Folder Size Monitoring**: Added ability to monitor total size of folders including all nested files.
- **Package Version Tracking**: Status payloads now include the log shipper package version.
- **Platform Detection**: Enhanced system metrics with platform-specific commands for Linux, macOS, and Windows.
- **Command Timeout Protection**: All shell commands now have timeout protection to prevent job hanging.
- **Improved Cron Scheduling**: Fixed scheduler to properly handle all interval ranges (1-59 minutes, hourly, daily).

### Fixed
- **Critical Lock Bug**: Fixed CacheBuffer to only release locks that were actually acquired, preventing errors in high-contention scenarios.
- **Buffer Size Validation**: Both CacheBuffer and RedisBuffer now validate and reject negative or excessive batch sizes.
- **JSON Decode Errors**: RedisBuffer now gracefully skips invalid JSON items instead of failing entire batch.
- **Infinite Loop Prevention**: Enhanced recursive log detection with performance optimization (skip check for debug/info logs).
- **Fallback Channel Protection**: Added protection against infinite loops when fallback channel is set to `log_shipper`.
- **Corrupted Cache Handling**: Buffer operations now handle corrupted cache data gracefully.

### Changed
- **Job Timeout**: Increased ShipStatusJob timeout from 30 to 120 seconds to accommodate expensive metrics collection.
- **Sanitization Matching**: Improved field matching to use underscore/hyphen delimiters, reducing false positives.
- **Object Filtering**: Enhanced filtering to handle closures, PDO objects, and JsonSerializable objects properly.

### Performance
- **Redis Batch Operations**: Changed from O(N) sequential LPOP calls to O(1) atomic Lua script execution.
- **Recursive Detection**: Added early exit for debug/info level logs to skip expensive checks.

## [1.1.0] - 2025-12-11

### Added
- **Batch Shipping**: Added support for buffering logs in Redis and shipping them in batches to reduce queue pressure.
- **Status Monitoring**: Added automatic system health monitoring (CPU, Memory, Disk, Queue, Database) with configurable reporting intervals.
- **Circuit Breaker**: Implemented a circuit breaker pattern to stop shipping logs temporarily after repeated failures, preventing queue congestion during outages.
- **Retries & Backoff**: Added configurable retry attempts and exponential backoff strategy for failed log shipping jobs.
- **Infinite Loop Prevention**: Added detection for recursive logging loops (e.g., when fallback logging triggers the shipper again) and context-aware skipping.
- **Reliability**: Added proper exception handling for 4xx/5xx API responses to ensure retries are triggered correctly.
- **Graceful Degradation**: Added protection against application crashes when using the `sync` queue driver if the log server is unreachable.
- **Fallback Channel**: Added configuration to specify a local fallback channel when log shipping fails.

## [1.0.1] - 2025-12-09

### Changed
- Changed default API endpoint path from `/api/logs` to `/api/ingest` to match the Logger service.

## [1.0.0] - 2025-12-09

### Added
- Initial release
- Log shipping to central server via HTTP
- Queue support for async log shipping
- Configurable log levels
- Automatic sensitive data sanitization
- Request context collection (user ID, IP, user agent, route, etc.)
- Laravel 10, 11, and 12 support

[Unreleased]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.5.0
[1.4.1]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.4.1
[1.4.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.4.0
[1.3.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.3.0
[1.2.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.2.0
[1.1.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.1.0
[1.0.1]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.0.1
[1.0.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.0.0
