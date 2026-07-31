<?php

namespace AdminIntelligence\LogShipper\Tests\Unit\Status;

use AdminIntelligence\LogShipper\Tests\Support\FakeCommandRunner;
use AdminIntelligence\LogShipper\Tests\Support\FakeOsInfoCollector;
use AdminIntelligence\LogShipper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class OsInfoCollectorTest extends TestCase
{
    protected function fixture(string $name): string
    {
        return __DIR__ . '/../../fixtures/os-release/' . $name;
    }

    protected function linuxCollector(string $fixture, ?FakeCommandRunner $runner = null): FakeOsInfoCollector
    {
        $collector = new FakeOsInfoCollector($runner ?? new FakeCommandRunner, $this->fixture($fixture));
        $collector->family = 'Linux';

        return $collector;
    }

    #[Test]
    public function it_reads_distribution_details_from_os_release(): void
    {
        $info = $this->linuxCollector('ubuntu')->collect();

        $this->assertSame('Linux', $info['family']);
        $this->assertSame('Ubuntu 24.04.1 LTS', $info['name']);
        $this->assertSame('24.04', $info['version']);
        $this->assertSame('ubuntu', $info['distro_id']);
    }

    #[Test]
    public function it_strips_quotes_from_os_release_values(): void
    {
        $info = $this->linuxCollector('almalinux')->collect();

        $this->assertSame('almalinux', $info['distro_id']);
        $this->assertSame('9.4', $info['version']);
        $this->assertSame('AlmaLinux 9.4 (Seafoam Ocelot)', $info['name']);
    }

    #[Test]
    public function it_reads_unquoted_os_release_values(): void
    {
        $info = $this->linuxCollector('alpine')->collect();

        $this->assertSame('alpine', $info['distro_id']);
        $this->assertSame('3.20.3', $info['version']);
    }

    #[Test]
    public function it_falls_back_to_name_and_version_when_pretty_fields_are_missing(): void
    {
        $info = $this->linuxCollector('minimal')->collect();

        $this->assertSame('Frobnix 7 (Wobble)', $info['name']);
        $this->assertSame('7 (Wobble)', $info['version']);
    }

    #[Test]
    public function it_returns_null_distribution_fields_when_os_release_is_missing(): void
    {
        $info = $this->linuxCollector('does-not-exist')->collect();

        $this->assertNull($info['name']);
        $this->assertNull($info['version']);
        $this->assertNull($info['distro_id']);
        $this->assertSame('Linux', $info['family']);
    }

    #[Test]
    public function it_tolerates_a_malformed_os_release_file(): void
    {
        $info = $this->linuxCollector('malformed')->collect();

        $this->assertNull($info['name']);
        $this->assertNull($info['version']);
        $this->assertNull($info['distro_id']);
    }

    #[Test]
    public function it_always_reports_kernel_and_architecture(): void
    {
        $info = $this->linuxCollector('ubuntu')->collect();

        $this->assertSame(php_uname('r'), $info['kernel']);
        $this->assertSame(php_uname('m'), $info['architecture']);
    }

    #[Test]
    public function it_does_not_shell_out_on_linux(): void
    {
        $runner = new FakeCommandRunner;

        $this->linuxCollector('ubuntu', $runner)->collect();

        $this->assertSame([], $runner->commands);
    }

    #[Test]
    public function it_reads_macos_details_from_sw_vers(): void
    {
        $runner = new FakeCommandRunner([
            'sw_vers -productName' => 'macOS',
            'sw_vers -productVersion' => '15.1',
        ]);

        $collector = new FakeOsInfoCollector($runner, $this->fixture('does-not-exist'));
        $collector->family = 'Darwin';

        $info = $collector->collect();

        $this->assertSame('Darwin', $info['family']);
        $this->assertSame('macOS', $info['name']);
        $this->assertSame('15.1', $info['version']);
        $this->assertSame('macos', $info['distro_id']);
    }

    #[Test]
    public function it_falls_back_to_uname_when_sw_vers_is_unavailable(): void
    {
        $collector = new FakeOsInfoCollector(new FakeCommandRunner, $this->fixture('does-not-exist'));
        $collector->family = 'Darwin';

        $info = $collector->collect();

        $this->assertSame(php_uname('s'), $info['name']);
        $this->assertNull($info['version']);
    }

    #[Test]
    public function it_reports_windows_without_shelling_out(): void
    {
        $runner = new FakeCommandRunner;

        $collector = new FakeOsInfoCollector($runner, $this->fixture('does-not-exist'));
        $collector->family = 'Windows';

        $info = $collector->collect();

        $this->assertSame('windows', $info['distro_id']);
        $this->assertSame(php_uname('s'), $info['name']);
        $this->assertSame(php_uname('r'), $info['version']);
        $this->assertSame([], $runner->commands);
    }

    #[Test]
    public function it_returns_a_stable_key_set_for_every_platform(): void
    {
        $expected = ['family', 'name', 'version', 'distro_id', 'kernel', 'architecture'];

        foreach (['Linux', 'Darwin', 'Windows', 'BSD'] as $family) {
            $collector = new FakeOsInfoCollector(new FakeCommandRunner, $this->fixture('does-not-exist'));
            $collector->family = $family;

            $this->assertSame($expected, array_keys($collector->collect()), "Key set differs for {$family}");
        }
    }
}
