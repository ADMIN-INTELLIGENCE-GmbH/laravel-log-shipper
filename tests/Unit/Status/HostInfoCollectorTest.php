<?php

namespace AdminIntelligence\LogShipper\Tests\Unit\Status;

use AdminIntelligence\LogShipper\Status\HostInfoCollector;
use AdminIntelligence\LogShipper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class HostInfoCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['SERVER_SOFTWARE']);

        parent::tearDown();
    }

    #[Test]
    public function it_reports_the_hostname(): void
    {
        $info = (new HostInfoCollector)->collect();

        $this->assertSame(gethostname(), $info['hostname']);
    }

    #[Test]
    public function it_reports_application_identity_from_config(): void
    {
        config([
            'app.url' => 'https://web-01.example.com',
            'app.timezone' => 'Europe/Berlin',
        ]);

        app()->setLocale('de');

        $info = (new HostInfoCollector)->collect();

        $this->assertSame('https://web-01.example.com', $info['app_url']);
        $this->assertSame('Europe/Berlin', $info['timezone']);
        $this->assertSame('de', $info['locale']);
    }

    #[Test]
    public function it_falls_back_to_the_runtime_timezone_when_config_is_empty(): void
    {
        config(['app.timezone' => null]);

        $info = (new HostInfoCollector)->collect();

        $this->assertSame(date_default_timezone_get(), $info['timezone']);
    }

    #[Test]
    public function it_reports_the_php_sapi(): void
    {
        $info = (new HostInfoCollector)->collect();

        $this->assertSame(PHP_SAPI, $info['php_sapi']);
    }

    #[Test]
    public function it_reports_loaded_php_extensions_sorted(): void
    {
        $info = (new HostInfoCollector)->collect();

        $this->assertContains('json', $info['php_extensions']);

        $sorted = $info['php_extensions'];
        sort($sorted);

        $this->assertSame($sorted, $info['php_extensions']);
        $this->assertSame(array_values($info['php_extensions']), $info['php_extensions']);
    }

    #[Test]
    public function it_reports_the_web_server_software_when_available(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';

        $info = (new HostInfoCollector)->collect();

        $this->assertSame('nginx/1.24.0', $info['server_software']);
    }

    #[Test]
    public function it_reports_null_server_software_on_the_cli(): void
    {
        unset($_SERVER['SERVER_SOFTWARE']);

        $info = (new HostInfoCollector)->collect();

        $this->assertNull($info['server_software']);
    }

    #[Test]
    public function it_returns_a_stable_key_set(): void
    {
        $info = (new HostInfoCollector)->collect();

        $this->assertSame(
            ['hostname', 'app_url', 'timezone', 'locale', 'server_software', 'php_sapi', 'php_extensions'],
            array_keys($info)
        );
    }
}
