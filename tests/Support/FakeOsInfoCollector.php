<?php

namespace AdminIntelligence\LogShipper\Tests\Support;

use AdminIntelligence\LogShipper\Status\OsInfoCollector;

/**
 * Lets a test pin the OS family so every platform branch is reachable from
 * a single CI runner.
 */
class FakeOsInfoCollector extends OsInfoCollector
{
    public string $family = 'Linux';

    protected function osFamily(): string
    {
        return $this->family;
    }
}
