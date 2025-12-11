<?php

namespace AdminIntelligence\LogShipper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ShipBatchJob implements ShouldQueue
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
     * The job may be attempted for up to 30 seconds (longer for batches).
     */
    public int $timeout = 30;

    /**
     * HTTP request timeout in seconds.
     */
    protected int $httpTimeout = 20;

    public function __construct(
        protected array $batch
    ) {}

    public function handle(): void
    {
        $endpoint = config('log-shipper.api_endpoint');
        $apiKey = config('log-shipper.api_key');

        if (empty($endpoint) || empty($apiKey)) {
            return;
        }

        try {
            Http::timeout($this->httpTimeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Project-Key' => $apiKey,
                ])
                ->post($endpoint, $this->batch)
                ->throw();

            if (config('log-shipper.circuit_breaker.enabled', false)) {
                Cache::forget('log_shipper_failures');
            }

        } catch (\Throwable $e) {
            $this->recordFailure($e);
            
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
        } catch (\Throwable $t) {
            // Ignore cache errors
        }
    }
}
