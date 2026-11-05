<?php

declare(strict_types=1);

namespace AdminIntelligence\LogShipper;

use AdminIntelligence\LogShipper\Buffer\CacheBuffer;
use AdminIntelligence\LogShipper\Buffer\LogBufferInterface;
use AdminIntelligence\LogShipper\Buffer\RedisBuffer;
use AdminIntelligence\LogShipper\Console\Commands\ShipBatchLogsCommand;
use AdminIntelligence\LogShipper\Console\Commands\ShipStatusCommand;
use AdminIntelligence\LogShipper\Console\Commands\TestStatusCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class LogShipperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/log-shipper.php',
            'log-shipper'
        );

        $this->app->bind(LogBufferInterface::class, function ($app) {
            $driver = config('log-shipper.batch.driver', 'redis');
            $connection = config('log-shipper.batch.connection', 'default');
            $key = config('log-shipper.batch.buffer_key', 'log_shipper_buffer');

            if ($driver === 'cache') {
                return new CacheBuffer($connection, $key);
            }

            return new RedisBuffer($connection, $key);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/log-shipper.php' => config_path('log-shipper.php'),
            ], 'log-shipper-config');

            $this->commands([
                ShipBatchLogsCommand::class,
                ShipStatusCommand::class,
                TestStatusCommand::class,
            ]);

            $this->warnIfMisconfigured();

            // Laravel 10 binds Schedule in the console kernel, after providers boot.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $this->scheduleStatusPush($schedule);
                $this->scheduleBatchShipping($schedule);
            });
        }
    }

    protected function scheduleBatchShipping(Schedule $schedule): void
    {
        if (!config('log-shipper.batch.enabled', false)) {
            return;
        }

        $interval = (int) config('log-shipper.batch.interval', 1);

        // Ensure interval is at least 1 minute and max 59 (cron limitation)
        $interval = max(1, min(59, $interval));

        // Use everyMinute() for interval of 1 to avoid cron syntax issues
        if ($interval === 1) {
            $schedule->command('log-shipper:ship-batch')->everyMinute();
        } else {
            $schedule->command('log-shipper:ship-batch')->cron("*/{$interval} * * * *");
        }
    }

    protected function scheduleStatusPush(Schedule $schedule): void
    {
        if (!config('log-shipper.status.enabled', false)) {
            return;
        }

        $minutes = (int) config('log-shipper.status.interval', 5);

        // Ensure interval is at least 1 minute
        $minutes = max(1, $minutes);

        if ($minutes >= 1440) {
            // Daily
            $schedule->command('log-shipper:status')->daily();
        } elseif ($minutes >= 60) {
            // Hourly (or every N hours)
            $hours = max(1, min(23, intdiv($minutes, 60)));
            $schedule->command('log-shipper:status')->cron("0 */{$hours} * * *");
        } else {
            // Every N minutes (cap at 59 for valid cron)
            $minutes = min(59, $minutes);
            if ($minutes === 1) {
                $schedule->command('log-shipper:status')->everyMinute();
            } else {
                $schedule->command('log-shipper:status')->cron("*/{$minutes} * * * *");
            }
        }
    }

    protected function warnIfMisconfigured(): void
    {
        $endpoint = config('log-shipper.api_endpoint');
        $apiKey = config('log-shipper.api_key');
        $enabled = config('log-shipper.enabled', true);

        // These run every minute or for days; repeating the warning there only floods cron mail and worker logs.
        // The command is the first argument that is not an option (artisan --env=x schedule:run)
        $command = '';
        foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
            if (!str_starts_with((string) $arg, '-')) {
                $command = (string) $arg;

                break;
            }
        }
        $quiet = ['schedule:run', 'schedule:work', 'queue:work', 'queue:listen', 'horizon', 'log-shipper:status', 'log-shipper:ship-batch'];
        if (in_array($command, $quiet, true) || str_starts_with($command, 'horizon:')) {
            return;
        }

        if ($enabled && (empty($endpoint) || empty($apiKey))) {
            $missing = [];
            if (empty($endpoint)) {
                $missing[] = 'LOG_SHIPPER_ENDPOINT';
            }
            if (empty($apiKey)) {
                $missing[] = 'LOG_SHIPPER_KEY';
            }

            $this->app->booted(function () use ($missing) {
                Log::channel('stderr')->warning(
                    '[Log Shipper] Missing configuration: ' . implode(', ', $missing) . '. Log shipping is disabled until configured.'
                );
            });
        }
    }
}
