<?php

declare(strict_types=1);

namespace AdminIntelligence\LogShipper\Jobs;

use AdminIntelligence\LogShipper\Jobs\Concerns\ShipsLogs;
use AdminIntelligence\LogShipper\Status\CommandRunner;
use AdminIntelligence\LogShipper\Status\HostInfoCollector;
use AdminIntelligence\LogShipper\Status\OsInfoCollector;
use AdminIntelligence\LogShipper\Status\SystemUpdatesCollector;
use Composer\InstalledVersions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class ShipStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ShipsLogs;

    public int $tries = 1;

    public int $timeout = 120; // Increased to handle composer/npm commands

    protected ?CommandRunner $runner = null;

    public function handle(): void
    {
        if (!config('log-shipper.status.enabled', false)) {
            return;
        }

        $payload = $this->collectMetrics();
        $this->ship($payload);
    }

    public function collectMetrics(): array
    {
        $metrics = [
            'timestamp' => now()->toIso8601String(),
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'app_debug' => config('app.debug'),
            'instance_id' => gethostname(),
            'log_shipper_version' => $this->getPackageVersion(),
        ];

        $enabledMetrics = config('log-shipper.status.metrics', []);

        if ($enabledMetrics['system'] ?? true) {
            $metrics['system'] = $this->getSystemMetrics();
        }

        if ($enabledMetrics['queue'] ?? true) {
            $metrics['queue'] = $this->getQueueMetrics();
        }

        if ($enabledMetrics['database'] ?? true) {
            $metrics['database'] = $this->getDatabaseMetrics();
        }

        if ($enabledMetrics['filesize'] ?? true) {
            $metrics['filesize'] = $this->getFilesizeMetrics();
        }

        if ($enabledMetrics['foldersize'] ?? true) {
            $metrics['foldersize'] = $this->getFoldersizeMetrics();
        }

        if ($enabledMetrics['os'] ?? true) {
            $metrics['os'] = $this->osInfoCollector()->collect();
        }

        if ($enabledMetrics['host'] ?? true) {
            $metrics['host'] = $this->hostInfoCollector()->collect();
        }

        if ($enabledMetrics['system_updates'] ?? false) {
            $metrics['updates'] = $this->systemUpdatesCollector()->collect();
        }

        return $metrics;
    }

    protected function getSystemMetrics(): array
    {
        $metrics = [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'server_memory' => $this->getServerMemory(),
            'cpu_usage' => $this->getCpuUsage(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'uptime' => $this->getUptime(),
            'disk_space' => $this->getDiskSpace(),
        ];

        // Optional dependency checks (can be slow, so they're configurable)
        if (config('log-shipper.status.metrics.node_npm', true)) {
            $metrics['node_version'] = $this->getNodeVersion();
            $metrics['npm_version'] = $this->getNpmVersion();
        }

        if (config('log-shipper.status.metrics.dependency_checks', false)) {
            $metrics['composer_outdated'] = $this->cachedCount('composer_outdated', 'composer.lock', fn () => $this->getComposerOutdatedCount());
            $metrics['npm_outdated'] = $this->cachedCount('npm_outdated', 'package-lock.json', fn () => $this->getNpmOutdatedCount());
        }

        if (config('log-shipper.status.metrics.security_audits', false)) {
            $metrics['composer_audit'] = $this->cachedCount('composer_audit', 'composer.lock', fn () => $this->getComposerAuditCount());
            $metrics['npm_audit'] = $this->cachedCount('npm_audit', 'package-lock.json', fn () => $this->getNpmAuditCount());
        }

        return $metrics;
    }

    protected function getServerMemory(): array
    {
        $memory = ['total' => null, 'free' => null, 'used' => null, 'percent_used' => null];

        try {
            if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
                $memInfo = file_get_contents('/proc/meminfo');
                preg_match('/MemTotal:\s+(\d+)\s+kB/', $memInfo, $totalMatches);
                preg_match('/MemAvailable:\s+(\d+)\s+kB/', $memInfo, $availableMatches);

                if (isset($totalMatches[1], $availableMatches[1])) {
                    $total = $totalMatches[1] * 1024;
                    $available = $availableMatches[1] * 1024;
                    $used = $total - $available;

                    $memory = [
                        'total' => $total,
                        'free' => $available,
                        'used' => $used,
                        'percent_used' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
                    ];
                }
            }
            // macOS
            elseif (PHP_OS_FAMILY === 'Darwin') {
                $total = $this->runCommandWithTimeout('sysctl -n hw.memsize', 5);
                if ($total) {
                    $total = (int) trim($total);
                }

                // vm_stat returns pages (usually 4096 bytes)
                $vmStat = $this->runCommandWithTimeout('vm_stat', 5);
                if ($vmStat) {
                    preg_match('/Pages free:\s+(\d+)\./', $vmStat, $freeMatches);
                    preg_match('/Pages speculative:\s+(\d+)\./', $vmStat, $specMatches);

                    if ($total > 0 && isset($freeMatches[1])) {
                        $pageSize = 4096;
                        $freePages = (int) $freeMatches[1] + (isset($specMatches[1]) ? (int) $specMatches[1] : 0);
                        $free = $freePages * $pageSize;
                        $used = $total - $free;

                        $memory = [
                            'total' => $total,
                            'free' => $free,
                            'used' => $used,
                            'percent_used' => round(($used / $total) * 100, 2),
                        ];
                    }
                }
            }
        } catch (Throwable) {
            // Fail silently
        }

        return $memory;
    }

    protected function getCpuUsage(): ?float
    {
        try {
            // Linux - use load average (more reliable than calculating from /proc/stat)
            if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/loadavg')) {
                $load = file_get_contents('/proc/loadavg');
                $load = explode(' ', $load);

                return isset($load[0]) ? (float) $load[0] : null;
            }

            // macOS
            if (PHP_OS_FAMILY === 'Darwin') {
                $output = $this->runCommandWithTimeout('top -l 1 | grep "CPU usage"', 5);
                if ($output && preg_match('/(\d+\.\d+)% idle/', $output, $matches)) {
                    return round(100 - (float) $matches[1], 2);
                }
            }

            // Windows
            if (PHP_OS_FAMILY === 'Windows') {
                $output = $this->runCommandWithTimeout('wmic cpu get loadpercentage', 5);
                if ($output && preg_match('/\d+/', $output, $matches)) {
                    return (float) $matches[0];
                }
            }
        } catch (Throwable) {
            // Fail silently
        }

        return null;
    }

    protected function getUptime(): ?int
    {
        try {
            // Linux
            if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/uptime')) {
                $uptime = file_get_contents('/proc/uptime');
                $uptime = explode(' ', $uptime)[0];

                return (int) $uptime;
            }

            // macOS / BSD
            if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
                $boottime = $this->runCommandWithTimeout('sysctl -n kern.boottime', 5);
                if ($boottime && preg_match('/sec = (\d+)/', $boottime, $matches)) {
                    return time() - (int) $matches[1];
                }
            }

            // Windows
            if (PHP_OS_FAMILY === 'Windows') {
                $statistics = $this->runCommandWithTimeout('net statistics workstation', 5);
                if ($statistics && preg_match('/Statistics since (.*)/', $statistics, $matches)) {
                    return time() - strtotime($matches[1]);
                }
            }
        } catch (Throwable) {
            // Fail silently
        }

        return null;
    }

    protected function getDiskSpace(): array
    {
        $primary = ['total' => null, 'free' => null, 'used' => null, 'percent_used' => null];
        $disks = [];

        // 1. Get primary disk info (backward compatibility)
        try {
            $path = base_path();
            $total = disk_total_space($path);
            $free = disk_free_space($path);
            $used = $total - $free;

            $primary = [
                'total' => $total,
                'free' => $free,
                'used' => $used,
                'percent_used' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
            ];
        } catch (Throwable) {
            // Leave as nulls
        }

        // 2. Get all mounted filesystems
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = $this->runCommandWithTimeout('wmic logicaldisk get Caption,FreeSpace,Size /format:csv', 5);
                if ($output) {
                    $lines = explode("\n", trim($output));
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) {
                            continue;
                        }

                        $parts = str_getcsv($line);
                        // Skip headers or invalid lines
                        if (isset($parts[1]) && $parts[1] === 'Caption') {
                            continue;
                        }

                        // Node, Caption, FreeSpace, Size
                        if (count($parts) >= 4) {
                            $model_total = (float) $parts[3];
                            $model_free = (float) $parts[2];
                            $model_used = $model_total - $model_free;
                            $disks[] = [
                                'path' => $parts[1],
                                'total' => $model_total,
                                'free' => $model_free,
                                'used' => $model_used,
                                'percent_used' => $model_total > 0 ? round(($model_used / $model_total) * 100, 2) : 0,
                            ];
                        }
                    }
                }
            } else {
                // Linux / macOS (Use -k for 1024-byte blocks, -P for POSIX portability)
                $output = $this->runCommandWithTimeout('df -kP', 5);
                if ($output) {
                    $lines = explode("\n", trim($output));
                    array_shift($lines); // Remove header

                    foreach ($lines as $line) {
                        $parts = preg_split('/\s+/', trim($line));

                        if (count($parts) >= 6) {
                            $model_total = (float) $parts[1] * 1024;
                            $model_used = (float) $parts[2] * 1024;
                            $model_free = (float) $parts[3] * 1024;
                            $mount = implode(' ', array_slice($parts, 5));

                            $disks[] = [
                                'path' => $mount,
                                'total' => $model_total,
                                'free' => $model_free,
                                'used' => $model_used,
                                'percent_used' => $model_total > 0 ? round(($model_used / $model_total) * 100, 2) : 0,
                            ];
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Fail silently
        }

        return array_merge($primary, ['disks' => $disks]);
    }

    protected function getNodeVersion(): ?string
    {
        try {
            return $this->runCommandWithTimeout('node --version 2>/dev/null', 5);
        } catch (Throwable) {
            return null;
        }
    }

    protected function getNpmVersion(): ?string
    {
        try {
            return $this->runCommandWithTimeout('npm --version 2>/dev/null', 5);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The dependency checks shell out for up to 30 s each, so their results are
     * kept for `status.dependency_cache_ttl` minutes. The key carries the lock
     * file's mtime: a deploy that changes the lock file gets fresh numbers on
     * the next push. Null (check failed) is never stored, so it is retried.
     */
    protected function cachedCount(string $name, string $lockFile, callable $check): ?int
    {
        $ttl = (int) config('log-shipper.status.dependency_cache_ttl', 60);

        if ($ttl <= 0) {
            return $check();
        }

        $lockPath = base_path($lockFile);
        $key = 'log_shipper:status:' . $name . ':' . md5(base_path() . '|' . (@filemtime($lockPath) ?: 0));

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            return $check();
        }

        if ($cached !== null) {
            return (int) $cached;
        }

        $value = $check();

        if ($value !== null) {
            try {
                Cache::put($key, $value, now()->addMinutes($ttl));
            } catch (Throwable) {
                // Cache down: the value is still sent, only the next push runs the check again
            }
        }

        return $value;
    }

    /**
     * Null when the check could not run or its output made no sense, so the
     * server can tell "unknown" apart from "none".
     */
    protected function getComposerOutdatedCount(): ?int
    {
        try {
            $basePath = base_path();

            if (!is_dir($basePath) || !is_readable($basePath)) {
                return null;
            }

            $output = $this->runCommandWithTimeout('exec composer outdated --direct --format=json 2>/dev/null', 30, $basePath);

            if (!$output) {
                return null;
            }

            $data = json_decode($output, true);

            return isset($data['installed']) && is_array($data['installed']) ? count($data['installed']) : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function getNpmOutdatedCount(): ?int
    {
        try {
            $basePath = base_path();

            if (!file_exists($basePath . '/package.json')) {
                return 0;
            }

            $output = $this->runCommandWithTimeout('exec npm outdated --json 2>/dev/null', 30, $basePath);

            if (!$output) {
                return null;
            }

            $data = json_decode($output, true);

            // npm prints {"error": {...}} as JSON when it fails
            return is_array($data) && !isset($data['error']) ? count($data) : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function getComposerAuditCount(): ?int
    {
        try {
            $basePath = base_path();

            if (!is_dir($basePath) || !is_readable($basePath)) {
                return null;
            }

            $output = $this->runCommandWithTimeout('exec composer audit --format=json 2>/dev/null', 30, $basePath);

            if (!$output) {
                return null;
            }

            $data = json_decode($output, true);

            if (isset($data['advisories']) && is_array($data['advisories'])) {
                return count($data['advisories']);
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function getNpmAuditCount(): ?int
    {
        try {
            $basePath = base_path();

            if (!file_exists($basePath . '/package.json')) {
                return 0;
            }

            $output = $this->runCommandWithTimeout('exec npm audit --json 2>/dev/null', 30, $basePath);

            if (!$output) {
                return null;
            }

            $data = json_decode($output, true);

            if (isset($data['metadata']['vulnerabilities']) && is_array($data['metadata']['vulnerabilities'])) {
                $vulns = $data['metadata']['vulnerabilities'];

                return (int) (($vulns['info'] ?? 0) +
                       ($vulns['low'] ?? 0) +
                       ($vulns['moderate'] ?? 0) +
                       ($vulns['high'] ?? 0) +
                       ($vulns['critical'] ?? 0));
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run a shell command with a timeout to prevent hanging.
     */
    protected function runCommandWithTimeout(string $command, int $timeoutSeconds, ?string $cwd = null): ?string
    {
        return $this->commandRunner()->run($command, $timeoutSeconds, $cwd);
    }

    protected function commandRunner(): CommandRunner
    {
        return $this->runner ??= new CommandRunner;
    }

    protected function osInfoCollector(): OsInfoCollector
    {
        return new OsInfoCollector($this->commandRunner());
    }

    protected function hostInfoCollector(): HostInfoCollector
    {
        return new HostInfoCollector;
    }

    protected function systemUpdatesCollector(): SystemUpdatesCollector
    {
        return new SystemUpdatesCollector($this->commandRunner());
    }

    protected function getQueueMetrics(): array
    {
        try {
            $connection = config('log-shipper.queue_connection', 'default');
            $queue = config('log-shipper.queue_name', 'default');

            // Same mapping as the commands: 'default' means the app's default connection
            if ($connection === 'default') {
                $connection = config('queue.default');
            }

            return [
                'size' => Queue::connection($connection)->size($queue),
                'connection' => $connection,
                'queue' => $queue,
            ];
        } catch (Throwable) {
            return ['error' => 'Could not fetch queue metrics'];
        }
    }

    protected function getDatabaseMetrics(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $end = microtime(true);

            return [
                'status' => 'connected',
                'latency_ms' => round(($end - $start) * 1000, 2),
            ];
        } catch (Throwable $e) {
            // The raw message can carry DB user and host; class plus codes is enough to diagnose.
            $error = get_class($e);

            if ($e instanceof PDOException) {
                $error .= ' SQLSTATE[' . ($e->errorInfo[0] ?? $e->getCode()) . ']';

                if (isset($e->errorInfo[1])) {
                    $error .= ' [' . $e->errorInfo[1] . ']';
                }
            }

            return [
                'status' => 'failed',
                'error' => $error,
            ];
        }
    }

    protected function getFilesizeMetrics(): array
    {
        $files = config('log-shipper.status.monitored_files', []);
        $metrics = [];

        foreach ($files as $file) {
            if (file_exists($file)) {
                $metrics[basename($file)] = filesize($file);
            } else {
                $metrics[basename($file)] = -1; // File not found
            }
        }

        return $metrics;
    }

    protected function getFoldersizeMetrics(): array
    {
        $folders = config('log-shipper.status.monitored_folders', []);
        $metrics = [];

        foreach ($folders as $folder) {
            if (is_dir($folder)) {
                $metrics[basename($folder)] = $this->calculateFolderSize($folder);
            } else {
                $metrics[basename($folder)] = -1; // Folder not found
            }
        }

        return $metrics;
    }

    protected function calculateFolderSize(string $path): int
    {
        $totalSize = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $totalSize += $file->getSize();
                }
            }
        } catch (Throwable) {
            // If we can't read the folder, return 0
            return 0;
        }

        return $totalSize;
    }

    protected function ship(array $payload): void
    {
        $endpoint = config('log-shipper.status.endpoint') ?? config('log-shipper.api_endpoint');
        $apiKey = config('log-shipper.api_key');

        if (empty($endpoint) || empty($apiKey)) {
            return;
        }

        if (!config('log-shipper.status.endpoint')) {
            $endpoint = $this->deriveStatusEndpoint($endpoint);
        }

        // Same rule as the log jobs: never send the project key over plain http in production
        if (!$this->validateEndpoint($endpoint)) {
            $this->reportShipFailure($endpoint, 'endpoint refused, https required in production');

            return;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Project-Key' => $apiKey,
                ])
                ->post($endpoint, $payload);

            if ($response->failed()) {
                $this->reportShipFailure($endpoint, 'HTTP ' . $response->status());
            }
        } catch (Throwable $e) {
            $this->reportShipFailure($endpoint, $e->getMessage());
        }
    }

    /**
     * The log server takes logs at .../api/ingest and status at .../api/stats.
     * Other servers keep the old behaviour of appending /status.
     */
    protected function deriveStatusEndpoint(string $endpoint): string
    {
        $endpoint = rtrim($endpoint, '/');

        if (str_ends_with($endpoint, '/ingest')) {
            return substr($endpoint, 0, -strlen('/ingest')) . '/stats';
        }

        return $endpoint . '/status';
    }

    protected function reportShipFailure(string $endpoint, string $reason): void
    {
        try {
            // log_shipper_failure keeps the handler from shipping this line (no loop)
            Log::warning('[Log Shipper] Status push failed: ' . $reason, [
                'endpoint' => $endpoint,
                'log_shipper_failure' => $reason,
            ]);
        } catch (Throwable) {
            // Logging must never break the status job
        }
    }

    /**
     * Get the installed version of the log shipper package.
     */
    protected function getPackageVersion(): string
    {
        try {
            if (class_exists(InstalledVersions::class)) {
                $version = InstalledVersions::getVersion('adminintelligence/laravel-log-shipper');

                return $version ?? 'unknown';
            }
        } catch (Throwable) {
            // Fall through to unknown
        }

        return 'unknown';
    }
}
