<?php

namespace AdminIntelligence\LogShipper\Buffer;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class CacheBuffer implements LogBufferInterface
{
    protected const MAX_BUFFER_SIZE = 1000;

    public function __construct(
        protected string $store,
        protected string $key
    ) {}

    public function push(array $payload): void
    {
        $lock = Cache::store($this->store)->lock($this->key . ':lock', 5);
        $lockAcquired = false;

        try {
            $lock->block(5);
            $lockAcquired = true;

            $buffer = Cache::store($this->store)->get($this->key, []);

            // Ensure it's an array (handle corruption or empty state)
            if (!is_array($buffer)) {
                $buffer = [];
            }

            // SECURITY: Prevent memory exhaustion by limiting buffer size
            if (count($buffer) >= self::MAX_BUFFER_SIZE) {
                // Strategy: Drop oldest items to make room for new ones (Ring Buffer)
                // This ensures we always have the most recent logs
                $buffer = array_slice($buffer, -(self::MAX_BUFFER_SIZE - 1));
            }

            $buffer[] = $payload;

            Cache::store($this->store)->put($this->key, $buffer);
        } catch (LockTimeoutException $e) {
            // If we can't get a lock, we might lose this log or should fallback.
            // For now, we silently fail to avoid crashing the app.
        } finally {
            // CRITICAL FIX: Only release if we actually acquired the lock
            if ($lockAcquired) {
                $lock->release();
            }
        }
    }

    public function popBatch(int $size): array
    {
        // SECURITY: Validate size to prevent memory exhaustion
        if ($size <= 0 || $size > 10000) {
            return [];
        }
        
        $lock = Cache::store($this->store)->lock($this->key . ':lock', 10);
        $batch = [];
        $lockAcquired = false;

        try {
            $lock->block(5);
            $lockAcquired = true;

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
            // CRITICAL FIX: Only release if we actually acquired the lock
            if ($lockAcquired) {
                $lock->release();
            }
        }

        return $batch;
    }

    public function size(): int
    {
        $buffer = Cache::store($this->store)->get($this->key, []);

        return is_array($buffer) ? count($buffer) : 0;
    }
}
