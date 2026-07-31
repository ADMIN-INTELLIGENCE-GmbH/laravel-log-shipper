<?php

namespace AdminIntelligence\LogShipper\Tests\Support;

use AdminIntelligence\LogShipper\Jobs\ShipStatusJob;
use AdminIntelligence\LogShipper\Status\SystemUpdatesCollector;

/**
 * Lets a test swap the update collector so payload wiring can be asserted
 * without querying the host's real package manager.
 */
class TestableShipStatusJob extends ShipStatusJob
{
    public ?SystemUpdatesCollector $fakeUpdatesCollector = null;

    protected function systemUpdatesCollector(): SystemUpdatesCollector
    {
        return $this->fakeUpdatesCollector ?? parent::systemUpdatesCollector();
    }
}
