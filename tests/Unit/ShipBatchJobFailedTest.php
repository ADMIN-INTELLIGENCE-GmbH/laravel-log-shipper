<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipBatchJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

class ShipBatchJobFailedTest extends TestCase
{
    protected function batch(): array
    {
        return [
            ['level' => 'error', 'message' => 'first', 'context' => ['password' => 'secret1']],
            ['level' => 'warning', 'message' => 'second', 'context' => []],
        ];
    }

    #[Test]
    public function failed_writes_every_log_to_fallback_channel()
    {
        Config::set('log-shipper.fallback_channel', 'single');
        Config::set('log-shipper.sanitize_fields', ['password']);

        $enabledDuringWrite = [];
        Log::shouldReceive('channel')->with('single')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->with('error', 'first', \Mockery::on(function ($context) use (&$enabledDuringWrite) {
                $enabledDuringWrite[] = config('log-shipper.enabled');

                return $context['log_shipper_failure'] === 'Network error'
                    && $context['original_payload']['context']['password'] === '[REDACTED]';
            }));
        Log::shouldReceive('log')
            ->once()
            ->with('warning', 'second', \Mockery::on(fn ($context) => isset($context['log_shipper_failure'])));

        (new ShipBatchJob($this->batch()))->failed(new \Exception('Network error'));

        $this->assertSame([false], $enabledDuringWrite);
        $this->assertTrue(config('log-shipper.enabled'));
    }

    #[Test]
    public function failed_restores_previous_enabled_value()
    {
        Config::set('log-shipper.enabled', false);
        Config::set('log-shipper.fallback_channel', 'single');

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->twice();

        (new ShipBatchJob($this->batch()))->failed(new \Exception('x'));

        $this->assertFalse(config('log-shipper.enabled'));
    }

    #[Test]
    public function failed_skips_without_fallback_or_with_log_shipper_fallback()
    {
        Log::shouldReceive('channel')->never();

        Config::set('log-shipper.fallback_channel', null);
        (new ShipBatchJob($this->batch()))->failed(new \Exception('x'));

        Config::set('log-shipper.fallback_channel', 'log_shipper');
        (new ShipBatchJob($this->batch()))->failed(new \Exception('x'));

        $this->assertTrue(config('log-shipper.enabled'));
    }
}
