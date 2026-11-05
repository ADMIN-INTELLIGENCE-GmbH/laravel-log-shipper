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

class SensitiveDataSanitizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('log-shipper.enabled', true);
        Queue::fake();
    }

    protected function shipAndCapture(array $context, array $sendContext = []): array
    {
        Config::set('log-shipper.send_context', $sendContext);

        $handler = new LogShipperHandler(Level::Error);
        $handler->handle(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Test',
            context: $context,
            extra: []
        ));

        $captured = null;
        Queue::assertPushed(ShipLogJob::class, function ($job) use (&$captured) {
            $property = (new \ReflectionClass($job))->getProperty('payload');
            $property->setAccessible(true);
            $captured = $property->getValue($job);

            return true;
        });

        return $captured;
    }

    #[Test]
    public function sensitive_key_with_array_value_is_redacted_whole()
    {
        $payload = $this->shipAndCapture([
            'headers' => [
                'authorization' => ['Bearer abc'],
                'accept' => ['application/json'],
            ],
            'password' => ['one', 'two'],
            'ids' => [1, 2, 3],
        ]);

        $this->assertSame('[REDACTED]', $payload['context']['headers']['authorization']);
        $this->assertSame(['application/json'], $payload['context']['headers']['accept']);
        $this->assertSame('[REDACTED]', $payload['context']['password']);
        $this->assertSame([1, 2, 3], $payload['context']['ids']);
    }

    #[Test]
    public function camel_case_and_other_separators_match()
    {
        $payload = $this->shipAndCapture([
            'apiKey' => 'a',
            'accessToken' => 'b',
            'clientSecret' => 'c',
            'x-api-key' => 'd',
            'X-XSRF-TOKEN' => 'e',
            'privateKey' => 'f',
            'user password' => 'g',
            'PassWord' => 'h',
            'secretary' => 'Jane',
            'compass' => 'north',
            'tokenizer' => 'keep',
        ]);

        foreach (['apiKey', 'accessToken', 'clientSecret', 'x-api-key', 'X-XSRF-TOKEN', 'privateKey', 'user password', 'PassWord'] as $key) {
            $this->assertSame('[REDACTED]', $payload['context'][$key], $key);
        }
        $this->assertSame('Jane', $payload['context']['secretary']);
        $this->assertSame('north', $payload['context']['compass']);
        $this->assertSame('keep', $payload['context']['tokenizer']);
    }

    #[Test]
    public function plural_keys_match()
    {
        $payload = $this->shipAndCapture([
            'passwords' => 'a',
            'api_keys' => 'b',
            'apiKeys' => 'c',
            'Tokens' => 'd',
            'PassWords' => 'e',
            'secretary' => 'Jane',
            'compass' => 'north',
            'tokenizer' => 'keep',
        ]);

        foreach (['passwords', 'api_keys', 'apiKeys', 'Tokens', 'PassWords'] as $key) {
            $this->assertSame('[REDACTED]', $payload['context'][$key], $key);
        }
        $this->assertSame('Jane', $payload['context']['secretary']);
        $this->assertSame('north', $payload['context']['compass']);
        $this->assertSame('keep', $payload['context']['tokenizer']);
    }

    #[Test]
    public function sensitive_query_parameters_are_redacted_in_urls()
    {
        $this->app['request']->server->set('REQUEST_URI', '/reset?token=abc&page=2&apiKey=xyz&user%5Bpassword%5D=pw');
        $this->app['request']->server->set('QUERY_STRING', 'token=abc&page=2&apiKey=xyz&user%5Bpassword%5D=pw');
        $this->app['request']->headers->set('referer', 'https://example.com/cb?code=1&access_token=t0k#id_token=jwt');

        $payload = $this->shipAndCapture([], ['request_url' => true, 'referrer' => true]);

        $this->assertStringNotContainsString('abc', $payload['request_url']);
        $this->assertStringNotContainsString('xyz', $payload['request_url']);
        $this->assertStringNotContainsString('=pw', $payload['request_url']);
        $this->assertStringContainsString('token=[REDACTED]', $payload['request_url']);
        $this->assertStringContainsString('page=2', $payload['request_url']);
        $this->assertSame(
            'https://example.com/cb?code=1&access_token=[REDACTED]#id_token=[REDACTED]',
            $payload['referrer']
        );
    }

    #[Test]
    public function default_config_covers_cookies_sessions_and_private_keys()
    {
        $fields = config('log-shipper.sanitize_fields');

        foreach (['cookie', 'session', 'x-xsrf-token', 'private_key'] as $field) {
            $this->assertContains($field, $fields);
        }

        $payload = $this->shipAndCapture([
            'headers' => ['cookie' => ['laravel_session=abc'], 'x-xsrf-token' => ['xyz']],
            'session_id' => 'abc',
            'private_key' => '-----BEGIN',
        ]);

        $this->assertSame('[REDACTED]', $payload['context']['headers']['cookie']);
        $this->assertSame('[REDACTED]', $payload['context']['headers']['x-xsrf-token']);
        $this->assertSame('[REDACTED]', $payload['context']['session_id']);
        $this->assertSame('[REDACTED]', $payload['context']['private_key']);
    }

    #[Test]
    public function failed_job_redacts_the_same_way_as_the_handler()
    {
        Config::set('log-shipper.fallback_channel', 'single');

        $raw = [
            'level' => 'error',
            'message' => 'Test',
            'context' => [
                'headers' => ['authorization' => ['Bearer abc']],
                'apiKey' => 'a',
                'secretary' => 'Jane',
                'compass' => 'north',
            ],
            'request_url' => 'https://example.com/?token=abc&page=2',
        ];

        // Handler result for the same data, for comparison
        $handler = new class extends LogShipperHandler
        {
            public function run(array $data): array
            {
                return $this->sanitize($data);
            }
        };
        $expected = $handler->run($raw);

        $logged = null;
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->once()->with('error', 'Test', \Mockery::on(function ($context) use (&$logged) {
            $logged = $context['original_payload'];

            return true;
        }));

        (new ShipLogJob($raw))->failed(new \Exception('x'));

        $this->assertSame($expected, $logged);
        $this->assertSame('[REDACTED]', $logged['context']['headers']['authorization']);
        $this->assertSame('[REDACTED]', $logged['context']['apiKey']);
        $this->assertSame('Jane', $logged['context']['secretary']);
        $this->assertSame('https://example.com/?token=[REDACTED]&page=2', $logged['request_url']);
    }
}
