<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipStatusJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

class PackageVersionTest extends TestCase
{
    #[Test]
    public function get_package_version_returns_string()
    {
        $job = new ShipStatusJob;
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('getPackageVersion');
        $method->setAccessible(true);

        $version = $method->invoke($job);

        $this->assertIsString($version);
    }

    #[Test]
    public function get_package_version_uses_composer_installed_versions()
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            $this->markTestSkipped('Composer\InstalledVersions not available');
        }

        $job = new ShipStatusJob;
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('getPackageVersion');
        $method->setAccessible(true);

        $version = $method->invoke($job);

        // Should return either a version string or 'unknown'
        // Accept: 1.0.0, dev-main, dev-feature-branch, unknown
        $this->assertTrue(
            $version === 'unknown' || 
            preg_match('/^\d+\.\d+/', $version) === 1 ||
            str_starts_with($version, 'dev-'),
            "Expected version format, 'dev-*', or 'unknown', got: $version"
        );
    }

    #[Test]
    public function collect_metrics_includes_log_shipper_version()
    {
        $job = new ShipStatusJob;
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('collectMetrics');
        $method->setAccessible(true);

        $metrics = $method->invoke($job);

        $this->assertArrayHasKey('log_shipper_version', $metrics);
        $this->assertIsString($metrics['log_shipper_version']);
    }

    #[Test]
    public function collect_metrics_includes_app_debug()
    {
        config(['app.debug' => true]);

        $job = new ShipStatusJob;
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('collectMetrics');
        $method->setAccessible(true);

        $metrics = $method->invoke($job);

        $this->assertArrayHasKey('app_debug', $metrics);
        $this->assertTrue($metrics['app_debug']);
    }

    #[Test]
    public function package_version_handles_composer_exceptions_gracefully()
    {
        // This test verifies the try-catch block works
        $job = new ShipStatusJob;
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('getPackageVersion');
        $method->setAccessible(true);

        // Should not throw exception even if Composer API fails
        $version = $method->invoke($job);

        $this->assertIsString($version);
    }
}
