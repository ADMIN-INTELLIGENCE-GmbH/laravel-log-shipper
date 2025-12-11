<?php

namespace AdminIntelligence\LogShipper\Console\Commands;

use AdminIntelligence\LogShipper\Buffer\LogBufferInterface;
use AdminIntelligence\LogShipper\Jobs\ShipBatchJob;
use Illuminate\Console\Command;

class ShipBatchLogsCommand extends Command
{
    protected $signature = 'log-shipper:ship-batch';

    protected $description = 'Ship buffered logs in batches';

    public function handle(): void
    {
        if (!config('log-shipper.batch.enabled', false)) {
            $this->info('Batch shipping is disabled.');
            return;
        }

        $size = (int) config('log-shipper.batch.size', 100);
        
        try {
            /** @var LogBufferInterface $buffer */
            $buffer = app(LogBufferInterface::class);
        } catch (\Throwable $e) {
            $this->error('Could not resolve LogBufferInterface: ' . $e->getMessage());
            return;
        }

        // Run for up to 55 seconds to avoid overlapping with the next minute's schedule
        $endTime = now()->addSeconds(55);
        $batchesProcessed = 0;

        while (now() < $endTime) {
            $batch = $buffer->popBatch($size);

            if (empty($batch)) {
                // Buffer is empty, we can stop
                break;
            }

            $this->dispatchBatch($batch);
            $batchesProcessed++;
        }

        $this->info("Processed {$batchesProcessed} batches.");
    }

    protected function dispatchBatch(array $batch): void
    {
        $connection = config('log-shipper.queue_connection', 'default');
        $queue = config('log-shipper.queue_name', 'default');

        if ($connection === 'default') {
            $connection = config('queue.default');
        }

        ShipBatchJob::dispatch($batch)
            ->onConnection($connection)
            ->onQueue($queue);
    }
}
