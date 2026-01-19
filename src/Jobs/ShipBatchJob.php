<?php

namespace AdminIntelligence\LogShipper\Jobs;

use AdminIntelligence\LogShipper\Jobs\Concerns\ShipsLogs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

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

            if (!$response->successful()) {
                $response->throw();
            }

            $this->resetCircuitBreaker();
        } catch (\Throwable $e) {
            $this->recordFailure($e);
            throw $e;
        }
    }
}
