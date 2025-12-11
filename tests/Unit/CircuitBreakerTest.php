<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Logging\LogShipperHandler;
use AdminIntelligence\LogShipper\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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
        try { $job->handle(); } catch (\Exception $e) {} // 1 failure
        $this->assertFalse(Cache::has('log_shipper_dead_until'));
        
        try { $job->handle(); } catch (\Exception $e) {} // 2 failures
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
