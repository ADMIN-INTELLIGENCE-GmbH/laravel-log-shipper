<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Buffer\LogBufferInterface;
use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Logging\LogShipperHandler;
use AdminIntelligence\LogShipper\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;

class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('log-shipper.circuit_breaker.enabled', true);
        Config::set('log-shipper.circuit_breaker.failure_threshold', 2);
        Config::set('log-shipper.circuit_breaker.duration', 60);
    }

    #[Test]
    public function job_increments_failure_count_on_exception()
    {
        Http::fake(function () {
            throw new \Exception('Connection failed');
        });

        $job = new ShipLogJob(['message' => 'test']);

        try {
            $job->handle();
        } catch (\Exception $e) {
            // Expected exception
        }

        $this->assertEquals(1, Cache::get('log_shipper_failures'));
    }

    #[Test]
    public function circuit_opens_after_threshold()
    {
        Http::fake(function () {
            throw new \Exception('Connection failed');
        });

        $job = new ShipLogJob(['message' => 'test']);

        // Threshold is 2
        try {
            $job->handle();
        } catch (\Exception $e) {
        } // 1 failure
        $this->assertFalse(Cache::has('log_shipper_dead_until'));

        try {
            $job->handle();
        } catch (\Exception $e) {
        } // 2 failures
        $this->assertTrue(Cache::has('log_shipper_dead_until'));
    }

    #[Test]
    public function job_resets_failure_count_on_success()
    {
        Cache::put('log_shipper_failures', 1);

        Http::fake([
            '*' => Http::response('OK', 200),
        ]);

        $job = new ShipLogJob(['message' => 'test']);
        $job->handle();

        $this->assertFalse(Cache::has('log_shipper_failures'));
    }

    #[Test]
    public function handler_skips_dispatch_when_circuit_is_open()
    {
        Queue::fake();
        Cache::put('log_shipper_dead_until', now()->addMinutes(5));

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test message', Level::Error);

        $handler->handle($record);

        Queue::assertNotPushed(ShipLogJob::class);
    }

    #[Test]
    public function handler_dispatches_when_circuit_is_closed()
    {
        Queue::fake();
        Cache::forget('log_shipper_dead_until');

        $handler = new LogShipperHandler(Level::Error);
        $record = $this->createLogRecord('Test message', Level::Error);

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class);
    }

    #[Test]
    public function handler_writes_to_fallback_channel_when_circuit_is_open()
    {
        Queue::fake();
        Config::set('log-shipper.fallback_channel', 'single');
        Config::set('log-shipper.sanitize_fields', ['password']);
        Cache::put('log_shipper_dead_until', now()->addMinutes(5));

        Log::shouldReceive('channel')->with('single')->once()->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->with('error', 'Test message', \Mockery::on(function ($context) {
                return $context['log_shipper_failure'] === 'circuit breaker open'
                    && $context['password'] === '[REDACTED]'
                    && $context['order_id'] === 7;
            }));

        $handler = new LogShipperHandler(Level::Error);
        $handler->handle($this->createLogRecord('Test message', Level::Error, ['password' => 'x', 'order_id' => 7]));

        Queue::assertNotPushed(ShipLogJob::class);
    }

    #[Test]
    public function handler_does_not_use_log_shipper_as_fallback_when_circuit_is_open()
    {
        Queue::fake();
        Config::set('log-shipper.fallback_channel', 'log_shipper');
        Cache::put('log_shipper_dead_until', now()->addMinutes(5));

        Log::shouldReceive('channel')->never();

        $handler = new LogShipperHandler(Level::Error);
        $handler->handle($this->createLogRecord('Test message', Level::Error));

        Queue::assertNotPushed(ShipLogJob::class);
    }

    #[Test]
    public function dead_queue_trips_the_breaker()
    {
        Config::set('queue.connections.broken', ['driver' => 'does-not-exist']);
        Config::set('log-shipper.queue_connection', 'broken');

        $handler = new LogShipperHandler(Level::Error);

        $handler->handle($this->createLogRecord('first', Level::Error));
        $this->assertEquals(1, Cache::get('log_shipper_failures'));
        $this->assertFalse(Cache::has('log_shipper_dead_until'));

        $handler->handle($this->createLogRecord('second', Level::Error));
        $this->assertTrue(Cache::has('log_shipper_dead_until'));
    }

    #[Test]
    public function failing_buffer_records_a_failure()
    {
        Queue::fake();
        Config::set('log-shipper.batch.enabled', true);
        $this->app->instance(LogBufferInterface::class, new class implements LogBufferInterface
        {
            public function push(array $payload): void
            {
                throw new \RuntimeException('redis down');
            }

            public function popBatch(int $size): array
            {
                return [];
            }

            public function size(): int
            {
                return 0;
            }
        });

        $handler = new LogShipperHandler(Level::Error);
        $handler->handle($this->createLogRecord('Test message', Level::Error));

        $this->assertEquals(1, Cache::get('log_shipper_failures'));
        Queue::assertPushed(ShipLogJob::class);
    }

    #[Test]
    public function failing_buffer_and_dead_queue_count_once_per_log()
    {
        Config::set('log-shipper.batch.enabled', true);
        Config::set('queue.connections.broken', ['driver' => 'does-not-exist']);
        Config::set('log-shipper.queue_connection', 'broken');
        $this->app->instance(LogBufferInterface::class, new class implements LogBufferInterface
        {
            public function push(array $payload): void
            {
                throw new \RuntimeException('redis down');
            }

            public function popBatch(int $size): array
            {
                return [];
            }

            public function size(): int
            {
                return 0;
            }
        });

        (new LogShipperHandler(Level::Error))->handle($this->createLogRecord('Test message', Level::Error));

        $this->assertEquals(1, Cache::get('log_shipper_failures'));
    }

    #[Test]
    public function failure_count_expires_after_breaker_duration()
    {
        Http::fake(function () {
            throw new \Exception('Connection failed');
        });

        try {
            (new ShipLogJob(['message' => 'test']))->handle();
        } catch (\Exception $e) {
        }
        $this->assertEquals(1, Cache::get('log_shipper_failures'));

        $this->travel(61)->seconds();

        // An old failure must not count towards a new outage
        $this->assertNull(Cache::get('log_shipper_failures'));
    }

    #[Test]
    public function breaker_is_on_when_the_published_config_lacks_the_enabled_key()
    {
        // Older published configs: mergeConfigFrom does not fill nested keys
        Config::set('log-shipper.circuit_breaker', []);
        Http::fake(function () {
            throw new \Exception('Connection failed');
        });

        try {
            (new ShipLogJob(['message' => 'test']))->handle();
        } catch (\Exception $e) {
        }

        $this->assertEquals(1, Cache::get('log_shipper_failures'));
    }

    #[Test]
    public function sync_queue_failure_is_counted_once()
    {
        Config::set('log-shipper.queue_connection', 'sync');
        Http::fake(['*' => Http::response('down', 500)]);

        (new LogShipperHandler(Level::Error))->handle($this->createLogRecord('boom', Level::Error));

        // The job already recorded this HTTP error; the handler must not count it again
        $this->assertEquals(1, Cache::get('log_shipper_failures'));
        $this->assertFalse(Cache::has('log_shipper_dead_until'));
    }

    #[Test]
    public function sync_queue_failure_writes_fallback_once()
    {
        Config::set('log-shipper.queue_connection', 'sync');
        Config::set('log-shipper.fallback_channel', 'fallback');
        Http::fake(['*' => Http::response('down', 500)]);

        $fallback = \Mockery::mock();
        $fallback->shouldReceive('log')->once();
        Log::shouldReceive('channel')->with('fallback')->andReturn($fallback);

        (new LogShipperHandler(Level::Error))->handle($this->createLogRecord('boom', Level::Error));
    }

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
}
