<?php

namespace AdminIntelligence\LogShipper\Console\Commands;

use AdminIntelligence\LogShipper\Jobs\ShipStatusJob;
use Illuminate\Console\Command;

class TestStatusCommand extends Command
{
    protected $signature = 'log-shipper:test-status';

    protected $description = 'Test the status metrics collection (dry run)';

    public function handle(): void
    {
        $this->info('Testing status metrics collection...');
        $this->newLine();

        $this->info('Full Metrics Payload:');
        $fullMetrics = (new ShipStatusJob)->collectMetrics();

        $this->line(json_encode($fullMetrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info('✓ Test complete!');
    }
}
