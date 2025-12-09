<?php

namespace AdminIntelligence\LogShipper\Tests;

use AdminIntelligence\LogShipper\LogShipperServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LogShipperServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('log-shipper.enabled', true);
        $app['config']->set('log-shipper.api_endpoint', 'https://test-logs.example.com/api/ingest');
        $app['config']->set('log-shipper.api_key', 'test-api-key');
        $app['config']->set('log-shipper.queue_connection', 'sync');
        $app['config']->set('log-shipper.queue_name', 'default');
    }
}
