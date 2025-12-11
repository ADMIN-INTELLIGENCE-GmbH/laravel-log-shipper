<?php

namespace AdminIntelligence\LogShipper\Console\Commands;

use AdminIntelligence\LogShipper\Jobs\ShipStatusJob;
use Illuminate\Console\Command;

class ShipStatusCommand extends Command
{
    protected $signature = 'log-shipper:status';

    protected $description = 'Ship application status metrics to the log server';

    public function handle(): void
    {
        if (!config('log-shipper.status.enabled', false)) {
            $this->info('Log Shipper status push is disabled.');

            return;
        }

        $connection = config('log-shipper.status.queue_connection', 'default');
        $queue = config('log-shipper.status.queue_name', 'default');

        if ($connection === 'default') {
            $connection = config('queue.default');
        }

        ShipStatusJob::dispatch()
            ->onConnection($connection)
            ->onQueue($queue);

        $this->info('Status job dispatched.');
    }
}
