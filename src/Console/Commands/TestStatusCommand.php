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

        // Create a temporary job instance to access the protected methods
        $job = new ShipStatusJob();
        $reflectionClass = new \ReflectionClass($job);

        // Test collectMetrics (the full payload)
        $this->info('Full Metrics Payload:');
        $collectMetrics = $reflectionClass->getMethod('collectMetrics');
        $collectMetrics->setAccessible(true);
        $fullMetrics = $collectMetrics->invoke($job);
        
        $this->line(json_encode($fullMetrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        $this->newLine();
        $this->info('✓ Test complete!');
    }
}
