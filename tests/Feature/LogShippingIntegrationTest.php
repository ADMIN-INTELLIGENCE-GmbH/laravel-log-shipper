<?php

namespace AdminIntelligence\LogShipper\Tests\Feature;

use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

class LogShippingIntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Configure the log shipper channel
        $app['config']->set('logging.channels.log_shipper', [
            'driver' => 'custom',
            'via' => \AdminIntelligence\LogShipper\Logging\CreateCustomLogger::class,
            'level' => 'error',
        ]);

        $app['config']->set('logging.default', 'log_shipper');
    }

    #[Test]
    public function it_ships_logs_through_laravel_log_facade(): void
    {
        Queue::fake();

        Log::error('Application error occurred', ['code' => 500]);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return $payload['message'] === 'Application error occurred'
                && $payload['level'] === 'error'
                && $payload['context']['code'] === 500;
        });
    }

    #[Test]
    public function it_does_not_ship_logs_below_configured_level(): void
    {
        Queue::fake();

        Log::info('This is just info');
        Log::debug('This is debug');
        Log::warning('This is a warning');

        Queue::assertNotPushed(ShipLogJob::class);
    }

    #[Test]
    public function it_ships_critical_and_emergency_logs(): void
    {
        Queue::fake();

        Log::critical('Critical error');
        Log::emergency('System down');

        Queue::assertPushed(ShipLogJob::class, 2);
    }

    #[Test]
    public function it_sanitizes_sensitive_data_in_log_context(): void
    {
        Queue::fake();
        config(['log-shipper.sanitize_fields' => ['password', 'secret']]);

        Log::error('User login failed', [
            'email' => 'user@example.com',
            'password' => 'secret123',
            'secret_token' => 'abc123',
        ]);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return $payload['context']['email'] === 'user@example.com'
                && $payload['context']['password'] === '[REDACTED]'
                && $payload['context']['secret_token'] === '[REDACTED]';
        });
    }

    #[Test]
    public function it_sends_logs_to_http_endpoint_in_sync_mode(): void
    {
        Http::fake([
            'https://test-logs.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        config(['log-shipper.queue_connection' => 'sync']);

        Log::error('Sync test error');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://test-logs.example.com/api/ingest'
                && $request['message'] === 'Sync test error';
        });
    }

    #[Test]
    public function it_handles_endpoint_failure_gracefully(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Server error'], 500),
        ]);

        config(['log-shipper.queue_connection' => 'sync']);

        // Should not throw an exception
        Log::error('This should not crash');

        $this->assertTrue(true); // If we get here, error was handled gracefully
    }

    #[Test]
    public function it_does_not_log_when_disabled(): void
    {
        Queue::fake();
        config(['log-shipper.enabled' => false]);

        Log::error('This should not be shipped');

        Queue::assertNotPushed(ShipLogJob::class);
    }

    /**
     * Get the payload from a ShipLogJob.
     */
    protected function getJobPayload(ShipLogJob $job): array
    {
        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('payload');
        $property->setAccessible(true);

        return $property->getValue($job);
    }
}
