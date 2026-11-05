# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **Status push reached a 404 on the default endpoint**: without `LOG_SHIPPER_STATUS_ENDPOINT`, the status URL was derived as `<api_endpoint>/status`, i.e. `/api/ingest/status`, which the log server does not serve (status lives at `/api/stats`). The response was never checked, so the push failed silently on every install that relied on the default. An endpoint ending in `/ingest` now maps to `/stats`; any other endpoint keeps the old `/status` suffix.
- **Status push failures are visible**: a non-2xx response or a transport error now writes a `[Log Shipper] Status push failed: ...` warning to the local log. The line carries `log_shipper_failure` in its context, so the shipper handler does not ship it (no loop).
- **Queue metric failed with `queue_connection = 'default'`**: `ShipStatusJob::getQueueMetrics()` passed `'default'` straight to `Queue::connection()` and reported "Could not fetch queue metrics". It now maps `'default'` to `queue.default`, the same way the commands already did.
- **The log handler no longer throws into the host app**: `LogShipperHandler::write()` had no outer guard. With the circuit breaker enabled, a failing cache store (e.g. Redis down) made `Cache::has()` throw, and a context object whose `__toString()` throws broke sanitization. Monolog rethrows handler exceptions, so a plain `Log::error()` crashed the request. The whole handler body is now wrapped in `try/catch (Throwable)` and failures are swallowed silently (no local log line, which would recurse). The README's `'ignore_exceptions' => false` stack setting is unchanged; other handlers on the stack can still throw.
- **Oversized logs were rejected by the server**: a payload over `max_payload_size` had its `context` replaced by the string `[TRUNCATED: Payload too large]`. The log server validates `context` as `nullable|array`, so every truncated log got a 422, was retried and lost. `context` is now an array `{"_truncated": true, "_original_size": <bytes>}`, a huge `extra` is replaced by `{"_truncated": true}` too, and the top-level `_truncated` / `_original_size` markers stay.
- **`LOG_SHIPPER_MAX_PAYLOAD_SIZE` did nothing**: `max_payload_size` was missing from the config file. It is now in the config (default 1048576 bytes).
- **Fields over the server's length limits were rejected**: the handler sent `message`, `user_agent`, `referrer` and the other request fields at any length, and the server answers values above its `max:` rules with a 422. The handler now cuts them to the server limits (characters, multibyte-safe): `message` and `request_url` 65535, `referrer` 2048, `user_agent` 1024, `channel` / `user_id` / `route_name` / `controller_action` 255, `app_env` 50, `ip_address` 45. The cut runs after sanitizing, so a redacted query value (`token=a` becomes `token=[REDACTED]`) cannot push a URL back over the limit.
- **Unknown request methods were rejected by the server**: methods other than GET, POST, PUT, PATCH, DELETE, HEAD and OPTIONS (e.g. PROPFIND) made the server answer 422. They are now sent as `null`.
- **Logs with invalid UTF-8 were lost**: a message or context with invalid UTF-8 made `json_encode` fail. The size check was skipped, the Redis buffer stored an empty entry that was dropped on pop, and queue dispatch failed. The payload is now encoded once with invalid bytes replaced by U+FFFD and decoded back, before the size check, the buffer push and the dispatch. `NAN` and `INF` floats, which also made `json_encode` fail, are sent as `0`. The Redis buffer skips a payload it cannot encode instead of pushing `false`.
- **Timestamps were stored hours off on non-UTC apps**: they were sent as local wall time without a timezone. They are now converted to UTC and sent as `Y-m-d\TH:i:s.uP` (e.g. `2026-11-05T13:30:00.123456+00:00`).
- **Exceptions lost their stack trace**: an exception in the log context was sent as class, message, code, file and line only. It now carries a `trace` string (`#i file:line Class->function()`, max 50 frames, no call arguments) and a `previous` chain (same shape, up to 3 levels deep). The handler also sends the frames as `context.trace` (list of `file`, `line`, `class`, `type`, `function`, max 50, no arguments), which the log server uses to find the failing controller method, unless the context already has a `trace` key.
- **Rejected payloads tripped the circuit breaker**: `ShipLogJob` and `ShipBatchJob` threw on every non-2xx response, so a 4xx was retried 3 times and each try counted toward the circuit breaker; a few bad logs could switch off shipping for all logs. A 4xx response other than 429 now fails the job at once without retries; `failed()` still writes it to the fallback channel. 429, 5xx and connection errors keep retrying.
- **Batch size is capped at 100**: `log-shipper:ship-batch` used `batch.size` as given, and the server rejects batches over 100 entries. Values above 100 are now clamped to 100.
- **The Redis batch buffer had no size limit**: if the scheduler stopped running, the list kept growing in the same Redis that holds sessions, cache and queues until Redis ran out of memory. Each push now trims the list in the same transaction and keeps only the newest `batch.max_buffer_size` entries (oldest are dropped, same as the cache buffer). Values below 1 fall back to 10000.
- **The cache batch driver failed with the default config**: it used `batch.connection` as the cache store name, and its default `default` is not a cache store, so `LOG_SHIPPER_BATCH_DRIVER=cache` without `LOG_SHIPPER_BATCH_CONNECTION` threw "Cache store [default] is not defined". `default` now means the app's default cache store (unless a store with that name exists).
- **An open circuit breaker dropped logs silently**: while it is open, each log now goes to `fallback_channel` (if set and not `log_shipper`), sanitized and tagged with `log_shipper_failure` so it is never shipped.
- **A dead queue or failing batch buffer did not trip the circuit breaker**: only the HTTP jobs counted, so every log in a request waited on the broken queue again. Both now count as failures, at most once per log (a failing buffer followed by a failing dispatch counts once).
- **The circuit breaker's failure counter never expired**: failures from an old outage added up forever and a single later failure could open the breaker. The counter now expires after `circuit_breaker.duration`.
- **Sync queue failures were handled twice**: with a `sync` queue, a failing `ShipLogJob` was counted twice by the circuit breaker (once in the job, once in the handler), so it tripped at half the threshold, and it was written to the fallback channel twice (the job's `failed()` plus a "LogShipper failed to dispatch job" entry). The handler now leaves both to the job on a sync queue.
- **The recursion guard missed jobs**: it now covers all package jobs (`ShipLogJob`, `ShipBatchJob`, `ShipStatusJob`) and matches the real installed path (`src/Jobs/`). Before, worker errors about `ShipBatchJob` were shipped again during an outage.
- **Failed batches were lost**: `ShipBatchJob` now has `failed()`: every log of a failed batch goes to `fallback_channel` with `log_shipper_failure` set and a sanitized `original_payload`. Shipping is paused during the write and the previous `enabled` value restored afterwards.
- **A failing `ShipLogJob` switched shipping on**: its `failed()` set `log-shipper.enabled` to true for the rest of the worker's life, even with `LOG_SHIPPER_ENABLED=false`. It now restores the previous value.
- **`LOG_SHIPPER_ENDPOINT=null` with a key set made every job fail** with a TypeError (3 attempts into `failed_jobs`). The job now exits quietly.
- **Unconfigured apps still queued jobs**: an app without `LOG_SHIPPER_KEY` or `LOG_SHIPPER_ENDPOINT` queued (or buffered) a job per log line that then did nothing. The handler now skips both.
- **Config fallbacks did not match the shipped config**: apps with an older published config that lacks a nested key (Laravel only merges top-level keys) ran without the circuit breaker and without the `os`, `host` and `node_npm` status metrics. They now get the same defaults as a fresh install.
- **Scheduled commands never ran on Laravel 10**: the provider fetched the scheduler during boot, before Laravel 10's console kernel binds it, so `log-shipper:status` and `log-shipper:ship-batch` landed on a throwaway instance. They are now registered once the real scheduler is resolved (Laravel 10 through 13).
- **"Missing configuration" warning flooded cron mail**: it no longer prints on `schedule:run`, `schedule:work`, `queue:work`, `queue:listen`, `horizon` and `horizon:*` starts, nor on the package's own scheduled `log-shipper:status` and `log-shipper:ship-batch`. Leading options are skipped when finding the command, so `artisan --env=staging schedule:run` is quiet too. Other artisan commands still show it.
- **Timed-out composer/npm checks left orphans**: the timeout only killed the `sh` wrapper of `cd X && ...`. Commands now run with the working directory passed to `proc_open` and start with `exec`, so the timeout kills the binary itself.

### Changed
- **Guzzle 8 allowed**: `guzzlehttp/guzzle` constraint widened to `^7.0|^8.0`. Fresh Laravel installs resolve Guzzle 8 and could not install the package before. Test suite green on 7.16.1 and 8.3.1.
- **401, 403 and 404 count toward the circuit breaker**: they still fail the job without retry, but a wrong project key or endpoint now trips the breaker instead of queuing doomed jobs forever. 413 and 422 (payload problems) do not count.
- **One sanitizer**: `AdminIntelligence\LogShipper\Utils\Sanitizer` is used by both the log handler and `ShipLogJob::failed()`. The job's looser copy (plain substring match, context only) is gone; the fallback channel now gets the same redaction as the log server, including the URL fields. The protected `LogShipperHandler::sanitizeRecursive()` and `isSensitiveKey()` were removed.
- **Fallback entries are tagged**: the "LogShipper failed to dispatch job" entry now carries `log_shipper_failure`, so a fallback channel that stacks `log_shipper` cannot loop.
- **New config `batch.max_buffer_size`** (env `LOG_SHIPPER_BATCH_MAX_BUFFER`, default 10000) sets the Redis buffer cap. The config now states that the `cache` batch driver is for development or low traffic only (it takes a lock and rewrites the whole buffer on every log line); Redis is recommended.
- **`dependency_checks` and `security_audits` default to off** in the published config, matching the README. They run four shell commands with up to 30 s each.
- **Dependency counters can be null**: `composer_outdated`, `npm_outdated`, `composer_audit` and `npm_audit` are `null` when the command is missing, fails, times out or prints unparseable output. Before they reported 0, which looked like "all clear". They stay an int on success.
- **`cache` status metric removed**: it always sent an empty array. The `cache` key in `status.metrics` is gone from the config; an old key in a published config is ignored.
- **`ShipStatusJob::collectMetrics()` is public**: `log-shipper:test-status` calls it directly instead of through reflection.
- **CI**: the matrix also covers PHP 8.4 and 8.5 (Laravel 10 capped at 8.3, Laravel 11 at 8.4). The workflow runs on every push and every pull request, not only main.

### Security
- **Sensitive array values were not redacted**: keys like `authorization => ['Bearer …']` (request headers) were walked into, never redacted. The whole value is now replaced with `[REDACTED]`.
- **camelCase keys slipped through**: key matching only knew `_` and `-` separators, so `apiKey`, `accessToken`, `clientSecret` and `privateKey` were shipped. Keys and configured fields are now split into words (camelCase, `_`, `-`, spaces, brackets) and matched as whole words, so `apiKey` / `x-api-key` / `API_KEY` match `api_key` while `secretary` does not match `secret`. A key word may also be the plural of the field word (trailing `s`), so `passwords`, `api_keys` and `apiKeys` are still redacted.
- **URL query secrets were shipped**: `request_url` and `referrer` kept their query strings (`?token=…`, reset links). Values of sensitive query and fragment parameters are now redacted (`?token=[REDACTED]&page=2`); the rest of the URL is kept.
- **More default `sanitize_fields`**: `cookie`, `session`, `x-xsrf-token` and `private_key`.
- **Status push over plain http**: the status job sent the project key to http endpoints in production. It now applies the same https-in-production rule as the log jobs, to the final status URL (after the `/ingest` to `/stats` derivation or an explicit `status.endpoint`). A refused push writes a local warning instead of sending.
- **DB credentials in the status payload**: on a database connection failure the payload carried the raw driver message, which can contain the DB user and host. It now sends only the exception class plus SQLSTATE and driver error code, e.g. `PDOException SQLSTATE[HY000] [1045]`.

### Upgrade Notes
- **Dependency checks and security audits are off by default.** Apps that relied on the previous config default get no `composer_outdated` / `npm_outdated` / `composer_audit` / `npm_audit` values until `status.metrics.dependency_checks` / `status.metrics.security_audits` are set to `true`.
- **Dependency counters can be `null`.** Consumers of the status payload must treat `null` as "check could not run", not as 0.
- **4xx responses are no longer retried**, except 429. 401, 403 and 404 now count toward the circuit breaker; with a wrong key or endpoint, shipping pauses and logs go to `fallback_channel`.
- **Timestamps are UTC ISO 8601 with offset** (`Y-m-d\TH:i:s.uP`). Apps not on UTC will see their logs at the correct time from now on; older entries from those apps stay shifted.
- **Sanitization is stricter.** Field names match as whole words in any key style, sensitive keys holding arrays are redacted whole, and sensitive query parameters in `request_url` / `referrer` are redacted. The new default fields mean that, for example, `session` now also redacts `session_id`, and `cookie` redacts cookie headers. Remove a field from a published `sanitize_fields` list if you need it shipped.
- **`LogShipperHandler::sanitizeRecursive()` and `isSensitiveKey()` are gone.** Subclasses that called or overrode these protected methods must use `AdminIntelligence\LogShipper\Utils\Sanitizer` instead.
- **The `cache` status metric is removed.** Receiving servers no longer get a `cache` key.
- **Config fallbacks now match the shipped config.** Published configs that lack these nested keys now get the `os`, `host` and `node_npm` status metrics and the circuit breaker switched on. Set them to `false` explicitly to keep them off.
- **New config keys** `max_payload_size` and `batch.max_buffer_size`. Published configs work without them (code defaults 1048576 and 10000); add them to change the values.

## [1.5.1] - 2026-09-21

### Fixed
- **Lists in the log context no longer kill the log line**: `LogShipperHandler::sanitizeRecursive()` walked every array with `foreach ($data as $key => $value)` and handed the key to `isSensitiveKey(string $key, ...)`. A list — any array with integer keys, e.g. `Log::info('Summary', ['new_users' => ['alice', 'bob']])` — therefore raised `TypeError: isSensitiveKey(): Argument #1 ($key) must be of type string, int given` under `declare(strict_types=1)`, and the log entry was lost. `isSensitiveKey()` now accepts `string|int` in both `LogShipperHandler` and `ShipLogJob`. Integer keys are cast and never match a sensitive field name, so list values are shipped unchanged and recursion into nested lists of arrays still redacts sensitive keys inside them. Affects every release since sanitization was introduced; empty lists were unaffected, which is why it only surfaced on payloads that actually contained data.

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

[Unreleased]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/compare/v1.5.1...HEAD
[1.5.1]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.5.1
[1.5.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.5.0
[1.4.1]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.4.1
[1.4.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.4.0
[1.3.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.3.0
[1.2.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.2.0
[1.1.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.1.0
[1.0.1]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.0.1
[1.0.0]: https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/releases/tag/v1.0.0
