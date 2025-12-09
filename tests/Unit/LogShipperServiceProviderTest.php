<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LogShipperServiceProviderTest extends TestCase
{
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
