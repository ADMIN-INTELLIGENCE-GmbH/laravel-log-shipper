<?php

namespace AdminIntelligence\LogShipper\Buffer;

use Illuminate\Support\Facades\Redis;

class RedisBuffer implements LogBufferInterface
{
    public function __construct(
        protected string $connection,
        protected string $key
    ) {}

    public function push(array $payload): void
    {
        Redis::connection($this->connection)->rpush($this->key, json_encode($payload));
    }

    public function popBatch(int $size): array
    {
        $redis = Redis::connection($this->connection);
        $batch = [];

        // We loop to ensure atomic pops without locking the whole list
        for ($i = 0; $i < $size; $i++) {
            $log = $redis->lpop($this->key);
            
            if (!$log) {
                break;
            }

            $batch[] = json_decode($log, true);
        }

        return $batch;
    }

    public function size(): int
    {
        return Redis::connection($this->connection)->llen($this->key);
    }
}
