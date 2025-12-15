# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.1.0
[1.0.1]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.0.1
[1.0.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.0.0
