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

/**
 * Regression tests: a list (array with integer keys) in the log context used to
 * hit isSensitiveKey() with an int and fatal under strict_types.
 */
class ListContextSanitizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('log-shipper.enabled', true);
        Config::set('log-shipper.sanitize_fields', ['password', 'secret', 'token', 'api_key']);
        Queue::fake();
    }

    #[Test]
    public function it_ships_a_context_containing_a_list(): void
    {
        $this->handle([
            'new_users' => ['alice', 'bob'],
            'deleted_users' => [],
        ]);

        $this->assertShippedContext(function (array $context) {
            return $context['new_users'] === ['alice', 'bob']
                && $context['deleted_users'] === [];
        });
    }

    #[Test]
    public function it_ships_a_context_containing_a_nested_list_of_arrays(): void
    {
        $this->handle([
            'changes' => [
                ['user' => 'alice', 'password' => 'secret123', 'roles' => ['admin', 'editor']],
                ['user' => 'bob', 'roles' => []],
            ],
        ]);

        $this->assertShippedContext(function (array $context) {
            return $context['changes'][0]['user'] === 'alice'
                && $context['changes'][0]['password'] === '[REDACTED]'
                && $context['changes'][0]['roles'] === ['admin', 'editor']
                && $context['changes'][1]['user'] === 'bob'
                && $context['changes'][1]['roles'] === [];
        });
    }

    #[Test]
    public function it_leaves_list_values_untouched_even_when_they_look_sensitive(): void
    {
        $this->handle([
            'fields' => ['password', 'token'],
        ]);

        $this->assertShippedContext(function (array $context) {
            return $context['fields'] === ['password', 'token'];
        });
    }

    protected function handle(array $context): void
    {
        $handler = new LogShipperHandler(Level::Info);

        $handler->handle(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Info,
            message: 'CSV Parse Summary',
            context: $context,
            extra: []
        ));
    }

    protected function assertShippedContext(callable $assertion): void
    {
        Queue::assertPushed(ShipLogJob::class, function ($job) use ($assertion) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('payload');
            $property->setAccessible(true);
            $payload = $property->getValue($job);

            return $assertion($payload['context']);
        });
    }
}
