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
    public int $timeout = 30;

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
            'instance_id' => gethostname(),
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

        return $metrics;
    }

    protected function getSystemMetrics(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'uptime' => $this->getUptime(),
            'disk_space' => $this->getDiskSpace(),
        ];
    }

    protected function getUptime(): ?int
    {
        // Try to get uptime from /proc/uptime on Linux
        if (is_readable('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $uptime = explode(' ', $uptime)[0];
            return (int)$uptime;
        }
        return null;
    }

    protected function getDiskSpace(): array
    {
        $diskMetrics = [];

        try {
            // Get disk space for the root filesystem
            // Most apps run on a single filesystem, so checking root covers everything
            $path = '/';
            
            if (is_dir($path)) {
                $total = disk_total_space($path);
                $free = disk_free_space($path);
                $used = $total - $free;
                
                $diskMetrics = [
                    'total' => $total,
                    'free' => $free,
                    'used' => $used,
                    'percent_used' => round(($used / $total) * 100, 2),
                ];
            }
        } catch (\Throwable) {
            // If we can't get disk space, just skip it
        }

        return $diskMetrics;
    }

    protected function getQueueMetrics(): array
    {
        try {
            $queueSize = Queue::size();
            
            // Subtract 1 to exclude this status job itself from the count
            // (since we're running as a queued job, we're in the queue)
            $adjustedSize = max(0, $queueSize - 1);
            
            return [
                'size' => $adjustedSize,
                'connection' => config('queue.default'),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function getDatabaseMetrics(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $duration = microtime(true) - $start;

            return [
                'status' => 'connected',
                'latency_ms' => round($duration * 1000, 2),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'disconnected',
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getCacheMetrics(): array
    {
        try {
            $store = Cache::getStore();
            return [
                'driver' => config('cache.default'),
                // 'ping' => $store->get('ping') // Not all stores support this easily without side effects
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
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

    protected function ship(array $payload): void
    {
        $endpoint = $this->resolveEndpoint();
        $apiKey = config('log-shipper.api_key');

        if (empty($endpoint) || empty($apiKey)) {
            return;
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
            // Fail silently
        }
    }

    protected function resolveEndpoint(): ?string
    {
        $statusEndpoint = config('log-shipper.status.endpoint');

        if (!empty($statusEndpoint)) {
            return $statusEndpoint;
        }

        // Fallback: Try to construct from main endpoint
        $apiEndpoint = config('log-shipper.api_endpoint');
        if (empty($apiEndpoint)) {
            return null;
        }

        // If api_endpoint is https://example.com/api/ingest
        // We want https://example.com/api/stats or similar?
        // The user said "the endpoint will be /stats by default".
        // Let's assume we replace the last segment if it looks like an endpoint, or just append /stats to the base.
        
        // Safest bet based on user request:
        // If we have a base URL, append /stats.
        // But we only have the full ingest URL.
        
        // Let's try to parse the URL.
        $parts = parse_url($apiEndpoint);
        if (!isset($parts['scheme']) || !isset($parts['host'])) {
            return null;
        }

        $baseUrl = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $baseUrl .= ':' . $parts['port'];
        }

        // If the path is /api/ingest, maybe they want /api/stats?
        // Or just /stats at root?
        // "the endpoint will be /stats by default"
        
        // I'll assume root /stats for now as it's the most literal interpretation.
        // return $baseUrl . '/stats';
        
        // Actually, usually these things are grouped.
        // If I have https://app.logger.com/api/v1/logs
        // I probably want https://app.logger.com/api/v1/stats
        
        // Let's try to be smart: replace the last segment with 'stats'
        // https://.../ingest -> https://.../stats
        // https://.../logs -> https://.../stats
        
        $path = $parts['path'] ?? '';
        if (empty($path) || $path === '/') {
            return $baseUrl . '/stats';
        }
        
        $segments = explode('/', trim($path, '/'));
        array_pop($segments);
        $segments[] = 'stats';
        
        return $baseUrl . '/' . implode('/', $segments);
    }
}
