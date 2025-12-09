<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Logging\LogShipperHandler;
use AdminIntelligence\LogShipper\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;

class LogShipperHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_dispatches_ship_log_job_when_enabled(): void
    {
        config(['log-shipper.enabled' => true]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test error message', Level::Error);

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class);
    }

    #[Test]
    public function it_does_not_dispatch_when_disabled(): void
    {
        config(['log-shipper.enabled' => false]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test error message', Level::Error);

        $handler->handle($record);

        Queue::assertNotPushed(ShipLogJob::class);
    }

    #[Test]
    public function it_does_not_handle_logs_below_configured_level(): void
    {
        config(['log-shipper.enabled' => true]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test debug message', Level::Debug);

        // The handler's isHandling method should return false for lower levels
        $this->assertFalse($handler->isHandling($record));
    }

    #[Test]
    public function it_handles_logs_at_configured_level(): void
    {
        config(['log-shipper.enabled' => true]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test error message', Level::Error);

        $this->assertTrue($handler->isHandling($record));
    }

    #[Test]
    public function it_handles_logs_above_configured_level(): void
    {
        config(['log-shipper.enabled' => true]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test critical message', Level::Critical);

        $this->assertTrue($handler->isHandling($record));
    }

    #[Test]
    public function it_sanitizes_password_fields(): void
    {
        config(['log-shipper.enabled' => true]);
        config(['log-shipper.sanitize_fields' => ['password']]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Login attempt', Level::Error, [
            'username' => 'john',
            'password' => 'secret123',
        ]);

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return $payload['context']['password'] === '[REDACTED]'
                && $payload['context']['username'] === 'john';
        });
    }

    #[Test]
    public function it_sanitizes_nested_sensitive_fields(): void
    {
        config(['log-shipper.enabled' => true]);
        config(['log-shipper.sanitize_fields' => ['password', 'secret']]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('API call failed', Level::Error, [
            'request' => [
                'headers' => [
                    'Authorization-Secret' => 'secret-key',
                ],
                'body' => [
                    'user_password' => 'my-password',
                ],
            ],
        ]);

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return $payload['context']['request']['headers']['Authorization-Secret'] === '[REDACTED]'
                && $payload['context']['request']['body']['user_password'] === '[REDACTED]';
        });
    }

    #[Test]
    public function it_includes_log_level_in_payload(): void
    {
        config(['log-shipper.enabled' => true]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test message', Level::Error);

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return $payload['level'] === 'error';
        });
    }

    #[Test]
    public function it_includes_message_in_payload(): void
    {
        config(['log-shipper.enabled' => true]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Something went wrong', Level::Error);

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return $payload['message'] === 'Something went wrong';
        });
    }

    #[Test]
    public function it_includes_datetime_in_payload(): void
    {
        config(['log-shipper.enabled' => true]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test', Level::Error);

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return isset($payload['datetime']) && !empty($payload['datetime']);
        });
    }

    #[Test]
    public function it_includes_channel_in_payload(): void
    {
        config(['log-shipper.enabled' => true]);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test', Level::Error);

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return $payload['channel'] === 'test-channel';
        });
    }

    #[Test]
    public function it_dispatches_to_configured_queue(): void
    {
        config(['log-shipper.enabled' => true]);
        config(['log-shipper.queue_connection' => 'redis']);
        config(['log-shipper.queue_name' => 'logs']);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test', Level::Error);

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            return $job->queue === 'logs';
        });
    }

    /**
     * Create a LogRecord for testing.
     */
    protected function createLogRecord(string $message, Level $level, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test-channel',
            level: $level,
            message: $message,
            context: $context,
            extra: [],
        );
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
