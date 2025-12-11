<?php

namespace AdminIntelligence\LogShipper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShipLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public function tries(): int
    {
        $tries = config('log-shipper.retries');
        
        return $tries ? (int) $tries : 3;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        $backoff = config('log-shipper.backoff');
        
        return is_array($backoff) ? $backoff : [2, 5, 10];
    }

    /**
     * The job may be attempted for up to 15 seconds.
     */
    public int $timeout = 15;

    /**
     * HTTP request timeout in seconds.
     * Must be less than job timeout to allow for cleanup.
     */
    protected int $httpTimeout = 10;

    public function __construct(
        protected array $payload
    ) {}

    public function handle(): void
    {
        $endpoint = config('log-shipper.api_endpoint');
        $apiKey = config('log-shipper.api_key');

        if (empty($endpoint) || empty($apiKey)) {
            // Silently fail - we don't want to log about failing to log
            return;
        }

        try {
            Http::timeout($this->httpTimeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Project-Key' => $apiKey,
                ])
                ->post($endpoint, $this->payload)
                ->throw();

            if (config('log-shipper.circuit_breaker.enabled', false)) {
                Cache::forget('log_shipper_failures');
            }

            // We intentionally don't check the response.
            // If it fails, it fails. Life goes on. Probably.
        } catch (\Throwable $e) {
            $this->recordFailure($e);
            
            // Rethrow the exception so the queue worker knows to retry
            throw $e;
        }
    }

    protected function recordFailure(\Throwable $e): void
    {
        if (!config('log-shipper.circuit_breaker.enabled', false)) {
            return;
        }

        try {
            $failures = Cache::increment('log_shipper_failures');
            $threshold = config('log-shipper.circuit_breaker.failure_threshold', 5);

            if ($failures >= $threshold) {
                $duration = config('log-shipper.circuit_breaker.duration', 300);
                Cache::put('log_shipper_dead_until', now()->addSeconds($duration), $duration);
            }
        } catch (\Throwable) {
            // If the cache is down, we can't record the failure.
            // But we shouldn't crash the job because of it.
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        $fallbackChannel = config('log-shipper.fallback_channel');

        if (empty($fallbackChannel)) {
            return;
        }

        // Prevent infinite loops: Disable log shipping while writing to fallback
        config(['log-shipper.enabled' => false]);

        try {
            $level = $this->payload['level'] ?? 'error';
            $message = $this->payload['message'] ?? 'Unknown error';
            $context = $this->payload['context'] ?? [];
            
            // Add metadata about the failure
            $context['log_shipper_failure'] = $exception?->getMessage();
            $context['original_payload'] = $this->payload;

            // Ensure we don't trigger the log shipper handler again
            // by explicitly using a channel that doesn't include it, if possible.
            // But since we can't know the user's channel config, we rely on the
            // context flag 'log_shipper_failure' which we check in the handler.
            Log::channel($fallbackChannel)->log($level, $message, $context);
        } catch (\Throwable) {
            // If the fallback fails, we really are doomed.
        } finally {
             // Restore config just in case, though in a job it might not matter much
             config(['log-shipper.enabled' => true]);
        }
    }
}
