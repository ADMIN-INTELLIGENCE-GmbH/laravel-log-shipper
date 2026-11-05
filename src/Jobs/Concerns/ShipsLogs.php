<?php

namespace AdminIntelligence\LogShipper\Jobs\Concerns;

use Illuminate\Support\Facades\Cache;

trait ShipsLogs
{
    /**
     * Record a failure for circuit breaker tracking.
     */
    protected function recordFailure(\Throwable $e): void
    {
        if (!config('log-shipper.circuit_breaker.enabled', true)) {
            return;
        }

        try {
            $duration = config('log-shipper.circuit_breaker.duration', 300);

            // TTL on the counter: failures from an old outage expire instead of adding up forever
            Cache::add('log_shipper_failures', 0, $duration);
            $failures = Cache::increment('log_shipper_failures');
            $threshold = config('log-shipper.circuit_breaker.failure_threshold', 5);

            if ($failures >= $threshold) {
                Cache::put('log_shipper_dead_until', now()->addSeconds($duration), $duration);
            }
        } catch (\Throwable) {
            // If the cache is down, we can't record the failure.
            // But we shouldn't crash the job because of it.
        }
    }

    /**
     * Validate and sanitize the API endpoint.
     */
    protected function validateEndpoint(?string $endpoint): bool
    {
        if (empty($endpoint)) {
            return false;
        }

        // SECURITY: Enforce HTTPS in production environments
        if (config('app.env') === 'production' && !str_starts_with($endpoint, 'https://')) {
            return false;
        }

        return true;
    }

    /**
     * Reset circuit breaker on successful request.
     */
    protected function resetCircuitBreaker(): void
    {
        if (config('log-shipper.circuit_breaker.enabled', true)) {
            Cache::forget('log_shipper_failures');
        }
    }
}
