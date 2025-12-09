<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Logging\CreateCustomLogger;
use AdminIntelligence\LogShipper\Logging\LogShipperHandler;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;

class CreateCustomLoggerTest extends TestCase
{
    #[Test]
    public function it_creates_a_monolog_logger_instance(): void
    {
        $factory = new CreateCustomLogger;
        $logger = $factory(['level' => 'error']);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertEquals('log-shipper', $logger->getName());
    }

    #[Test]
    public function it_adds_log_shipper_handler(): void
    {
        $factory = new CreateCustomLogger;
        $logger = $factory(['level' => 'error']);

        $handlers = $logger->getHandlers();
        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(LogShipperHandler::class, $handlers[0]);
    }

    #[Test]
    public function it_parses_error_level_correctly(): void
    {
        $factory = new CreateCustomLogger;
        $logger = $factory(['level' => 'error']);

        $handler = $logger->getHandlers()[0];
        $this->assertEquals(Level::Error, $handler->getLevel());
    }

    #[Test]
    public function it_parses_debug_level_correctly(): void
    {
        $factory = new CreateCustomLogger;
        $logger = $factory(['level' => 'debug']);

        $handler = $logger->getHandlers()[0];
        $this->assertEquals(Level::Debug, $handler->getLevel());
    }

    #[Test]
    public function it_parses_warning_level_correctly(): void
    {
        $factory = new CreateCustomLogger;
        $logger = $factory(['level' => 'warning']);

        $handler = $logger->getHandlers()[0];
        $this->assertEquals(Level::Warning, $handler->getLevel());
    }

    #[Test]
    public function it_parses_critical_level_correctly(): void
    {
        $factory = new CreateCustomLogger;
        $logger = $factory(['level' => 'critical']);

        $handler = $logger->getHandlers()[0];
        $this->assertEquals(Level::Critical, $handler->getLevel());
    }

    #[Test]
    public function it_defaults_to_error_level_for_invalid_level(): void
    {
        $factory = new CreateCustomLogger;
        $logger = $factory(['level' => 'invalid-level']);

        $handler = $logger->getHandlers()[0];
        $this->assertEquals(Level::Error, $handler->getLevel());
    }

    #[Test]
    public function it_defaults_to_error_level_when_no_level_provided(): void
    {
        $factory = new CreateCustomLogger;
        $logger = $factory([]);

        $handler = $logger->getHandlers()[0];
        $this->assertEquals(Level::Error, $handler->getLevel());
    }

    #[Test]
    public function it_handles_uppercase_level_names(): void
    {
        $factory = new CreateCustomLogger;
        $logger = $factory(['level' => 'WARNING']);

        $handler = $logger->getHandlers()[0];
        $this->assertEquals(Level::Warning, $handler->getLevel());
    }
}
