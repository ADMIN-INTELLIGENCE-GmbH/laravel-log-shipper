<?php

namespace AdminIntelligence\LogShipper\Buffer;

interface LogBufferInterface
{
    /**
     * Add a log payload to the buffer.
     */
    public function push(array $payload): void;

    /**
     * Retrieve and remove a batch of logs from the buffer.
     *
     * @param  int  $size  Maximum number of logs to retrieve
     * @return array The batch of logs
     */
    public function popBatch(int $size): array;

    /**
     * Get the current size of the buffer (approximate).
     */
    public function size(): int;
}
