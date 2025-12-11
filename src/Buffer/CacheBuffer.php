<?php

namespace AdminIntelligence\LogShipper\Buffer;

use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\LockTimeoutException;

class CacheBuffer implements LogBufferInterface
{
    public function __construct(
        protected string $store,
        protected string $key
    ) {}

    public function push(array $payload): void
    {
        $lock = Cache::store($this->store)->lock($this->key . ':lock', 5);

        try {
            $lock->block(5);

            $buffer = Cache::store($this->store)->get($this->key, []);
            
            // Ensure it's an array (handle corruption or empty state)
            if (!is_array($buffer)) {
                $buffer = [];
            }

            $buffer[] = $payload;

            Cache::store($this->store)->put($this->key, $buffer);
        } catch (LockTimeoutException $e) {
            // If we can't get a lock, we might lose this log or should fallback.
            // For now, we silently fail to avoid crashing the app.
        } finally {
            $lock->release();
        }
    }

    public function popBatch(int $size): array
    {
        $lock = Cache::store($this->store)->lock($this->key . ':lock', 10);
        $batch = [];

        try {
            $lock->block(5);

            $buffer = Cache::store($this->store)->get($this->key, []);

            if (!is_array($buffer) || empty($buffer)) {
                return [];
            }

            // Splice the first $size items
            $batch = array_splice($buffer, 0, $size);

            // Save the remaining items back
            if (empty($buffer)) {
                Cache::store($this->store)->forget($this->key);
            } else {
                Cache::store($this->store)->put($this->key, $buffer);
            }

        } catch (LockTimeoutException $e) {
            return [];
        } finally {
            $lock->release();
        }

        return $batch;
    }

    public function size(): int
    {
        $buffer = Cache::store($this->store)->get($this->key, []);
        return is_array($buffer) ? count($buffer) : 0;
    }
}
