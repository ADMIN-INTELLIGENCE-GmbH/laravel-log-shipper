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
        // SECURITY: Cap the list so a stalled scheduler cannot fill Redis.
        // Keeps the newest entries and drops the oldest, like CacheBuffer.
        $max = (int) config('log-shipper.batch.max_buffer_size', 10000);
        // 0 would make ltrim keep everything, a negative value would drop the newest entries
        $max = $max < 1 ? 10000 : $max;
        $json = json_encode($payload);

        // rpush(false) would store an empty entry that popBatch drops anyway
        if ($json === false) {
            return;
        }

        Redis::connection($this->connection)->transaction(function ($redis) use ($json, $max) {
            $redis->rpush($this->key, $json);
            $redis->ltrim($this->key, -$max, -1);
        });
    }

    public function popBatch(int $size): array
    {
        // SECURITY: Validate size to prevent memory exhaustion
        if ($size <= 0 || $size > 10000) {
            return [];
        }

        $redis = Redis::connection($this->connection);
        $batch = [];

        // PERFORMANCE FIX: Use Lua script for atomic batch pop
        // This eliminates N round-trips to Redis
        $luaScript = <<<'LUA'
            local key = KEYS[1]
            local count = tonumber(ARGV[1])
            local items = {}
            
            for i = 1, count do
                local item = redis.call('LPOP', key)
                if not item then
                    break
                end
                table.insert(items, item)
            end
            
            return items
LUA;

        try {
            $items = $redis->eval($luaScript, 1, $this->key, $size);

            if (is_array($items)) {
                foreach ($items as $item) {
                    $decoded = json_decode($item, true);
                    if ($decoded !== null) {
                        $batch[] = $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fallback to sequential pops if Lua script fails
            for ($i = 0; $i < $size; $i++) {
                $log = $redis->lpop($this->key);

                if (!$log) {
                    break;
                }

                $decoded = json_decode($log, true);
                if ($decoded !== null) {
                    $batch[] = $decoded;
                }
            }
        }

        return $batch;
    }

    public function size(): int
    {
        return Redis::connection($this->connection)->llen($this->key);
    }
}
