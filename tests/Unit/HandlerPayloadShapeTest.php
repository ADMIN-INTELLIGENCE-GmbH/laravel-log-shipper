<?php

declare(strict_types=1);

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Logging\LogShipperHandler;
use AdminIntelligence\LogShipper\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;

class HandlerPayloadShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Config::set('log-shipper.enabled', true);
    }

    protected function ship(array $context = [], array $extra = [], ?DateTimeImmutable $datetime = null): array
    {
        (new LogShipperHandler(Level::Error))->handle(new LogRecord(
            datetime: $datetime ?? new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: $context,
            extra: $extra
        ));

        $payload = null;
        Queue::assertPushed(ShipLogJob::class, function ($job) use (&$payload) {
            $payload = (new ReflectionProperty($job, 'payload'))->getValue($job);

            return true;
        });

        return $payload;
    }

    protected function nested(int $depth): \Throwable
    {
        $e = new RuntimeException('level 0');
        for ($i = 1; $i <= $depth; $i++) {
            $e = new RuntimeException("level {$i}", 0, $e);
        }

        return $e;
    }

    #[Test]
    public function exception_keeps_trace_without_frame_args()
    {
        $secret = 'hunter2-secret-arg';
        $e = (fn (string $password) => new RuntimeException('boom'))($secret);

        $payload = $this->ship(['exception' => $e]);
        $exception = $payload['context']['exception'];

        $this->assertIsString($exception['trace']);
        $this->assertStringContainsString(__CLASS__, $exception['trace']);
        $this->assertStringNotContainsString($secret, json_encode($payload));
    }

    #[Test]
    public function exception_trace_is_also_sent_as_top_level_frames_for_logger()
    {
        $payload = $this->ship(['exception' => new RuntimeException('boom')]);
        $trace = $payload['context']['trace'];

        $this->assertIsArray($trace);
        $this->assertSame(__CLASS__, $trace[0]['class']);
        $this->assertSame(__FUNCTION__, $trace[0]['function']);
        $this->assertArrayNotHasKey('args', $trace[0]);
    }

    #[Test]
    public function caller_supplied_trace_is_not_overwritten()
    {
        $payload = $this->ship(['exception' => new RuntimeException('boom'), 'trace' => ['mine']]);

        $this->assertSame(['mine'], $payload['context']['trace']);
    }

    #[Test]
    public function trace_is_capped_at_50_frames()
    {
        $recurse = function (int $n) use (&$recurse) {
            return $n === 0 ? new RuntimeException('deep') : $recurse($n - 1);
        };

        $payload = $this->ship(['exception' => $recurse(80)]);

        $this->assertCount(50, $payload['context']['trace']);
        $this->assertCount(50, explode("\n", $payload['context']['exception']['trace']));
    }

    #[Test]
    public function previous_chain_is_kept_up_to_three_levels()
    {
        $payload = $this->ship(['exception' => $this->nested(5)]);
        $exception = $payload['context']['exception'];

        $this->assertSame('level 5', $exception['message']);
        $this->assertSame('level 4', $exception['previous']['message']);
        $this->assertIsString($exception['previous']['trace']);
        $this->assertSame('level 2', $exception['previous']['previous']['previous']['message']);
        $this->assertArrayNotHasKey('previous', $exception['previous']['previous']['previous']);
    }

    #[Test]
    public function datetime_is_sent_as_utc_with_offset()
    {
        $local = new DateTimeImmutable('2026-11-05 14:30:00.123456', new DateTimeZone('Europe/Berlin'));

        $payload = $this->ship(datetime: $local);

        $this->assertSame('2026-11-05T13:30:00.123456+00:00', $payload['datetime']);
        // Same parse the log server does in IngestLogRequest::getLogDatetime()
        $this->assertSame($local->getTimestamp(), (new DateTimeImmutable($payload['datetime']))->getTimestamp());
    }

    #[Test]
    public function oversized_payload_also_truncates_extra()
    {
        Config::set('log-shipper.max_payload_size', 500);

        $payload = $this->ship(extra: ['big' => str_repeat('X', 2000)]);

        $this->assertSame(['_truncated' => true], $payload['extra']);
        $this->assertTrue($payload['_truncated']);
    }

    #[Test]
    public function oversized_trace_is_truncated_with_context()
    {
        Config::set('log-shipper.max_payload_size', 500);

        $payload = $this->ship(['exception' => $this->nested(3)]);

        $this->assertTrue($payload['context']['_truncated']);
        $this->assertArrayNotHasKey('trace', $payload['context']);
    }

    #[Test]
    public function unknown_request_method_is_sent_as_null()
    {
        Config::set('log-shipper.send_context.request_method', true);
        $this->app['request']->setMethod('PROPFIND');

        $this->assertNull($this->ship()['request_method']);
    }

    #[Test]
    public function known_request_method_is_kept()
    {
        Config::set('log-shipper.send_context.request_method', true);
        $this->app['request']->setMethod('PATCH');

        $this->assertSame('PATCH', $this->ship()['request_method']);
    }
}
