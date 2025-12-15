<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Logging\LogShipperHandler;
use AdminIntelligence\LogShipper\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;

class RecursiveLogDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('log-shipper.enabled', true);
        Queue::fake();
    }

    #[Test]
    public function blocks_logs_mentioning_ship_log_job_class_in_message()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Error in AdminIntelligence\LogShipper\Jobs\ShipLogJob: Connection failed',
            context: [],
            extra: []
        );

        $handler->handle($record);

        // Should not dispatch to prevent infinite loop
        Queue::assertNothingPushed();
    }

    #[Test]
    public function blocks_logs_with_ship_log_job_in_exception_trace()
    {
        $handler = new LogShipperHandler(Level::Error);

        // Create exception that would have ShipLogJob in stack trace
        try {
            throw new \Exception('Test exception');
        } catch (\Exception $e) {
            $record = new LogRecord(
                datetime: new DateTimeImmutable,
                channel: 'test',
                level: Level::Error,
                message: 'Error occurred',
                context: ['exception' => $e],
                extra: []
            );

            // Manually set trace to contain job class (simulating actual scenario)
            $reflection = new \ReflectionProperty($e, 'trace');
            $reflection->setAccessible(true);
            $reflection->setValue($e, [
                [
                    'file' => '/app/vendor/package/AdminIntelligence/LogShipper/Jobs/ShipLogJob.php',
                    'line' => 50,
                    'function' => 'handle',
                ],
            ]);

            $handler->handle($record);
        }

        // Should detect job class in trace and not dispatch
        Queue::assertNothingPushed();
    }

    #[Test]
    public function allows_debug_logs_without_recursion_check()
    {
        $handler = new LogShipperHandler(Level::Debug);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Debug,
            message: 'Debug message with AdminIntelligence\LogShipper\Jobs\ShipLogJob',
            context: [],
            extra: []
        );

        $handler->handle($record);

        // Debug logs skip recursion check for performance, but level is still below handler threshold
        // Handler is configured with Level::Debug so it should process
        Queue::assertPushed(ShipLogJob::class);
    }

    #[Test]
    public function allows_info_logs_without_recursion_check()
    {
        $handler = new LogShipperHandler(Level::Info);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Info,
            message: 'Info with AdminIntelligence\LogShipper\Jobs\ShipLogJob mentioned',
            context: [],
            extra: []
        );

        $handler->handle($record);

        // Info logs skip recursion check for performance
        Queue::assertPushed(ShipLogJob::class);
    }

    #[Test]
    public function warning_logs_go_through_recursion_check()
    {
        $handler = new LogShipperHandler(Level::Warning);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Warning,
            message: 'Warning in AdminIntelligence\LogShipper\Jobs\ShipLogJob',
            context: [],
            extra: []
        );

        $handler->handle($record);

        // Should be blocked by recursion detection
        Queue::assertNothingPushed();
    }

    #[Test]
    public function error_logs_go_through_recursion_check()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Error from AdminIntelligence\LogShipper\Jobs\ShipLogJob',
            context: [],
            extra: []
        );

        $handler->handle($record);

        // Should be blocked
        Queue::assertNothingPushed();
    }

    #[Test]
    public function allows_normal_errors_not_related_to_ship_log_job()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Database connection failed',
            context: ['query' => 'SELECT * FROM users'],
            extra: []
        );

        $handler->handle($record);

        // Should dispatch normally
        Queue::assertPushed(ShipLogJob::class);
    }

    #[Test]
    public function blocks_logs_with_log_shipper_failure_context_flag()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Fallback log entry',
            context: ['log_shipper_failure' => 'Network timeout'],
            extra: []
        );

        $handler->handle($record);

        // Should not dispatch to prevent infinite loop
        Queue::assertNothingPushed();
    }

    #[Test]
    public function case_sensitive_class_name_matching()
    {
        $handler = new LogShipperHandler(Level::Error);

        // Try with different case
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Error in adminintelligence\logshipper\jobs\shiplogJob (lowercase)',
            context: [],
            extra: []
        );

        $handler->handle($record);

        // Should still match (implementation uses str_contains which is case-sensitive,
        // but this documents the actual behavior)
        // If lowercase doesn't match, job will be dispatched
        Queue::assertPushed(ShipLogJob::class);
    }
}
