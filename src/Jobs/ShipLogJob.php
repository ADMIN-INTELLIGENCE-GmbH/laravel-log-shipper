<?php

namespace AdminIntelligence\LogShipper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShipLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Do not retry if the log server is down.
     * We don't want to clog the queue with failed log attempts.
     */
    public int $tries = 1;

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
                ->post($endpoint, $this->payload);

            // We intentionally don't check the response.
            // If it fails, it fails. Life goes on. Probably.
        } catch (\Throwable $e) {
            $this->handleFailure($e);
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        $fallbackChannel = config('log-shipper.fallback_channel');

        if (empty($fallbackChannel)) {
            return;
        }

        try {
            $level = $this->payload['level'] ?? 'error';
            $message = $this->payload['message'] ?? 'Unknown error';
            $context = $this->payload['context'] ?? [];
            
            // Add metadata about the failure
            $context['log_shipper_failure'] = $e->getMessage();
            $context['original_payload'] = $this->payload;

            Log::channel($fallbackChannel)->log($level, $message, $context);
        } catch (\Throwable) {
            // If the fallback fails, we really are doomed.
        }
    }

    /**
     * Handle a job failure.
     * Spoiler: We do nothing. That's the point.
     */
    public function failed(?\Throwable $exception): void
    {
        // Intentionally empty.
        // If we can't ship logs, we certainly can't ship logs about
        // failing to ship logs. That way lies madness.
    }
}
