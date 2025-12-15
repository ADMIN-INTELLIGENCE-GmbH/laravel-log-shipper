<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class HttpsEnforcementTest extends TestCase
{
    #[Test]
    public function production_environment_rejects_http_endpoints()
    {
        Config::set('app.env', 'production');
        Config::set('log-shipper.api_endpoint', 'http://insecure.example.com/api/ingest');
        Config::set('log-shipper.api_key', 'test-key');

        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        // Should not send request to HTTP endpoint in production
        Http::assertNothingSent();
    }

    #[Test]
    public function production_environment_accepts_https_endpoints()
    {
        Config::set('app.env', 'production');
        Config::set('log-shipper.api_endpoint', 'https://secure.example.com/api/ingest');
        Config::set('log-shipper.api_key', 'test-key');

        Http::fake([
            'https://secure.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        // Should send request to HTTPS endpoint
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://');
        });
    }

    #[Test]
    public function local_environment_allows_http_endpoints()
    {
        Config::set('app.env', 'local');
        Config::set('log-shipper.api_endpoint', 'http://localhost:8000/api/ingest');
        Config::set('log-shipper.api_key', 'test-key');

        Http::fake([
            'http://localhost:8000/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        // Should allow HTTP in local environment
        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:8000/api/ingest';
        });
    }

    #[Test]
    public function staging_environment_allows_http_endpoints()
    {
        Config::set('app.env', 'staging');
        Config::set('log-shipper.api_endpoint', 'http://staging.example.com/api/ingest');
        Config::set('log-shipper.api_key', 'test-key');

        Http::fake([
            'http://staging.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        // Currently allows HTTP in staging (this is a known issue from code review)
        // Test documents current behavior
        Http::assertSent(function ($request) {
            return true; // Just verify a request was sent
        });
    }

    #[Test]
    public function testing_environment_allows_http_endpoints()
    {
        Config::set('app.env', 'testing');
        Config::set('log-shipper.api_endpoint', 'http://test.example.com/api/ingest');
        Config::set('log-shipper.api_key', 'test-key');

        Http::fake([
            'http://test.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        Http::assertSent(function ($request) {
            return true; // Just verify a request was sent
        });
    }

    #[Test]
    public function empty_endpoint_is_rejected_in_any_environment()
    {
        Config::set('app.env', 'production');
        Config::set('log-shipper.api_endpoint', '');
        Config::set('log-shipper.api_key', 'test-key');

        Http::fake();

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);
        $job->handle();

        Http::assertNothingSent();
    }

    #[Test]
    public function malformed_url_is_handled_gracefully()
    {
        Config::set('app.env', 'production');
        Config::set('log-shipper.api_endpoint', 'not-a-valid-url');
        Config::set('log-shipper.api_key', 'test-key');

        Http::fake();

        $job = new ShipLogJob(['level' => 'error', 'message' => 'Test']);

        // Should not throw exception
        $job->handle();

        $this->assertTrue(true);
    }
}
