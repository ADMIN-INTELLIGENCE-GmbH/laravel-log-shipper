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

class SanitizationConsistencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('log-shipper.enabled', true);
        Config::set('log-shipper.sanitize_fields', ['password', 'secret', 'token', 'api_key']);
        Queue::fake();
    }

    #[Test]
    public function handler_sanitizes_exact_field_names()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: [
                'password' => 'secret123',
                'username' => 'john',
            ],
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);
            
            return $payload['context']['password'] === '[REDACTED]'
                && $payload['context']['username'] === 'john';
        });
    }

    #[Test]
    public function handler_sanitizes_underscore_prefixed_fields()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: [
                'user_password' => 'secret123',
                'api_secret' => 'key456',
                'user_name' => 'john',
            ],
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);
            
            return $payload['context']['user_password'] === '[REDACTED]'
                && $payload['context']['api_secret'] === '[REDACTED]'
                && $payload['context']['user_name'] === 'john';
        });
    }

    #[Test]
    public function handler_sanitizes_underscore_suffixed_fields()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: [
                'password_confirmation' => 'secret123',
                'token_refresh' => 'abc123',
            ],
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);
            
            return $payload['context']['password_confirmation'] === '[REDACTED]'
                && $payload['context']['token_refresh'] === '[REDACTED]';
        });
    }

    #[Test]
    public function handler_sanitizes_hyphen_delimited_fields()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: [
                'user-password' => 'secret123',  // password is in config
                'api-token' => 'token456',       // token is in config
            ],
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);
            
            // Current implementation matches hyphen-delimited fields
            return $payload['context']['user-password'] === '[REDACTED]'
                && $payload['context']['api-token'] === '[REDACTED]';
        });
    }

    #[Test]
    public function handler_does_not_match_false_positives()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: [
                'compass' => 'north',        // Should NOT match 'pass'
                'passage' => 'hallway',      // Should NOT match 'pass'
                'secretary' => 'John',       // Should NOT match 'secret'
                'bypass' => 'enabled',       // Should NOT match 'pass'
            ],
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);
            
            return $payload['context']['compass'] === 'north'
                && $payload['context']['passage'] === 'hallway'
                && $payload['context']['secretary'] === 'John'
                && $payload['context']['bypass'] === 'enabled';
        });
    }

    #[Test]
    public function handler_sanitizes_nested_arrays()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: [
                'user' => [
                    'name' => 'John',
                    'password' => 'secret123',
                    'profile' => [
                        'api_key' => 'key456',
                    ],
                ],
            ],
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);
            
            $user = $payload['context']['user'];
            return $user['name'] === 'John'
                && $user['password'] === '[REDACTED]'
                && $user['profile']['api_key'] === '[REDACTED]';
        });
    }

    #[Test]
    public function handler_is_case_insensitive()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: [
                'PASSWORD' => 'secret123',
                'Password' => 'secret456',
                'PassWord' => 'secret789',
            ],
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);
            
            return $payload['context']['PASSWORD'] === '[REDACTED]'
                && $payload['context']['Password'] === '[REDACTED]'
                && $payload['context']['PassWord'] === '[REDACTED]';
        });
    }

    #[Test]
    public function failed_job_uses_same_sanitization_as_handler()
    {
        Config::set('log-shipper.fallback_channel', 'single');

        $payload = [
            'level' => 'error',
            'message' => 'Test',
            'context' => [
                'user_password' => 'secret123',
                'api_key' => 'key456',
                'username' => 'john',
            ],
        ];

        $job = new ShipLogJob($payload);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->with('error', 'Test', \Mockery::on(function ($context) {
                $payload = $context['original_payload'] ?? [];
                if (!isset($payload['context'])) {
                    return false;
                }

                // Verify sanitization matches handler behavior
                return $payload['context']['user_password'] === '[REDACTED]'
                    && $payload['context']['api_key'] === '[REDACTED]'
                    && $payload['context']['username'] === 'john';
            }));

        $job->failed(new \Exception('Test'));
    }

    #[Test]
    public function handler_sanitizes_multiple_occurrences_in_same_context()
    {
        $handler = new LogShipperHandler(Level::Error);

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: [
                'old_password' => 'old123',
                'new_password' => 'new456',
                'password_confirmation' => 'new456',
            ],
            extra: []
        );

        $handler->handle($record);

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);
            
            return $payload['context']['old_password'] === '[REDACTED]'
                && $payload['context']['new_password'] === '[REDACTED]'
                && $payload['context']['password_confirmation'] === '[REDACTED]';
        });
    }
}
