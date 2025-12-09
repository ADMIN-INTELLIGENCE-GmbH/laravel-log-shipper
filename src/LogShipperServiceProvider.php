<?php

namespace AdminIntelligence\LogShipper;

use Illuminate\Support\ServiceProvider;

class LogShipperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/log-shipper.php',
            'log-shipper'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/log-shipper.php' => config_path('log-shipper.php'),
            ], 'log-shipper-config');

            $this->warnIfMisconfigured();
        }
    }

    protected function warnIfMisconfigured(): void
    {
        $endpoint = config('log-shipper.api_endpoint');
        $apiKey = config('log-shipper.api_key');
        $enabled = config('log-shipper.enabled', true);

        if ($enabled && (empty($endpoint) || empty($apiKey))) {
            $missing = [];
            if (empty($endpoint)) {
                $missing[] = 'LOG_SHIPPER_ENDPOINT';
            }
            if (empty($apiKey)) {
                $missing[] = 'LOG_SHIPPER_KEY';
            }

            $this->app->booted(function () use ($missing) {
                $this->commands([]);
                if (class_exists(\Illuminate\Console\Command::class)) {
                    \Illuminate\Support\Facades\Log::channel('stderr')->warning(
                        '[Log Shipper] Missing configuration: ' . implode(', ', $missing) . '. Log shipping is disabled until configured.'
                    );
                }
            });
        }
    }
}
