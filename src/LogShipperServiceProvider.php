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

        $this->app->bind(\AdminIntelligence\LogShipper\Buffer\LogBufferInterface::class, function ($app) {
            $driver = config('log-shipper.batch.driver', 'redis');
            $connection = config('log-shipper.batch.connection', 'default');
            $key = config('log-shipper.batch.buffer_key', 'log_shipper_buffer');

            if ($driver === 'cache') {
                return new \AdminIntelligence\LogShipper\Buffer\CacheBuffer($connection, $key);
            }

            return new \AdminIntelligence\LogShipper\Buffer\RedisBuffer($connection, $key);
        });
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
                \AdminIntelligence\LogShipper\Console\Commands\ShipBatchLogsCommand::class,
            ]);

            $this->warnIfMisconfigured();
            $this->scheduleStatusPush();
            $this->scheduleBatchShipping();
        }
    }

    protected function scheduleBatchShipping(): void
    {
        if (!config('log-shipper.batch.enabled', false)) {
            return;
        }

        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $interval = (int) config('log-shipper.batch.interval', 1);

        // Ensure interval is at least 1 minute
        $interval = max(1, $interval);

        $schedule->command('log-shipper:ship-batch')->cron("*/{$interval} * * * *");
    }

    protected function scheduleStatusPush(): void
    {
        if (!config('log-shipper.status.enabled', false)) {
            return;
        }

        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $minutes = (int) config('log-shipper.status.interval', 5);

        // Ensure interval is at least 1 minute
        $minutes = max(1, $minutes);

        if ($minutes >= 1440) {
            // Daily
            $schedule->command('log-shipper:status')->daily();
        } elseif ($minutes >= 60) {
            // Hourly (or every N hours)
            $hours = intdiv($minutes, 60);
            $schedule->command('log-shipper:status')->cron("0 */{$hours} * * *");
        } else {
            // Every N minutes
            $schedule->command('log-shipper:status')->cron("*/{$minutes} * * * *");
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
