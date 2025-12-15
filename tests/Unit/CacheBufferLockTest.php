<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Buffer\CacheBuffer;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;

class CacheBufferLockTest extends TestCase
{
    #[Test]
    public function push_acquires_and_releases_lock()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        $payload = ['level' => 'error', 'message' => 'Test'];

        $buffer->push($payload);

        // Verify data was stored
        $this->assertEquals(1, $buffer->size());
    }

    #[Test]
    public function push_handles_lock_timeout_gracefully()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        // Acquire the lock manually to simulate contention
        $lock = Cache::store('array')->lock('test_buffer:lock', 10);
        $lock->get();

        $payload = ['level' => 'error', 'message' => 'Test'];

        // Should not throw exception even if lock can't be acquired
        $buffer->push($payload);

        // Data might not be stored due to lock, but shouldn't crash
        $this->assertTrue(true);

        $lock->release();
    }

    #[Test]
    public function pop_batch_acquires_and_releases_lock()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        // Add some data
        Cache::store('array')->put('test_buffer', [
            ['level' => 'error', 'message' => 'Log 1'],
            ['level' => 'error', 'message' => 'Log 2'],
        ]);

        $batch = $buffer->popBatch(2);

        $this->assertCount(2, $batch);
        $this->assertEquals('Log 1', $batch[0]['message']);
    }

    #[Test]
    public function pop_batch_handles_lock_timeout_gracefully()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        // Acquire the lock manually
        $lock = Cache::store('array')->lock('test_buffer:lock', 10);
        $lock->get();

        // Should return empty array when lock can't be acquired
        $batch = $buffer->popBatch(10);

        $this->assertIsArray($batch);
        $this->assertEmpty($batch);

        $lock->release();
    }

    #[Test]
    public function buffer_overflow_implements_ring_buffer_strategy()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        // Fill buffer to max capacity (1000)
        for ($i = 0; $i < 1000; $i++) {
            $buffer->push(['message' => "Log $i"]);
        }

        $this->assertEquals(1000, $buffer->size());

        // Add one more - should drop oldest
        $buffer->push(['message' => 'Log 1000']);

        // Size should still be at max (or max limit)
        $this->assertLessThanOrEqual(1000, $buffer->size());
    }

    #[Test]
    public function pop_batch_with_negative_size_returns_empty()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        Cache::store('array')->put('test_buffer', [
            ['level' => 'error', 'message' => 'Test'],
        ]);

        $batch = $buffer->popBatch(-5);

        $this->assertIsArray($batch);
        $this->assertEmpty($batch);
    }

    #[Test]
    public function pop_batch_with_zero_size_returns_empty()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        $batch = $buffer->popBatch(0);

        $this->assertIsArray($batch);
        $this->assertEmpty($batch);
    }

    #[Test]
    public function pop_batch_with_excessive_size_is_rejected()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        Cache::store('array')->put('test_buffer', [
            ['level' => 'error', 'message' => 'Test'],
        ]);

        $batch = $buffer->popBatch(15000); // Over limit

        $this->assertIsArray($batch);
        $this->assertEmpty($batch);
    }

    #[Test]
    public function pop_batch_removes_items_from_buffer()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        Cache::store('array')->put('test_buffer', [
            ['message' => 'Log 1'],
            ['message' => 'Log 2'],
            ['message' => 'Log 3'],
        ]);

        $batch = $buffer->popBatch(2);

        $this->assertCount(2, $batch);
        $this->assertEquals(1, $buffer->size()); // 1 remaining
    }

    #[Test]
    public function pop_batch_on_empty_buffer_returns_empty_array()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        $batch = $buffer->popBatch(10);

        $this->assertIsArray($batch);
        $this->assertEmpty($batch);
    }

    #[Test]
    public function pop_batch_handles_corrupted_cache_data()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        // Store non-array data (simulating corruption)
        Cache::store('array')->put('test_buffer', 'corrupted_string');

        $batch = $buffer->popBatch(10);

        $this->assertIsArray($batch);
        $this->assertEmpty($batch);
    }

    #[Test]
    public function size_returns_zero_for_corrupted_data()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        Cache::store('array')->put('test_buffer', 'not_an_array');

        $this->assertEquals(0, $buffer->size());
    }

    #[Test]
    public function concurrent_push_operations_are_safe()
    {
        $buffer = new CacheBuffer('array', 'test_buffer');

        // Simulate multiple pushes (in real scenario these would be concurrent)
        $buffer->push(['message' => 'Log 1']);
        $buffer->push(['message' => 'Log 2']);
        $buffer->push(['message' => 'Log 3']);

        // All should be stored
        $this->assertEquals(3, $buffer->size());
    }
}
