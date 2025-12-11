<?php

namespace AdminIntelligence\LogShipper\Tests\Feature;

use AdminIntelligence\LogShipper\Jobs\ShipBatchJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class BatchShippingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('log-shipper.batch.enabled', true);
        Config::set('log-shipper.batch.buffer_key', 'test_buffer');

        // Configure logging to use our channel
        Config::set('logging.default', 'stack');
        Config::set('logging.channels.stack', [
            'driver' => 'stack',
            'channels' => ['log_shipper'],
            'ignore_exceptions' => false,
        ]);
        Config::set('logging.channels.log_shipper', [
            'driver' => 'custom',
            'via' => \AdminIntelligence\LogShipper\Logging\CreateCustomLogger::class,
            'level' => 'debug',
        ]);
    }

    public function test_logs_are_pushed_to_redis_when_batch_enabled_with_redis_driver()
    {
        Config::set('log-shipper.batch.driver', 'redis');

        Redis::shouldReceive('connection')
            ->with('default')
            ->andReturnSelf();

        Redis::shouldReceive('rpush')
            ->once()
            ->with('test_buffer', \Mockery::on(function ($arg) {
                $json = json_decode($arg, true);

                return $json['message'] === 'Test batch log';
            }));

        Log::error('Test batch log');
    }

    public function test_logs_are_pushed_to_cache_when_batch_enabled_with_cache_driver()
    {
        Config::set('log-shipper.batch.driver', 'cache');
        Config::set('log-shipper.batch.connection', 'array'); // Use array driver for testing

        // We can't easily mock Cache facade with locks in a simple way without partial mocks,
        // so we'll rely on the real array cache driver behavior.

        Log::error('Test batch log');

        $buffer = Cache::store('array')->get('test_buffer');
        $this->assertIsArray($buffer);
        $this->assertCount(1, $buffer);
        $this->assertEquals('Test batch log', $buffer[0]['message']);
    }

    public function test_command_dispatches_batch_job_redis()
    {
        Config::set('log-shipper.batch.driver', 'redis');
        Queue::fake();

        Redis::shouldReceive('connection')
            ->andReturnSelf();

        // Mock popping 2 logs then null
        Redis::shouldReceive('lpop')
            ->with('test_buffer')
            ->times(4) // Called 4 times: Log1, Log2, null (end of batch), null (end of loop)
            ->andReturn(
                json_encode(['message' => 'Log 1']),
                json_encode(['message' => 'Log 2']),
                null,
                null
            );

        $this->artisan('log-shipper:ship-batch')
            ->assertExitCode(0);

        Queue::assertPushed(ShipBatchJob::class, 1);
    }

    public function test_command_dispatches_batch_job_cache()
    {
        Config::set('log-shipper.batch.driver', 'cache');
        Config::set('log-shipper.batch.connection', 'array');
        Queue::fake();

        // Seed the cache
        Cache::store('array')->put('test_buffer', [
            ['message' => 'Log 1'],
            ['message' => 'Log 2'],
        ]);

        $this->artisan('log-shipper:ship-batch')
            ->assertExitCode(0);

        Queue::assertPushed(ShipBatchJob::class, 1);

        // Verify buffer is empty
        $this->assertEmpty(Cache::store('array')->get('test_buffer'));
    }
}
