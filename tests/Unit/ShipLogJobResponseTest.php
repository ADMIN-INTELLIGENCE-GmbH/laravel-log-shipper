<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class ShipLogJobResponseTest extends TestCase
{
    #[Test]
    public function it_throws_exception_on_404_response()
    {
        config(['log-shipper.api_endpoint' => 'https://example.com/wrong-path']);
        config(['log-shipper.api_key' => 'test-key']);

        Http::fake([
            '*' => Http::response('Not Found', 404),
        ]);

        $job = new ShipLogJob(['message' => 'test']);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $job->handle();
    }

    #[Test]
    public function it_throws_exception_on_500_response()
    {
        config(['log-shipper.api_endpoint' => 'https://example.com/api/ingest']);
        config(['log-shipper.api_key' => 'test-key']);

        Http::fake([
            '*' => Http::response('Server Error', 500),
        ]);

        $job = new ShipLogJob(['message' => 'test']);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $job->handle();
    }
}
