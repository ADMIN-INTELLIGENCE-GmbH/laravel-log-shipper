<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Buffer\RedisBuffer;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;

class RedisBufferTest extends TestCase
{
    #[Test]
    public function push_adds_item_to_redis_list()
    {
        Redis::shouldReceive('connection')
            ->with('default')
            ->once()
            ->andReturnSelf();

        Redis::shouldReceive('rpush')
            ->once()
            ->with('log_shipper_buffer', \Mockery::on(function ($json) {
                $decoded = json_decode($json, true);

                return $decoded['level'] === 'error' && $decoded['message'] === 'Test';
            }));

        $buffer = new RedisBuffer('default', 'log_shipper_buffer');
        $buffer->push(['level' => 'error', 'message' => 'Test']);
    }

    #[Test]
    public function pop_batch_with_negative_size_returns_empty()
    {
        $buffer = new RedisBuffer('default', 'log_shipper_buffer');

        Redis::shouldReceive('connection')->andReturnSelf();

        $batch = $buffer->popBatch(-5);

        $this->assertIsArray($batch);
        $this->assertEmpty($batch);
    }

    #[Test]
    public function pop_batch_with_zero_size_returns_empty()
    {
        $buffer = new RedisBuffer('default', 'log_shipper_buffer');

        $batch = $buffer->popBatch(0);

        $this->assertIsArray($batch);
        $this->assertEmpty($batch);
    }

    #[Test]
    public function pop_batch_with_excessive_size_returns_empty()
    {
        $buffer = new RedisBuffer('default', 'log_shipper_buffer');

        $batch = $buffer->popBatch(15000); // Over limit

        $this->assertIsArray($batch);
        $this->assertEmpty($batch);
    }

    #[Test]
    public function pop_batch_uses_lua_script_for_atomic_operations()
    {
        Redis::shouldReceive('connection')
            ->with('default')
            ->once()
            ->andReturnSelf();

        Redis::shouldReceive('eval')
            ->once()
            ->with(
                \Mockery::type('string'), // Lua script
                1, // Number of keys
                'log_shipper_buffer', // Key
                10 // Size
            )
            ->andReturn([
                json_encode(['level' => 'error', 'message' => 'Log 1']),
                json_encode(['level' => 'error', 'message' => 'Log 2']),
            ]);

        $buffer = new RedisBuffer('default', 'log_shipper_buffer');
        $batch = $buffer->popBatch(10);

        $this->assertCount(2, $batch);
        $this->assertEquals('Log 1', $batch[0]['message']);
        $this->assertEquals('Log 2', $batch[1]['message']);
    }

    #[Test]
    public function pop_batch_falls_back_to_sequential_on_lua_failure()
    {
        Redis::shouldReceive('connection')
            ->with('default')
            ->andReturnSelf();

        // First call fails (Lua script)
        Redis::shouldReceive('eval')
            ->once()
            ->andThrow(new \Exception('Lua script failed'));

        // Fallback to lpop
        Redis::shouldReceive('lpop')
            ->times(3)
            ->with('log_shipper_buffer')
            ->andReturn(
                json_encode(['message' => 'Log 1']),
                json_encode(['message' => 'Log 2']),
                false // No more items
            );

        $buffer = new RedisBuffer('default', 'log_shipper_buffer');
        $batch = $buffer->popBatch(10);

        $this->assertCount(2, $batch);
    }

    #[Test]
    public function pop_batch_handles_invalid_json_gracefully()
    {
        Redis::shouldReceive('connection')->andReturnSelf();

        Redis::shouldReceive('eval')
            ->once()
            ->andReturn([
                json_encode(['valid' => 'data']),
                'invalid-json-string',
                json_encode(['another' => 'valid']),
            ]);

        $buffer = new RedisBuffer('default', 'log_shipper_buffer');
        $batch = $buffer->popBatch(10);

        // Should only include valid JSON items
        $this->assertCount(2, $batch);
    }

    #[Test]
    public function size_returns_list_length()
    {
        Redis::shouldReceive('connection')
            ->with('default')
            ->once()
            ->andReturnSelf();

        Redis::shouldReceive('llen')
            ->once()
            ->with('log_shipper_buffer')
            ->andReturn(42);

        $buffer = new RedisBuffer('default', 'log_shipper_buffer');
        $size = $buffer->size();

        $this->assertEquals(42, $size);
    }

    #[Test]
    public function uses_custom_connection_name()
    {
        Redis::shouldReceive('connection')
            ->with('custom-redis')
            ->once()
            ->andReturnSelf();

        Redis::shouldReceive('rpush')->once();

        $buffer = new RedisBuffer('custom-redis', 'custom_buffer');
        $buffer->push(['test' => 'data']);
    }

    #[Test]
    public function uses_custom_buffer_key()
    {
        Redis::shouldReceive('connection')->andReturnSelf();

        Redis::shouldReceive('rpush')
            ->once()
            ->with('my_custom_key', \Mockery::type('string'));

        $buffer = new RedisBuffer('default', 'my_custom_key');
        $buffer->push(['test' => 'data']);
    }
}
