<?php

namespace AdminIntelligence\LogShipper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

class ShipStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120; // Increased to handle composer/npm commands

    public function handle(): void
    {
        if (!config('log-shipper.status.enabled', false)) {
            return;
        }

        $payload = $this->collectMetrics();
        $this->ship($payload);
    }

    protected function collectMetrics(): array
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

        if ($enabledMetrics['cache'] ?? false) {
            $metrics['cache'] = $this->getCacheMetrics();
        }

        if ($enabledMetrics['filesize'] ?? true) {
            $metrics['filesize'] = $this->getFilesizeMetrics();
        }

        if ($enabledMetrics['foldersize'] ?? true) {
            $metrics['foldersize'] = $this->getFoldersizeMetrics();
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
        if (config('log-shipper.status.metrics.node_npm', false)) {
            $metrics['node_version'] = $this->getNodeVersion();
            $metrics['npm_version'] = $this->getNpmVersion();
        }

        if (config('log-shipper.status.metrics.dependency_checks', false)) {
            $metrics['composer_outdated'] = $this->getComposerOutdatedCount();
            $metrics['npm_outdated'] = $this->getNpmOutdatedCount();
        }

        if (config('log-shipper.status.metrics.security_audits', false)) {
            $metrics['composer_audit'] = $this->getComposerAuditCount();
            $metrics['npm_audit'] = $this->getNpmAuditCount();
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
            // Fail silently
        }

        return null;
    }

    protected function getDiskSpace(): array
    {
        try {
            $path = base_path();
            $total = disk_total_space($path);
            $free = disk_free_space($path);
            $used = $total - $free;

            return [
                'total' => $total,
                'free' => $free,
                'used' => $used,
                'percent_used' => $total > 0 ? round(($used / $total) * 100, 2) : 0,
            ];
        } catch (\Throwable) {
            return ['total' => null, 'free' => null, 'used' => null, 'percent_used' => null];
        }
    }

    protected function getNodeVersion(): ?string
    {
        try {
            return $this->runCommandWithTimeout('node --version 2>/dev/null', 5);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getNpmVersion(): ?string
    {
        try {
            return $this->runCommandWithTimeout('npm --version 2>/dev/null', 5);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getComposerOutdatedCount(): int
    {
        try {
            $basePath = base_path();
            
            if (!is_dir($basePath) || !is_readable($basePath)) {
                return 0;
            }
            
            $escapedPath = escapeshellarg($basePath);
            $output = $this->runCommandWithTimeout("cd {$escapedPath} && composer outdated --direct --format=json 2>/dev/null", 30);
            
            if (!$output) {
                return 0;
            }

            $data = json_decode($output, true);
            
            if (!is_array($data)) {
                return 0;
            }
            
            return isset($data['installed']) && is_array($data['installed']) ? count($data['installed']) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function getNpmOutdatedCount(): int
    {
        try {
            $basePath = base_path();
            
            if (!file_exists($basePath . '/package.json')) {
                return 0;
            }

            $escapedPath = escapeshellarg($basePath);
            $output = $this->runCommandWithTimeout("cd {$escapedPath} && npm outdated --json 2>/dev/null", 30);
            
            if (!$output) {
                return 0;
            }

            $data = json_decode($output, true);
            return is_array($data) ? count($data) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function getComposerAuditCount(): int
    {
        try {
            $basePath = base_path();
            
            if (!is_dir($basePath) || !is_readable($basePath)) {
                return 0;
            }
            
            $escapedPath = escapeshellarg($basePath);
            $output = $this->runCommandWithTimeout("cd {$escapedPath} && composer audit --format=json 2>/dev/null", 30);
            
            if (!$output) {
                return 0;
            }

            $data = json_decode($output, true);
            
            if (isset($data['advisories']) && is_array($data['advisories'])) {
                return count($data['advisories']);
            }
            
            return 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function getNpmAuditCount(): int
    {
        try {
            $basePath = base_path();
            
            if (!file_exists($basePath . '/package.json')) {
                return 0;
            }

            $escapedPath = escapeshellarg($basePath);
            $output = $this->runCommandWithTimeout("cd {$escapedPath} && npm audit --json 2>/dev/null", 30);
            
            if (!$output) {
                return 0;
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
            
            return 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Run a shell command with a timeout to prevent hanging.
     */
    protected function runCommandWithTimeout(string $command, int $timeoutSeconds): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start = microtime(true);
        $output = '';

        while (true) {
            $elapsed = microtime(true) - $start;
            
            if ($elapsed > $timeoutSeconds) {
                proc_terminate($process);
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return null;
            }

            $read = stream_get_contents($pipes[1]);
            if ($read !== false && $read !== '') {
                $output .= $read;
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $output .= stream_get_contents($pipes[1]);
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return trim($output) ?: null;
            }

            usleep(100000); // 100ms
        }
    }



    protected function getQueueMetrics(): array
    {
        try {
            $connection = config('log-shipper.queue_connection', 'default');
            $queue = config('log-shipper.queue_name', 'default');
            
            return [
                'size' => Queue::connection($connection)->size($queue),
                'connection' => $connection,
                'queue' => $queue,
            ];
        } catch (\Throwable) {
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
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getCacheMetrics(): array
    {
        // Cache metrics are driver-specific and hard to generalize
        return [];
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
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $totalSize += $file->getSize();
                }
            }
        } catch (\Throwable) {
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

        // Append /status to the endpoint if using the default api_endpoint
        if (!config('log-shipper.status.endpoint')) {
            $endpoint = rtrim($endpoint, '/') . '/status';
        }

        try {
            Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Project-Key' => $apiKey,
                ])
                ->post($endpoint, $payload);
        } catch (\Throwable) {
            // Fail silently for status updates
        }
    }

    /**
     * Get the installed version of the log shipper package.
     */
    protected function getPackageVersion(): string
    {
        try {
            if (class_exists(\Composer\InstalledVersions::class)) {
                $version = \Composer\InstalledVersions::getVersion('adminintelligence/laravel-log-shipper');
                
                return $version ?? 'unknown';
            }
        } catch (\Throwable) {
            // Fall through to unknown
        }

        return 'unknown';
    }
}
