<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipStatusJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class ShipStatusJobDiskSpaceTest extends TestCase
{
    #[Test]
    public function it_collects_disk_space_metrics_including_all_mounts(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.system' => true,
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();
            $system = $data['system'];

            if (!isset($system['disk_space'])) {
                return false;
            }

            $diskSpace = $system['disk_space'];

            // Check primary keys (backward compatibility)
            $hasPrimary = array_key_exists('total', $diskSpace)
                && array_key_exists('free', $diskSpace)
                && array_key_exists('used', $diskSpace);

            // Check for disks array
            $hasDisks = isset($diskSpace['disks']) && is_array($diskSpace['disks']);

            if ($hasDisks) {
                foreach ($diskSpace['disks'] as $disk) {
                    if (!isset($disk['path'], $disk['total'], $disk['free'], $disk['used'])) {
                        return false;
                    }
                }
            }

            return $hasPrimary && $hasDisks;
        });
    }
}
