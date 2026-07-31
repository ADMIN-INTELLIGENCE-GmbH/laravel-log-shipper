<?php

declare(strict_types=1);

namespace AdminIntelligence\LogShipper\Status;

use Throwable;

/**
 * Collects host runtime details that help tell instances apart: hostname,
 * app URL, timezone, locale, web server, PHP SAPI and loaded extensions.
 *
 * Pure PHP — no shell commands and no DNS lookups, so it is safe to leave on.
 */
class HostInfoCollector
{
    /**
     * @return array{hostname: string|null, app_url: string|null, timezone: string|null, locale: string|null, server_software: string|null, php_sapi: string, php_extensions: array<int, string>}
     */
    public function collect(): array
    {
        return [
            'hostname' => $this->hostname(),
            'app_url' => $this->stringOrNull(config('app.url')),
            'timezone' => $this->timezone(),
            'locale' => $this->locale(),
            'server_software' => $this->stringOrNull($_SERVER['SERVER_SOFTWARE'] ?? null),
            'php_sapi' => PHP_SAPI,
            'php_extensions' => $this->extensions(),
        ];
    }

    protected function hostname(): ?string
    {
        $hostname = gethostname();

        return $hostname === false ? null : $hostname;
    }

    protected function timezone(): ?string
    {
        return $this->stringOrNull(config('app.timezone')) ?? date_default_timezone_get();
    }

    protected function locale(): ?string
    {
        try {
            return $this->stringOrNull(app()->getLocale());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    protected function extensions(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        return array_values($extensions);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
