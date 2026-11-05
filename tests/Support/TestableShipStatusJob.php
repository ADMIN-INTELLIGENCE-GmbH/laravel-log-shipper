<?php

namespace AdminIntelligence\LogShipper\Tests\Support;

use AdminIntelligence\LogShipper\Jobs\ShipStatusJob;
use AdminIntelligence\LogShipper\Status\CommandRunner;
use AdminIntelligence\LogShipper\Status\SystemUpdatesCollector;

/**
 * Lets a test swap the update collector so payload wiring can be asserted
 * without querying the host's real package manager.
 */
class TestableShipStatusJob extends ShipStatusJob
{
    public ?SystemUpdatesCollector $fakeUpdatesCollector = null;

    public ?CommandRunner $fakeRunner = null;

    protected function commandRunner(): CommandRunner
    {
        return $this->fakeRunner ?? parent::commandRunner();
    }

    protected function systemUpdatesCollector(): SystemUpdatesCollector
    {
        return $this->fakeUpdatesCollector ?? parent::systemUpdatesCollector();
    }
}
