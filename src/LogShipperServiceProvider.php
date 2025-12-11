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

            $this->commands([
                \AdminIntelligence\LogShipper\Console\Commands\ShipStatusCommand::class,
                \AdminIntelligence\LogShipper\Console\Commands\TestStatusCommand::class,
            ]);

            $this->warnIfMisconfigured();
            $this->scheduleStatusPush();
        }
    }

    protected function scheduleStatusPush(): void
    {
        if (!config('log-shipper.status.enabled', false)) {
            return;
        }

        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $interval = (int) config('log-shipper.status.interval', 300);

        // Convert seconds to appropriate schedule method
        if ($interval <= 60) {
            // For intervals <= 1 minute, use everyMinute
            $schedule->command('log-shipper:status')->everyMinute();
        } elseif ($interval < 300) {
            // For 1-5 minutes, use everyFiveMinutes as a safe default
            $schedule->command('log-shipper:status')->everyFiveMinutes();
        } elseif ($interval < 600) {
            // For 5-10 minutes
            $schedule->command('log-shipper:status')->everyTenMinutes();
        } elseif ($interval < 1800) {
            // For 10-30 minutes
            $schedule->command('log-shipper:status')->everyThirtyMinutes();
        } else {
            // For 30+ minutes
            $schedule->command('log-shipper:status')->hourly();
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
