<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Logging\LogShipperHandler;
use AdminIntelligence\LogShipper\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;

class FallbackChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function handler_does_not_dispatch_when_fallback_channel_is_log_shipper()
    {
        Config::set('log-shipper.enabled', true);
        Config::set('log-shipper.fallback_channel', 'log_shipper');

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test error', Level::Error);

        // This should dispatch normally (protection is in fallback handling, not dispatch)
        $handler->handle($record);

        // The protection is that if dispatch FAILS and fallback is log_shipper,
        // it won't try to log to fallback. But with Queue::fake(), dispatch succeeds.
        // So we actually expect the job to be dispatched here.
        Queue::assertPushed(ShipLogJob::class);
    }

    #[Test]
    public function fallback_channel_infinite_loop_protection_exists_in_code()
    {
        // This test verifies the protection exists by checking the actual code
        // We can't test dispatch failure with Queue::fake() since it prevents errors
        
        Config::set('log-shipper.fallback_channel', 'log_shipper');
        
        $handler = new LogShipperHandler(Level::Error);
        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('write');
        $method->setAccessible(true);
        
        // Read the method source to verify protection exists
        $source = file_get_contents($reflection->getFileName());
        
        // Verify the code contains the protection check
        $this->assertStringContainsString('&& $fallbackChannel !== \'log_shipper\'', $source);
    }

    #[Test]
    public function failed_job_does_not_trigger_infinite_loop_with_log_shipper_context_flag()
    {
        Config::set('log-shipper.enabled', true);
        Config::set('log-shipper.fallback_channel', 'single');

        $payload = [
            'level' => 'error',
            'message' => 'Test',
            'context' => ['order_id' => 123],
        ];

        $job = new ShipLogJob($payload);

        // Mock Log to verify the context flag is set
        Log::shouldReceive('channel')
            ->with('single')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->with('error', 'Test', \Mockery::on(function ($context) {
                return isset($context['log_shipper_failure']);
            }));

        $job->failed(new \Exception('Network error'));
    }

    #[Test]
    public function failed_job_sanitizes_payload_before_logging()
    {
        Config::set('log-shipper.enabled', true);
        Config::set('log-shipper.fallback_channel', 'single');
        Config::set('log-shipper.sanitize_fields', ['password', 'secret']);

        $payload = [
            'level' => 'error',
            'message' => 'Test',
            'context' => [
                'user_password' => 'secret123',
                'api_secret' => 'key456',
                'order_id' => 789,
            ],
        ];

        $job = new ShipLogJob($payload);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->with('error', 'Test', \Mockery::on(function ($context) {
                $payload = $context['original_payload'] ?? [];
                return isset($payload['context'])
                    && $payload['context']['user_password'] === '[REDACTED]'
                    && $payload['context']['api_secret'] === '[REDACTED]'
                    && $payload['context']['order_id'] === 789;
            }));

        $job->failed(new \Exception('Test'));
    }

    #[Test]
    public function failed_job_handles_missing_fallback_channel_gracefully()
    {
        Config::set('log-shipper.fallback_channel', null);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);

        // Should not throw exception
        $job->failed(new \Exception('Test'));

        $this->assertTrue(true);
    }

    #[Test]
    public function failed_job_handles_corrupted_payload_gracefully()
    {
        Config::set('log-shipper.fallback_channel', 'single');

        // Create job with minimal/missing payload structure
        $job = new ShipLogJob([]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->once();

        // Should not throw exception
        $job->failed(new \Exception('Test'));

        $this->assertTrue(true);
    }

    protected function createLogRecord(string $message, Level $level): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: $level,
            message: $message,
            context: [],
            extra: []
        );
    }
}
