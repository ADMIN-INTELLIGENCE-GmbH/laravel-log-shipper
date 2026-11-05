<?php

declare(strict_types=1);

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipBatchJob;
use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ShipLogJobResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['log-shipper.api_endpoint' => 'https://example.com/api/ingest']);
        config(['log-shipper.api_key' => 'test-key']);
        config(['log-shipper.circuit_breaker.enabled' => true]);
    }

    public static function jobs(): array
    {
        return [
            'single' => [fn () => new ShipLogJob(['message' => 'test'])],
            'batch' => [fn () => new ShipBatchJob([['message' => 'test']])],
        ];
    }

    #[Test]
    #[DataProvider('jobs')]
    public function client_error_fails_the_job_without_retry_or_breaker_count(callable $make)
    {
        foreach ([413, 422] as $status) {
            Http::fake(['*' => Http::response(['message' => 'invalid'], $status)]);

            $job = $make()->withFakeQueueInteractions();
            $job->handle();

            $job->assertFailed();
            $this->assertFalse(Cache::has('log_shipper_failures'), "status {$status}");
        }
    }

    #[Test]
    #[DataProvider('jobs')]
    public function auth_and_endpoint_errors_fail_without_retry_but_count_toward_breaker(callable $make)
    {
        foreach ([401, 403, 404] as $i => $status) {
            Http::fake(['*' => Http::response('nope', $status)]);

            $job = $make()->withFakeQueueInteractions();
            $job->handle();

            $job->assertFailed();
            $this->assertSame($i + 1, Cache::get('log_shipper_failures'), "status {$status}");
        }
    }

    #[Test]
    #[DataProvider('jobs')]
    public function rate_limit_is_retried_and_counted(callable $make)
    {
        Http::fake(['*' => Http::response('Too Many Requests', 429)]);

        $job = $make()->withFakeQueueInteractions();

        try {
            $job->handle();
            $this->fail('Expected RequestException');
        } catch (RequestException) {
        }

        $job->assertNotFailed();
        $this->assertSame(1, Cache::get('log_shipper_failures'));
    }

    #[Test]
    #[DataProvider('jobs')]
    public function server_error_is_retried_and_counted(callable $make)
    {
        Http::fake(['*' => Http::response('Server Error', 500)]);

        $job = $make()->withFakeQueueInteractions();

        try {
            $job->handle();
            $this->fail('Expected RequestException');
        } catch (RequestException) {
        }

        $job->assertNotFailed();
        $this->assertSame(1, Cache::get('log_shipper_failures'));
    }
}
