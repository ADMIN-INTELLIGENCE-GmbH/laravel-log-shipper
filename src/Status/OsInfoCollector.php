<?php

declare(strict_types=1);

namespace AdminIntelligence\LogShipper\Status;

use Throwable;

/**
 * Collects operating system identity: distribution, version, kernel, CPU
 * architecture.
 *
 * Cheap by design — on Linux this is a single file read and no shell at all.
 */
class OsInfoCollector
{
    public function __construct(
        protected CommandRunner $runner,
        protected string $osReleasePath = '/etc/os-release'
    ) {}

    /**
     * @return array{family: string, name: string|null, version: string|null, distro_id: string|null, kernel: string|null, architecture: string|null}
     */
    public function collect(): array
    {
        $family = $this->osFamily();

        $details = match ($family) {
            'Linux' => $this->linuxDetails(),
            'Darwin' => $this->darwinDetails(),
            'Windows' => $this->windowsDetails(),
            default => $this->genericDetails(),
        };

        return [
            'family' => $family,
            'name' => $details['name'],
            'version' => $details['version'],
            'distro_id' => $details['distro_id'],
            'kernel' => $this->uname('r'),
            'architecture' => $this->uname('m'),
        ];
    }

    protected function osFamily(): string
    {
        return PHP_OS_FAMILY;
    }

    /**
     * @return array{name: string|null, version: string|null, distro_id: string|null}
     */
    protected function linuxDetails(): array
    {
        $release = $this->parseOsRelease();

        $name = $release['PRETTY_NAME'] ?? null;

        if ($name === null && isset($release['NAME'])) {
            $name = trim($release['NAME'] . ' ' . ($release['VERSION'] ?? ''));
        }

        return [
            'name' => $this->nullIfEmpty($name),
            'version' => $this->nullIfEmpty($release['VERSION_ID'] ?? $release['VERSION'] ?? null),
            'distro_id' => $this->nullIfEmpty($release['ID'] ?? null),
        ];
    }

    /**
     * @return array{name: string|null, version: string|null, distro_id: string|null}
     */
    protected function darwinDetails(): array
    {
        $name = $this->runner->run('sw_vers -productName 2>/dev/null', 5);
        $version = $this->runner->run('sw_vers -productVersion 2>/dev/null', 5);

        return [
            'name' => $this->nullIfEmpty($name) ?? $this->uname('s'),
            'version' => $this->nullIfEmpty($version),
            'distro_id' => 'macos',
        ];
    }

    /**
     * @return array{name: string|null, version: string|null, distro_id: string|null}
     */
    protected function windowsDetails(): array
    {
        return [
            'name' => $this->uname('s'),
            'version' => $this->uname('r'),
            'distro_id' => 'windows',
        ];
    }

    /**
     * @return array{name: string|null, version: string|null, distro_id: string|null}
     */
    protected function genericDetails(): array
    {
        return [
            'name' => $this->uname('s'),
            'version' => $this->uname('r'),
            'distro_id' => null,
        ];
    }

    /**
     * Parse an os-release file into key/value pairs, ignoring anything that is
     * not a `KEY=value` line.
     *
     * @return array<string, string>
     */
    protected function parseOsRelease(): array
    {
        try {
            if (!is_file($this->osReleasePath) || !is_readable($this->osReleasePath)) {
                return [];
            }

            $contents = file_get_contents($this->osReleasePath);

            if ($contents === false) {
                return [];
            }

            $values = [];

            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);

                if ($key === '') {
                    continue;
                }

                $values[$key] = trim(trim($value), "\"'");
            }

            return $values;
        } catch (Throwable) {
            return [];
        }
    }

    protected function uname(string $mode): ?string
    {
        try {
            return $this->nullIfEmpty(php_uname($mode));
        } catch (Throwable) {
            return null;
        }
    }

    protected function nullIfEmpty(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
