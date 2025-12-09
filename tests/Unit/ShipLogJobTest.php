<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class ShipLogJobTest extends TestCase
{
    #[Test]
    public function it_sends_payload_to_configured_endpoint(): void
    {
        Http::fake();

        config(['log-shipper.api_endpoint' => 'https://logs.example.com/api/logs']);
        config(['log-shipper.api_key' => 'test-key']);

        $payload = [
            'level' => 'error',
            'message' => 'Test error',
            'context' => [],
            'datetime' => '2025-12-09 12:00:00.000000',
            'channel' => 'test',
        ];

        $job = new ShipLogJob($payload);
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://logs.example.com/api/logs'
                && $request['level'] === 'error'
                && $request['message'] === 'Test error';
        });
    }

    #[Test]
    public function it_sends_project_key_header(): void
    {
        Http::fake();

        config(['log-shipper.api_endpoint' => 'https://logs.example.com/api/logs']);
        config(['log-shipper.api_key' => 'my-secret-key']);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Project-Key', 'my-secret-key');
        });
    }

    #[Test]
    public function it_sends_json_content_type(): void
    {
        Http::fake();

        config(['log-shipper.api_endpoint' => 'https://logs.example.com/api/logs']);
        config(['log-shipper.api_key' => 'test-key']);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Content-Type', 'application/json');
        });
    }

    #[Test]
    public function it_sends_accept_json_header(): void
    {
        Http::fake();

        config(['log-shipper.api_endpoint' => 'https://logs.example.com/api/logs']);
        config(['log-shipper.api_key' => 'test-key']);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Accept', 'application/json');
        });
    }

    #[Test]
    public function it_does_not_send_when_endpoint_is_empty(): void
    {
        Http::fake();

        config(['log-shipper.api_endpoint' => '']);
        config(['log-shipper.api_key' => 'test-key']);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_when_api_key_is_empty(): void
    {
        Http::fake();

        config(['log-shipper.api_endpoint' => 'https://logs.example.com/api/logs']);
        config(['log-shipper.api_key' => '']);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_has_single_try(): void
    {
        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);

        $this->assertEquals(1, $job->tries);
    }

    #[Test]
    public function it_has_15_second_job_timeout(): void
    {
        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);

        $this->assertEquals(15, $job->timeout);
    }

    #[Test]
    public function it_handles_http_exceptions_silently(): void
    {
        config(['log-shipper.api_endpoint' => 'https://logs.example.com/api/logs']);
        config(['log-shipper.api_key' => 'test-key']);

        Http::fake(function () {
            throw new \Exception('Connection refused');
        });

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);

        // Should not throw an exception
        $job->handle();

        $this->assertTrue(true); // If we get here, the exception was handled
    }

    #[Test]
    public function it_handles_failed_method_silently(): void
    {
        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);

        // Should not throw an exception
        $job->failed(new \Exception('Test exception'));

        $this->assertTrue(true); // If we get here, the method completed without error
    }

    #[Test]
    public function it_sends_complete_payload(): void
    {
        Http::fake();

        config(['log-shipper.api_endpoint' => 'https://logs.example.com/api/logs']);
        config(['log-shipper.api_key' => 'test-key']);

        $payload = [
            'level' => 'error',
            'message' => 'Order processing failed',
            'context' => ['order_id' => 123, 'user_id' => 456],
            'datetime' => '2025-12-09 12:00:00.000000',
            'channel' => 'production',
            'extra' => ['memory_usage' => '50MB'],
        ];

        $job = new ShipLogJob($payload);
        $job->handle();

        Http::assertSent(function ($request) use ($payload) {
            return $request['level'] === $payload['level']
                && $request['message'] === $payload['message']
                && $request['context']['order_id'] === 123
                && $request['context']['user_id'] === 456
                && $request['channel'] === 'production';
        });
    }
}
