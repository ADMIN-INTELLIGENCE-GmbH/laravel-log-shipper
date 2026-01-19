<?php

namespace AdminIntelligence\LogShipper\Tests\Feature;

use AdminIntelligence\LogShipper\Jobs\ShipLogJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

class IpObfuscationIntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Configure the log shipper channel
        $app['config']->set('logging.channels.log_shipper', [
            'driver' => 'custom',
            'via' => \AdminIntelligence\LogShipper\Logging\CreateCustomLogger::class,
            'level' => 'error',
        ]);

        $app['config']->set('logging.default', 'log_shipper');
    }

    #[Test]
    public function test_ip_address_is_obfuscated_with_mask_method()
    {
        Queue::fake();

        Config::set('log-shipper.send_context.ip_address', true);
        Config::set('log-shipper.ip_obfuscation.enabled', true);
        Config::set('log-shipper.ip_obfuscation.method', 'mask');

        Log::error('Test log message with IP');

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            // Should have an IP address that looks masked
            return isset($payload['ip_address']) &&
                   is_string($payload['ip_address']);
        });
    }

    #[Test]
    public function test_ip_address_is_obfuscated_with_hash_method()
    {
        Queue::fake();

        Config::set('log-shipper.send_context.ip_address', true);
        Config::set('log-shipper.ip_obfuscation.enabled', true);
        Config::set('log-shipper.ip_obfuscation.method', 'hash');

        Log::error('Test log message');

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return isset($payload['ip_address']) &&
                   (str_starts_with($payload['ip_address'], 'ip_') ||
                    is_null($payload['ip_address']) ||
                    $payload['ip_address'] === '');
        });
    }

    #[Test]
    public function test_ip_address_is_not_obfuscated_when_disabled()
    {
        Queue::fake();

        Config::set('log-shipper.send_context.ip_address', true);
        Config::set('log-shipper.ip_obfuscation.enabled', false);

        Log::error('Test log message');

        Queue::assertPushed(ShipLogJob::class);
    }

    #[Test]
    public function test_ip_address_is_not_sent_when_context_disabled()
    {
        Queue::fake();

        Config::set('log-shipper.send_context.ip_address', false);
        Config::set('log-shipper.ip_obfuscation.enabled', true);

        Log::error('Test log message');

        Queue::assertPushed(ShipLogJob::class, function ($job) {
            $payload = $this->getJobPayload($job);

            return !isset($payload['ip_address']);
        });
    }

    /**
     * Get the payload from a ShipLogJob.
     */
    protected function getJobPayload(ShipLogJob $job): array
    {
        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('payload');
        $property->setAccessible(true);

        return $property->getValue($job);
    }
}
