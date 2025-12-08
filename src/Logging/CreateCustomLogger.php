<?php

namespace AdminIntelligence\LogShipper\Logging;

use Monolog\Level;
use Monolog\Logger;

class CreateCustomLogger
{
    /**
     * Create a custom Monolog instance.
     *
     * @param array $config
     * @return Logger
     */
    public function __invoke(array $config): Logger
    {
        $level = $this->parseLevel($config['level'] ?? 'error');

        $logger = new Logger('log-shipper');
        $logger->pushHandler(new LogShipperHandler($level));

        return $logger;
    }

    protected function parseLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Error,
        };
    }
}
