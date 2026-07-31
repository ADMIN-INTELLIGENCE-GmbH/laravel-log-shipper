<?php

namespace AdminIntelligence\LogShipper\Tests\Unit\Status;

use AdminIntelligence\LogShipper\Tests\Support\FakeCommandRunner;
use AdminIntelligence\LogShipper\Tests\Support\FakeSystemUpdatesCollector;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class SystemUpdatesCollectorTest extends TestCase
{
    protected function aptOutput(): string
    {
        return <<<'OUT'
Listing...
libssl3t64/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]
openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]
curl/noble-updates 8.5.0-2ubuntu10.6 amd64 [upgradable from: 8.5.0-2ubuntu10.5]
OUT;
    }

    protected function collector(FakeCommandRunner $runner): FakeSystemUpdatesCollector
    {
        return new FakeSystemUpdatesCollector($runner);
    }

    protected function aptRunner(?string $output = null): FakeCommandRunner
    {
        return new FakeCommandRunner(
            ['apt list --upgradable' => $output ?? $this->aptOutput()],
            ['apt']
        );
    }

    #[Test]
    public function it_counts_and_parses_pending_apt_updates(): void
    {
        $updates = $this->collector($this->aptRunner())->collect();

        $this->assertSame('apt', $updates['manager']);
        $this->assertTrue($updates['supported']);
        $this->assertSame(3, $updates['total_count']);
        $this->assertFalse($updates['truncated']);
        $this->assertNull($updates['error']);

        $this->assertSame([
            'name' => 'openssl',
            'current_version' => '3.0.13-0ubuntu3.4',
            'available_version' => '3.0.13-0ubuntu3.5',
            'security' => true,
        ], $updates['packages'][1]);

        $this->assertSame([
            'name' => 'curl',
            'current_version' => '8.5.0-2ubuntu10.5',
            'available_version' => '8.5.0-2ubuntu10.6',
            'security' => false,
        ], $updates['packages'][2]);
    }

    #[Test]
    public function it_counts_apt_security_updates_separately(): void
    {
        $updates = $this->collector($this->aptRunner())->collect();

        $this->assertSame(2, $updates['security_count']);
    }

    #[Test]
    public function it_never_refreshes_apt_metadata(): void
    {
        $runner = $this->aptRunner();

        $this->collector($runner)->collect();

        foreach ($runner->commands as $command) {
            $this->assertStringNotContainsString('apt-get update', $command);
            $this->assertStringNotContainsString('apt update', $command);
            $this->assertStringNotContainsString('sudo', $command);
        }
    }

    #[Test]
    public function it_reports_a_pending_reboot_when_the_flag_file_exists(): void
    {
        $flag = tempnam(sys_get_temp_dir(), 'reboot_');

        $collector = $this->collector($this->aptRunner());
        $collector->rebootPath = $flag;

        $updates = $collector->collect();

        unlink($flag);

        $this->assertTrue($updates['reboot_required']);
    }

    #[Test]
    public function it_reports_no_pending_reboot_when_the_flag_file_is_absent(): void
    {
        $updates = $this->collector($this->aptRunner())->collect();

        $this->assertFalse($updates['reboot_required']);
    }

    #[Test]
    public function it_reports_when_the_package_metadata_was_last_refreshed(): void
    {
        $stamp = tempnam(sys_get_temp_dir(), 'apt_stamp_');
        touch($stamp, 1753660800);

        $collector = $this->collector($this->aptRunner());
        $collector->metadataPaths = ['apt' => [$stamp]];

        $updates = $collector->collect();

        unlink($stamp);

        $this->assertSame(Carbon::createFromTimestamp(1753660800)->toIso8601String(), $updates['last_refresh']);
    }

    #[Test]
    public function it_reports_a_null_last_refresh_when_no_metadata_path_exists(): void
    {
        $updates = $this->collector($this->aptRunner())->collect();

        $this->assertNull($updates['last_refresh']);
    }

    #[Test]
    public function it_truncates_the_package_list_at_the_configured_maximum(): void
    {
        config(['log-shipper.status.updates.max_packages' => 2]);

        $updates = $this->collector($this->aptRunner())->collect();

        $this->assertSame(3, $updates['total_count']);
        $this->assertCount(2, $updates['packages']);
        $this->assertTrue($updates['truncated']);
    }

    #[Test]
    public function it_omits_package_names_when_disabled(): void
    {
        config(['log-shipper.status.updates.include_packages' => false]);

        $updates = $this->collector($this->aptRunner())->collect();

        $this->assertSame(3, $updates['total_count']);
        $this->assertSame(2, $updates['security_count']);
        $this->assertSame([], $updates['packages']);
        $this->assertFalse($updates['truncated']);
    }

    #[Test]
    public function it_reports_an_error_when_the_package_manager_produces_nothing(): void
    {
        $runner = new FakeCommandRunner([], ['apt']);

        $updates = $this->collector($runner)->collect();

        $this->assertSame('apt', $updates['manager']);
        $this->assertTrue($updates['supported']);
        $this->assertNull($updates['total_count']);
        $this->assertNotNull($updates['error']);
    }

    #[Test]
    public function it_parses_dnf_updates_when_the_check_exits_with_one_hundred(): void
    {
        $runner = new FakeCommandRunner([
            'check-update --security' => [
                'output' => "openssl.x86_64                  1:3.2.2-6.el9_5             baseos\n",
                'exit_code' => 100,
            ],
            'check-update' => [
                'output' => <<<'OUT'
Last metadata expiration check: 2:31:12 ago on Mon 28 Jul 2026 08:00:00 AM UTC.

openssl.x86_64                  1:3.2.2-6.el9_5             baseos
kernel.x86_64                   5.14.0-503.15.1.el9_5       baseos

Obsoleting Packages
grub2-tools.x86_64              1:2.06-93.el9               baseos
OUT,
                'exit_code' => 100,
            ],
        ], ['dnf']);

        $updates = $this->collector($runner)->collect();

        $this->assertSame('dnf', $updates['manager']);
        $this->assertSame(2, $updates['total_count']);
        $this->assertSame(1, $updates['security_count']);
        $this->assertSame('openssl.x86_64', $updates['packages'][0]['name']);
        $this->assertSame('1:3.2.2-6.el9_5', $updates['packages'][0]['available_version']);
        $this->assertNull($updates['packages'][0]['current_version']);
        $this->assertTrue($updates['packages'][0]['security']);
        $this->assertFalse($updates['packages'][1]['security']);
    }

    #[Test]
    public function it_reports_zero_dnf_updates_when_the_check_exits_with_zero(): void
    {
        $runner = new FakeCommandRunner([
            'check-update' => ['output' => '', 'exit_code' => 0],
        ], ['dnf']);

        $updates = $this->collector($runner)->collect();

        $this->assertSame(0, $updates['total_count']);
        $this->assertSame([], $updates['packages']);
        $this->assertNull($updates['error']);
    }

    #[Test]
    public function it_reports_an_error_when_the_dnf_check_fails(): void
    {
        $runner = new FakeCommandRunner([
            'check-update' => ['output' => 'Error: Failed to download metadata', 'exit_code' => 1],
        ], ['dnf']);

        $updates = $this->collector($runner)->collect();

        $this->assertNull($updates['total_count']);
        $this->assertNotNull($updates['error']);
    }

    #[Test]
    public function it_reads_dnf_from_cache_only_so_no_metadata_download_happens(): void
    {
        $runner = new FakeCommandRunner([
            'check-update' => ['output' => '', 'exit_code' => 0],
        ], ['dnf']);

        $this->collector($runner)->collect();

        $this->assertNotEmpty($runner->commands);

        foreach ($runner->commands as $command) {
            $this->assertStringContainsString('--cacheonly', $command);
        }
    }

    #[Test]
    public function it_parses_yum_updates(): void
    {
        $runner = new FakeCommandRunner([
            'check-update' => [
                'output' => "openssl.x86_64                  1:3.2.2-6.el9_5             base\n",
                'exit_code' => 100,
            ],
        ], ['yum']);

        $updates = $this->collector($runner)->collect();

        $this->assertSame('yum', $updates['manager']);
        $this->assertSame(1, $updates['total_count']);
    }

    #[Test]
    public function it_parses_apk_updates(): void
    {
        $runner = new FakeCommandRunner([
            'apk version' => <<<'OUT'
Installed:                                Available:
busybox-1.36.1-r29                      < busybox-1.36.1-r31
musl-1.2.5-r0                           < musl-1.2.5-r1
OUT,
        ], ['apk']);

        $updates = $this->collector($runner)->collect();

        $this->assertSame('apk', $updates['manager']);
        $this->assertSame(2, $updates['total_count']);
        $this->assertNull($updates['security_count']);
        $this->assertSame([
            'name' => 'busybox',
            'current_version' => '1.36.1-r29',
            'available_version' => '1.36.1-r31',
            'security' => false,
        ], $updates['packages'][0]);
    }

    #[Test]
    public function it_parses_pacman_updates(): void
    {
        $runner = new FakeCommandRunner([
            'pacman -Qu' => "linux 6.6.1-1 -> 6.6.2-1\nopenssl 3.2.0-1 -> 3.2.1-1",
        ], ['pacman']);

        $updates = $this->collector($runner)->collect();

        $this->assertSame('pacman', $updates['manager']);
        $this->assertSame(2, $updates['total_count']);
        $this->assertSame([
            'name' => 'linux',
            'current_version' => '6.6.1-1',
            'available_version' => '6.6.2-1',
            'security' => false,
        ], $updates['packages'][0]);
    }

    #[Test]
    public function it_parses_brew_updates_without_auto_updating(): void
    {
        $runner = new FakeCommandRunner([
            'brew outdated' => '{"formulae":[{"name":"php","installed_versions":["8.4.1"],"current_version":"8.4.2"}],"casks":[{"name":"firefox","installed_versions":"131.0","current_version":"132.0"}]}',
        ], ['brew']);

        $collector = $this->collector($runner);
        $collector->family = 'Darwin';

        $updates = $collector->collect();

        $this->assertSame('brew', $updates['manager']);
        $this->assertSame(2, $updates['total_count']);
        $this->assertNull($updates['security_count']);
        $this->assertNull($updates['reboot_required']);
        $this->assertSame([
            'name' => 'php',
            'current_version' => '8.4.1',
            'available_version' => '8.4.2',
            'security' => false,
        ], $updates['packages'][0]);
        $this->assertSame('firefox', $updates['packages'][1]['name']);

        foreach ($runner->commands as $command) {
            $this->assertStringContainsString('HOMEBREW_NO_AUTO_UPDATE=1', $command);
        }
    }

    #[Test]
    public function it_reports_unsupported_when_no_package_manager_is_installed(): void
    {
        $updates = $this->collector(new FakeCommandRunner)->collect();

        $this->assertNull($updates['manager']);
        $this->assertFalse($updates['supported']);
        $this->assertNull($updates['total_count']);
        $this->assertNull($updates['security_count']);
        $this->assertSame([], $updates['packages']);
        $this->assertNull($updates['error']);
    }

    #[Test]
    public function it_reports_unsupported_on_windows_without_running_commands(): void
    {
        $runner = new FakeCommandRunner([], ['apt']);

        $collector = $this->collector($runner);
        $collector->family = 'Windows';

        $updates = $collector->collect();

        $this->assertFalse($updates['supported']);
        $this->assertSame([], $runner->commands);
    }

    #[Test]
    public function it_prefers_apt_when_several_package_managers_are_present(): void
    {
        $runner = new FakeCommandRunner(
            ['apt list --upgradable' => $this->aptOutput()],
            ['apt', 'dnf', 'brew']
        );

        $updates = $this->collector($runner)->collect();

        $this->assertSame('apt', $updates['manager']);
    }

    #[Test]
    public function it_returns_a_stable_key_set(): void
    {
        $updates = $this->collector($this->aptRunner())->collect();

        $this->assertSame([
            'manager',
            'supported',
            'total_count',
            'security_count',
            'packages',
            'truncated',
            'reboot_required',
            'last_refresh',
            'error',
        ], array_keys($updates));
    }

    #[Test]
    public function it_asks_the_package_manager_only_for_pending_updates_on_the_reboot_check(): void
    {
        $runner = new FakeCommandRunner([
            'check-update' => ['output' => '', 'exit_code' => 0],
            'needs-restarting -r' => ['output' => 'Reboot is required', 'exit_code' => 1],
        ], ['dnf', 'needs-restarting']);

        $updates = $this->collector($runner)->collect();

        $this->assertTrue($updates['reboot_required']);
    }

    #[Test]
    public function it_reports_no_reboot_when_needs_restarting_exits_cleanly(): void
    {
        $runner = new FakeCommandRunner([
            'check-update' => ['output' => '', 'exit_code' => 0],
            'needs-restarting -r' => ['output' => 'No core libraries or services have been updated', 'exit_code' => 0],
        ], ['dnf', 'needs-restarting']);

        $updates = $this->collector($runner)->collect();

        $this->assertFalse($updates['reboot_required']);
    }

    #[Test]
    public function it_reports_an_unknown_reboot_state_when_needs_restarting_is_missing(): void
    {
        $runner = new FakeCommandRunner([
            'check-update' => ['output' => '', 'exit_code' => 0],
        ], ['dnf']);

        $updates = $this->collector($runner)->collect();

        $this->assertNull($updates['reboot_required']);
    }
}
