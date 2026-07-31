<?php

declare(strict_types=1);

namespace AdminIntelligence\LogShipper\Status;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Collects pending operating system package updates.
 *
 * Every command here is read-only: no metadata refresh, no network access, no
 * writes, no sudo. Counts therefore reflect whatever the host's package
 * metadata already says, which is why `last_refresh` is reported alongside
 * them — stale metadata means stale counts.
 */
class SystemUpdatesCollector
{
    /**
     * Package managers in detection order. The first one installed wins.
     *
     * @var array<int, string>
     */
    protected array $managers = ['apt', 'dnf', 'yum', 'apk', 'pacman', 'brew'];

    public function __construct(protected CommandRunner $runner) {}

    /**
     * @return array{manager: string|null, supported: bool, total_count: int|null, security_count: int|null, packages: array<int, array{name: string, current_version: string|null, available_version: string|null, security: bool}>, truncated: bool, reboot_required: bool|null, last_refresh: string|null, error: string|null}
     */
    public function collect(): array
    {
        $manager = $this->detectManager();

        if ($manager === null) {
            return $this->payload(null, false, null, null, [], false, null, null, null);
        }

        try {
            $result = match ($manager) {
                'apt' => $this->aptUpdates(),
                'dnf', 'yum' => $this->rpmUpdates($manager),
                'apk' => $this->apkUpdates(),
                'pacman' => $this->pacmanUpdates(),
                'brew' => $this->brewUpdates(),
                default => $this->failure('Unsupported package manager.'),
            };
        } catch (Throwable $e) {
            $result = $this->failure('Could not read pending updates: ' . $e->getMessage());
        }

        $packages = $result['packages'];
        $truncated = false;

        if (!$this->includePackages()) {
            $packages = [];
        } else {
            $max = max(0, (int) config('log-shipper.status.updates.max_packages', 50));

            if (count($packages) > $max) {
                $packages = array_slice($packages, 0, $max);
                $truncated = true;
            }
        }

        return $this->payload(
            $manager,
            true,
            $result['total_count'],
            $result['security_count'],
            $packages,
            $truncated,
            $this->rebootRequired($manager),
            $this->lastRefresh($manager),
            $result['error']
        );
    }

    /**
     * @param  array<int, array{name: string, current_version: string|null, available_version: string|null, security: bool}>  $packages
     * @return array<string, mixed>
     */
    protected function payload(
        ?string $manager,
        ?bool $supported,
        ?int $totalCount,
        ?int $securityCount,
        array $packages,
        bool $truncated,
        ?bool $rebootRequired,
        ?string $lastRefresh,
        ?string $error
    ): array {
        return [
            'manager' => $manager,
            'supported' => (bool) $supported,
            'total_count' => $totalCount,
            'security_count' => $securityCount,
            'packages' => $packages,
            'truncated' => $truncated,
            'reboot_required' => $rebootRequired,
            'last_refresh' => $lastRefresh,
            'error' => $error,
        ];
    }

    protected function detectManager(): ?string
    {
        if (!in_array($this->osFamily(), ['Linux', 'Darwin', 'BSD'], true)) {
            return null;
        }

        foreach ($this->managers as $manager) {
            if ($this->runner->commandExists($manager)) {
                return $manager;
            }
        }

        return null;
    }

    /**
     * Debian and Ubuntu. Reads the already-downloaded package lists.
     *
     * @return array{total_count: int|null, security_count: int|null, packages: array<int, array<string, mixed>>, error: string|null}
     */
    protected function aptUpdates(): array
    {
        $output = $this->runner->run('apt list --upgradable 2>/dev/null', $this->timeout());

        if ($output === null) {
            return $this->failure('Could not read pending updates from apt.');
        }

        $packages = [];
        $securityCount = 0;

        foreach ($this->lines($output) as $line) {
            // e.g. openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]
            if (!preg_match('#^(?<name>[^/\s]+)/(?<origin>\S+)\s+(?<available>\S+)\s+\S+\s+\[upgradable from:\s*(?<current>[^\]]+)\]#', $line, $matches)) {
                continue;
            }

            $security = str_contains($matches['origin'], '-security');

            if ($security) {
                $securityCount++;
            }

            $packages[] = $this->package($matches['name'], trim($matches['current']), $matches['available'], $security);
        }

        return [
            'total_count' => count($packages),
            'security_count' => $securityCount,
            'packages' => $packages,
            'error' => null,
        ];
    }

