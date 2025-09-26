<?php

namespace Pagekit\Debug\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Middleware for collecting SQL queries for the debug bar.
 * Replaces the old DebugStack implementation for DBAL 3.x compatibility.
 */
class DebugMiddleware implements Middleware
{
    protected DebugLogger $logger;

    public function __construct(DebugLogger $logger)
    {
        $this->logger = $logger;
    }

    public function wrap(Driver $driver): Driver
    {
        return new DebugDriver($driver, $this->logger);
    }

    public function getLogger(): DebugLogger
    {
        return $this->logger;
    }
}