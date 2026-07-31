<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipStatusJob;
use AdminIntelligence\LogShipper\Tests\Support\FakeCommandRunner;
use AdminIntelligence\LogShipper\Tests\Support\FakeSystemUpdatesCollector;
use AdminIntelligence\LogShipper\Tests\Support\TestableShipStatusJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class ShipStatusJobHostMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics' => [],
        ]);
    }

    protected function assertPayloadHasKey(string $key): void
    {
        Http::assertSent(fn ($request) => array_key_exists($key, $request->data()));
    }

    protected function assertPayloadMissingKey(string $key): void
    {
        Http::assertSent(fn ($request) => !array_key_exists($key, $request->data()));
    }

    #[Test]
    public function it_ships_os_information_when_enabled(): void
    {
        config(['log-shipper.status.metrics.os' => true]);

        (new ShipStatusJob)->handle();

        Http::assertSent(function ($request) {
            $os = $request->data()['os'] ?? null;

            return is_array($os)
                && $os['family'] === PHP_OS_FAMILY
                && array_key_exists('name', $os)
                && array_key_exists('version', $os)
                && array_key_exists('distro_id', $os)
                && array_key_exists('kernel', $os)
                && array_key_exists('architecture', $os);
        });
    }

    #[Test]
    public function it_omits_os_information_when_disabled(): void
    {
        config(['log-shipper.status.metrics.os' => false]);

        (new ShipStatusJob)->handle();

        $this->assertPayloadMissingKey('os');
    }

    #[Test]
    public function it_omits_os_information_when_the_metric_is_not_configured(): void
    {
        (new ShipStatusJob)->handle();

        $this->assertPayloadMissingKey('os');
    }

    #[Test]
    public function it_ships_host_information_when_enabled(): void
    {
        config(['log-shipper.status.metrics.host' => true]);

        (new ShipStatusJob)->handle();

        Http::assertSent(function ($request) {
            $host = $request->data()['host'] ?? null;

            return is_array($host)
                && $host['hostname'] === gethostname()
                && $host['php_sapi'] === PHP_SAPI
                && is_array($host['php_extensions']);
        });
    }

    #[Test]
    public function it_omits_host_information_when_disabled(): void
    {
        config(['log-shipper.status.metrics.host' => false]);

        (new ShipStatusJob)->handle();

        $this->assertPayloadMissingKey('host');
    }

    #[Test]
    public function it_omits_host_information_when_the_metric_is_not_configured(): void
    {
        (new ShipStatusJob)->handle();

        $this->assertPayloadMissingKey('host');
    }

    #[Test]
    public function it_ships_pending_updates_when_enabled(): void
    {
        config(['log-shipper.status.metrics.system_updates' => true]);

        $job = new TestableShipStatusJob;
        $job->fakeUpdatesCollector = new FakeSystemUpdatesCollector(new FakeCommandRunner(
            ['apt list --upgradable' => "Listing...\ncurl/noble-updates 8.5.0-2ubuntu10.6 amd64 [upgradable from: 8.5.0-2ubuntu10.5]"],
            ['apt']
        ));

        $job->handle();

        Http::assertSent(function ($request) {
            $updates = $request->data()['updates'] ?? null;

            return is_array($updates)
                && $updates['manager'] === 'apt'
                && $updates['supported'] === true
                && $updates['total_count'] === 1
                && $updates['packages'][0]['name'] === 'curl';
        });
    }

    #[Test]
    public function it_omits_pending_updates_when_disabled(): void
    {
        config(['log-shipper.status.metrics.system_updates' => false]);

        (new ShipStatusJob)->handle();

        $this->assertPayloadMissingKey('updates');
    }

    #[Test]
    public function it_omits_pending_updates_when_the_metric_is_not_configured(): void
    {
        (new ShipStatusJob)->handle();

        $this->assertPayloadMissingKey('updates');
    }

    #[Test]
    public function it_does_not_query_the_package_manager_when_updates_are_disabled(): void
    {
        config(['log-shipper.status.metrics.system_updates' => false]);

        $runner = new FakeCommandRunner([], ['apt']);

        $job = new TestableShipStatusJob;
        $job->fakeUpdatesCollector = new FakeSystemUpdatesCollector($runner);

        $job->handle();

        $this->assertSame([], $runner->commands);
    }

    #[Test]
    public function it_still_ships_the_core_payload_alongside_the_new_blocks(): void
    {
        config([
            'log-shipper.status.metrics.os' => true,
            'log-shipper.status.metrics.host' => true,
        ]);

        (new ShipStatusJob)->handle();

        $this->assertPayloadHasKey('timestamp');
        $this->assertPayloadHasKey('app_name');
        $this->assertPayloadHasKey('instance_id');
        $this->assertPayloadHasKey('log_shipper_version');
    }
}