    /**
     * RHEL family. `--cacheonly` keeps the check offline; `check-update` exits
     * 100 when updates are pending and 0 when the host is current.
     *
     * @return array{total_count: int|null, security_count: int|null, packages: array<int, array<string, mixed>>, error: string|null}
     */
    protected function rpmUpdates(string $binary): array
    {
        $result = $this->runner->runWithExitCode(
            "{$binary} --cacheonly check-update --quiet 2>/dev/null",
            $this->timeout()
        );

        if ($result['timed_out']) {
            return $this->failure("{$binary} check-update timed out.");
        }

        if ($result['exit_code'] === 0) {
            return ['total_count' => 0, 'security_count' => 0, 'packages' => [], 'error' => null];
        }

        if ($result['exit_code'] !== 100) {
            return $this->failure("{$binary} check-update exited with code " . var_export($result['exit_code'], true) . '.');
        }

        $packages = $this->parseRpmPackages($result['output']);
        $securityNames = $this->rpmSecurityNames($binary);

        foreach ($packages as $index => $package) {
            $packages[$index]['security'] = in_array($package['name'], $securityNames, true);
        }

        return [
            'total_count' => count($packages),
            'security_count' => $securityNames === null ? null : count($securityNames),
            'packages' => $packages,
            'error' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseRpmPackages(string $output): array
    {
        $packages = [];

        foreach ($this->lines($output) as $line) {
            // Anything after this header is a different kind of entry.
            if (str_starts_with($line, 'Obsoleting Packages')) {
                break;
            }

            if (str_starts_with($line, 'Last metadata') || str_starts_with($line, 'Security:')) {
                continue;
            }

            // e.g. openssl.x86_64    1:3.2.2-6.el9_5    baseos
            if (!preg_match('/^(?<name>\S+)\s+(?<available>\S+)\s+(?<repo>\S+)$/', $line, $matches)) {
                continue;
            }

            $packages[] = $this->package($matches['name'], null, $matches['available'], false);
        }

        return $packages;
    }

    /**
     * Names of packages with pending security errata, or null when the host
     * cannot answer the question.
     *
     * @return array<int, string>|null
     */
    protected function rpmSecurityNames(string $binary): ?array
    {
        $result = $this->runner->runWithExitCode(
            "{$binary} --cacheonly check-update --security --quiet 2>/dev/null",
            $this->timeout()
        );

        if ($result['timed_out'] || !in_array($result['exit_code'], [0, 100], true)) {
            return null;
        }

        return array_column($this->parseRpmPackages($result['output']), 'name');
    }

    /**
     * Alpine. `apk version -l '<'` lists packages older than the index.
     *
     * @return array{total_count: int|null, security_count: int|null, packages: array<int, array<string, mixed>>, error: string|null}
     */
    protected function apkUpdates(): array
    {
        $output = $this->runner->run("apk version -l '<' 2>/dev/null", $this->timeout());

        if ($output === null) {
            return $this->failure('Could not read pending updates from apk.');
        }

        $packages = [];

        foreach ($this->lines($output) as $line) {
            if (!str_contains($line, '<')) {
                continue;
            }

            [$installed, $available] = array_map('trim', explode('<', $line, 2));

            if (!preg_match('/^(?<name>.+)-(?<version>\d[^\s-]*(?:-r\d+)?)$/', $installed, $matches)) {
                continue;
            }

            $name = $matches['name'];
            $availableVersion = str_starts_with($available, $name . '-')
                ? substr($available, strlen($name) + 1)
                : $available;

            $packages[] = $this->package($name, $matches['version'], $availableVersion, false);
        }

        return [
            'total_count' => count($packages),
            'security_count' => null,
            'packages' => $packages,
            'error' => null,
        ];
    }

    /**
     * Arch. `pacman -Qu` reads the local sync database only.
     *
     * @return array{total_count: int|null, security_count: int|null, packages: array<int, array<string, mixed>>, error: string|null}
     */
    protected function pacmanUpdates(): array
    {
        $output = $this->runner->run('pacman -Qu 2>/dev/null', $this->timeout());

        if ($output === null) {
            return $this->failure('Could not read pending updates from pacman.');
        }

        $packages = [];

        foreach ($this->lines($output) as $line) {
            // e.g. linux 6.6.1-1 -> 6.6.2-1
            if (!preg_match('/^(?<name>\S+)\s+(?<current>\S+)\s+->\s+(?<available>\S+)$/', $line, $matches)) {
                continue;
            }

            $packages[] = $this->package($matches['name'], $matches['current'], $matches['available'], false);
        }

        return [
            'total_count' => count($packages),
            'security_count' => null,
            'packages' => $packages,
            'error' => null,
        ];
    }

    /**
     * Homebrew. HOMEBREW_NO_AUTO_UPDATE keeps brew from hitting the network.
     *
     * @return array{total_count: int|null, security_count: int|null, packages: array<int, array<string, mixed>>, error: string|null}
     */
    protected function brewUpdates(): array
    {
        $output = $this->runner->run('HOMEBREW_NO_AUTO_UPDATE=1 brew outdated --json=v2 2>/dev/null', $this->timeout());

        if ($output === null) {
            return $this->failure('Could not read pending updates from brew.');
        }

        $data = json_decode($output, true);

        if (!is_array($data)) {
            return $this->failure('Could not decode the brew outdated report.');
        }

        $packages = [];

        foreach (['formulae', 'casks'] as $group) {
            foreach ($data[$group] ?? [] as $entry) {
                if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name'])) {
                    continue;
                }

                $installed = $entry['installed_versions'] ?? null;

                if (is_array($installed)) {
                    $installed = end($installed) ?: null;
                }

                $packages[] = $this->package(
                    $entry['name'],
                    is_string($installed) ? $installed : null,
                    isset($entry['current_version']) && is_string($entry['current_version']) ? $entry['current_version'] : null,
                    false
                );
            }
        }

        return [
            'total_count' => count($packages),
            'security_count' => null,
            'packages' => $packages,
            'error' => null,
        ];
    }

    protected function rebootRequired(string $manager): ?bool
    {
        try {
            if ($manager === 'apt') {
                return file_exists($this->rebootRequiredPath());
            }

            if (in_array($manager, ['dnf', 'yum'], true) && $this->runner->commandExists('needs-restarting')) {
                $result = $this->runner->runWithExitCode('needs-restarting -r 2>/dev/null', 10);

                if ($result['timed_out'] || $result['exit_code'] === null) {
                    return null;
                }

                return $result['exit_code'] === 1;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    protected function lastRefresh(string $manager): ?string
    {
        try {
            foreach ($this->metadataPathsFor($manager) as $path) {
                if (!file_exists($path)) {
                    continue;
                }

                $timestamp = filemtime($path);

                if ($timestamp === false) {
                    continue;
                }

                return Carbon::createFromTimestamp($timestamp)->toIso8601String();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Paths whose modification time indicates when package metadata was last
     * refreshed, most precise first.
     *
     * @return array<int, string>
     */
    protected function metadataPathsFor(string $manager): array
    {
        return match ($manager) {
            'apt' => ['/var/lib/apt/periodic/update-success-stamp', '/var/lib/apt/lists'],
            'dnf' => ['/var/cache/dnf'],
            'yum' => ['/var/cache/yum'],
            'apk' => ['/var/cache/apk', '/var/lib/apk/db/installed'],
            'pacman' => ['/var/lib/pacman/sync'],
            default => [],
        };
    }

    protected function rebootRequiredPath(): string
    {
        return '/var/run/reboot-required';
    }

    protected function osFamily(): string
    {
        return PHP_OS_FAMILY;
    }

    protected function timeout(): int
    {
        return max(1, (int) config('log-shipper.status.updates.timeout', 15));
    }

    protected function includePackages(): bool
    {
        return (bool) config('log-shipper.status.updates.include_packages', true);
    }

    /**
     * @return array{name: string, current_version: string|null, available_version: string|null, security: bool}
     */
    protected function package(string $name, ?string $current, ?string $available, bool $security): array
    {
        return [
            'name' => $name,
            'current_version' => $current,
            'available_version' => $available,
            'security' => $security,
        ];
    }

    /**
     * @return array{total_count: null, security_count: null, packages: array<int, array<string, mixed>>, error: string}
     */
    protected function failure(string $message): array
    {
        return [
            'total_count' => null,
            'security_count' => null,
            'packages' => [],
            'error' => $message,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function lines(string $output): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
