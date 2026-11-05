<?php

namespace AdminIntelligence\LogShipper\Jobs;

use AdminIntelligence\LogShipper\Jobs\Concerns\ShipsLogs;
use AdminIntelligence\LogShipper\Utils\Sanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShipBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ShipsLogs;

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

        if (empty($apiKey) || !$this->validateEndpoint($endpoint)) {
            return;
        }

        try {
            $response = Http::timeout($this->httpTimeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Project-Key' => $apiKey,
                ])
                ->post($endpoint, $this->batch);

            // A rejected request stays rejected: fail at once (failed() still runs), no retry
            if ($response->clientError() && $response->status() !== 429) {
                // Bad key or endpoint (401/403/404) hits every log: let the breaker trip. Payload errors (413/422) stay out.
                if (in_array($response->status(), [401, 403, 404], true)) {
                    $this->recordFailure($response->toException());
                }

                $this->fail($response->toException());

                return;
            }

            if (!$response->successful()) {
                $response->throw();
            }

            $this->resetCircuitBreaker();
        } catch (\Throwable $e) {
            $this->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Handle a job failure: keep every log of the batch in the fallback channel.
     */
    public function failed(?\Throwable $exception): void
    {
        $fallbackChannel = config('log-shipper.fallback_channel');

        // Prevent infinite loop if fallback channel is log_shipper
        if (empty($fallbackChannel) || $fallbackChannel === 'log_shipper') {
            return;
        }

        // Prevent infinite loops: Disable log shipping while writing to fallback, restore the previous value after
        $wasEnabled = config('log-shipper.enabled');
        config(['log-shipper.enabled' => false]);

        try {
            $sanitizeFields = config('log-shipper.sanitize_fields', []);

            foreach ($this->batch as $payload) {
                try {
                    $payload = is_array($payload) ? $payload : [];
                    $context = $payload['context'] ?? [];
                    $context = is_array($context) ? $context : [];
                    $context['log_shipper_failure'] = $exception?->getMessage() ?? 'batch failed';
                    $context['original_payload'] = Sanitizer::sanitize($payload, $sanitizeFields);

                    Log::channel($fallbackChannel)->log($payload['level'] ?? 'error', $payload['message'] ?? 'Unknown error', $context);
                } catch (\Throwable) {
                    // One bad entry must not cost the rest of the batch
                }
            }
        } finally {
            config(['log-shipper.enabled' => $wasEnabled]);
        }
    }
}
