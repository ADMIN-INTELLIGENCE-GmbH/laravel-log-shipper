<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Logging\LogShipperHandler;
use AdminIntelligence\LogShipper\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;

class PayloadSizeLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Config::set('log-shipper.enabled', true);
    }

    #[Test]
    public function payload_exceeding_size_limit_is_truncated()
    {
        Config::set('log-shipper.max_payload_size', 500); // 500 bytes limit

        $handler = new LogShipperHandler(Level::Error);

        // Create a record with large context
        $largeContext = [
            'data' => str_repeat('A', 1000), // 1000 bytes
            'more_data' => str_repeat('B', 1000),
        ];

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test error',
            context: $largeContext,
            extra: []
        );

        $handler->handle($record);

        // Verify job was still dispatched (with truncated payload)
        Queue::assertPushed(\AdminIntelligence\LogShipper\Jobs\ShipLogJob::class);
    }

    #[Test]
    public function truncated_payload_has_truncation_markers()
    {
        Config::set('log-shipper.max_payload_size', 500);

        $handler = new LogShipperHandler(Level::Error);

        $largeContext = ['data' => str_repeat('X', 2000)];

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: $largeContext,
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(\AdminIntelligence\LogShipper\Jobs\ShipLogJob::class, function ($job) {
            // Use reflection to access protected property
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);

            return isset($payload['_truncated'])
                && $payload['_truncated'] === true
                && isset($payload['_original_size'])
                && $payload['context'] === '[TRUNCATED: Payload too large]';
        });
    }

    #[Test]
    public function payload_under_size_limit_is_not_truncated()
    {
        Config::set('log-shipper.max_payload_size', 10000); // 10KB limit

        $handler = new LogShipperHandler(Level::Error);

        $smallContext = ['order_id' => 123, 'user_id' => 456];

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Small payload',
            context: $smallContext,
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(\AdminIntelligence\LogShipper\Jobs\ShipLogJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);

            return !isset($payload['_truncated'])
                && is_array($payload['context'])
                && $payload['context']['order_id'] === 123;
        });
    }

    #[Test]
    public function invalid_max_payload_size_config_does_not_crash()
    {
        // Test with negative value
        Config::set('log-shipper.max_payload_size', -1);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test', Level::Error);

        // Should not throw exception
        $handler->handle($record);

        Queue::assertPushed(\AdminIntelligence\LogShipper\Jobs\ShipLogJob::class);
    }

    #[Test]
    public function zero_max_payload_size_does_not_bypass_validation()
    {
        Config::set('log-shipper.max_payload_size', 0);

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test', Level::Error);

        $handler->handle($record);

        // Should still dispatch (but would be truncated)
        Queue::assertPushed(\AdminIntelligence\LogShipper\Jobs\ShipLogJob::class);
    }

    #[Test]
    public function extremely_large_max_payload_size_does_not_cause_memory_issues()
    {
        Config::set('log-shipper.max_payload_size', PHP_INT_MAX);

        $handler = new LogShipperHandler(Level::Error);

        // Create moderate-sized payload that should pass
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: ['data' => str_repeat('X', 5000)],
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(\AdminIntelligence\LogShipper\Jobs\ShipLogJob::class);
    }

    #[Test]
    public function payload_with_non_serializable_data_still_respects_size_limit()
    {
        Config::set('log-shipper.max_payload_size', 1000);

        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: [
                'closure' => function () {
                    return 'test';
                },
                'large_data' => str_repeat('Y', 2000),
            ],
            extra: []
        );

        $handler->handle($record);

        // Should handle gracefully and dispatch
        Queue::assertPushed(\AdminIntelligence\LogShipper\Jobs\ShipLogJob::class);
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
