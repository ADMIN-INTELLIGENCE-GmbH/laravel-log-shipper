# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
