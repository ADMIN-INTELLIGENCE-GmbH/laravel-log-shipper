<?php

namespace AdminIntelligence\LogShipper\Jobs;

use AdminIntelligence\LogShipper\Jobs\Concerns\ShipsLogs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShipLogJob implements ShouldQueue
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
                ->post($endpoint, $this->payload);
                
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
     * Sanitize sensitive data recursively.
     */
    protected function sanitizeRecursive(array $data, array $sensitiveFields): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeRecursive($value, $sensitiveFields);
            } elseif ($this->isSensitiveKey($key, $sensitiveFields)) {
                $data[$key] = '[REDACTED]';
            }
        }

        return $data;
    }

    /**
     * Check if a key is sensitive.
     */
    protected function isSensitiveKey(string $key, array $sensitiveFields): bool
    {
        $lowerKey = strtolower($key);

        foreach ($sensitiveFields as $field) {
            if (str_contains($lowerKey, strtolower($field))) {
                return true;
            }
        }

        return false;
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
            
            // SECURITY FIX: Sanitize payload before logging to prevent exposure of sensitive data
            $sanitizedPayload = $this->payload;
            if (isset($sanitizedPayload['context'])) {
                $sensitiveFields = config('log-shipper.sanitize_fields', []);
                $sanitizedPayload['context'] = $this->sanitizeRecursive($sanitizedPayload['context'], $sensitiveFields);
            }
            $context['original_payload'] = $sanitizedPayload;

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
