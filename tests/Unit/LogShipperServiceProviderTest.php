<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\LogShipperServiceProvider;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

class LogShipperServiceProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('log-shipper.status.enabled', true);
        $app['config']->set('log-shipper.batch.enabled', true);
    }

    #[Test]
    public function it_registers_commands_on_a_schedule_resolved_after_boot(): void
    {
        // Laravel 10 binds Schedule late (console kernel), after providers booted.
        $this->app->singleton(Schedule::class, fn () => new Schedule);

        $commands = array_map(
            fn (Event $event) => $event->command,
            $this->app->make(Schedule::class)->events()
        );

        $this->assertNotEmpty(array_filter($commands, fn ($c) => str_contains((string) $c, 'log-shipper:status')));
        $this->assertNotEmpty(array_filter($commands, fn ($c) => str_contains((string) $c, 'log-shipper:ship-batch')));
    }

    #[Test]
    public function it_skips_the_misconfiguration_warning_for_scheduler_and_queue_workers(): void
    {
        $this->assertSame([], $this->commandsThatWarn(
            'schedule:run',
            'schedule:work',
            'queue:work',
            'queue:listen',
            'horizon',
            'horizon:work',
            'horizon:supervisor',
            'log-shipper:status',
            'log-shipper:ship-batch',
            '--env=staging schedule:run',
            '-q queue:work',
        ));
    }

    #[Test]
    public function it_warns_about_misconfiguration_for_other_commands(): void
    {
        $this->assertSame(['migrate', '--env=staging migrate'], $this->commandsThatWarn('migrate', '--env=staging migrate'));
    }

    private function commandsThatWarn(string ...$commands): array
    {
        config(['log-shipper.api_key' => null]);
        $warned = [];
        $current = null;
        $stderr = new class
        {
            public $onWarning;

            public function warning(): void
            {
                ($this->onWarning)();
            }
        };
        $stderr->onWarning = function () use (&$warned, &$current) {
            $warned[] = $current;
        };
        Log::shouldReceive('channel')->with('stderr')->andReturn($stderr);

        $argv = $_SERVER['argv'] ?? null;
        $method = new \ReflectionMethod(LogShipperServiceProvider::class, 'warnIfMisconfigured');

        try {
            foreach ($commands as $current) {
                $_SERVER['argv'] = ['artisan', ...explode(' ', $current)];
                $method->invoke($this->app->getProvider(LogShipperServiceProvider::class));
            }
        } finally {
            $_SERVER['argv'] = $argv;
        }

        return $warned;
    }

    #[Test]
    public function it_merges_config_correctly(): void
    {
        $config = config('log-shipper');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('api_endpoint', $config);
        $this->assertArrayHasKey('api_key', $config);
        $this->assertArrayHasKey('queue_connection', $config);
        $this->assertArrayHasKey('queue_name', $config);
        $this->assertArrayHasKey('sanitize_fields', $config);
        $this->assertArrayHasKey('send_context', $config);
    }

    #[Test]
    public function it_has_default_sanitize_fields(): void
    {
        $sanitizeFields = config('log-shipper.sanitize_fields');

        $this->assertContains('password', $sanitizeFields);
        $this->assertContains('password_confirmation', $sanitizeFields);
        $this->assertContains('credit_card', $sanitizeFields);
        $this->assertContains('api_key', $sanitizeFields);
        $this->assertContains('secret', $sanitizeFields);
        $this->assertContains('token', $sanitizeFields);
    }

    #[Test]
    public function it_has_default_context_settings(): void
    {
        $contextConfig = config('log-shipper.send_context');

        $this->assertArrayHasKey('user_id', $contextConfig);
        $this->assertArrayHasKey('ip_address', $contextConfig);
        $this->assertArrayHasKey('user_agent', $contextConfig);
        $this->assertArrayHasKey('route_name', $contextConfig);
        $this->assertArrayHasKey('controller_action', $contextConfig);
        $this->assertArrayHasKey('request_method', $contextConfig);
        $this->assertArrayHasKey('request_url', $contextConfig);
    }

    #[Test]
    public function config_values_can_be_overridden(): void
    {
        config(['log-shipper.enabled' => false]);
        config(['log-shipper.api_endpoint' => 'https://custom.example.com/logs']);

        $this->assertFalse(config('log-shipper.enabled'));
        $this->assertEquals('https://custom.example.com/logs', config('log-shipper.api_endpoint'));
    }
}
