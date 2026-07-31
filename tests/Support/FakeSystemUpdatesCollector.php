<?php

namespace AdminIntelligence\LogShipper\Tests\Support;

use AdminIntelligence\LogShipper\Status\SystemUpdatesCollector;

/**
 * Lets a test pin the OS family and the host paths the collector inspects, so
 * every package-manager branch is reachable from a single CI runner.
 */
class FakeSystemUpdatesCollector extends SystemUpdatesCollector
{
    public string $family = 'Linux';

    public string $rebootPath = '/log-shipper/no-such-reboot-flag';

    /**
     * @var array<string, array<int, string>>
     */
    public array $metadataPaths = [];

    protected function osFamily(): string
    {
        return $this->family;
    }

    protected function rebootRequiredPath(): string
    {
        return $this->rebootPath;
    }

    protected function metadataPathsFor(string $manager): array
    {
        return $this->metadataPaths[$manager] ?? [];
    }
}
