<?php

namespace Pagekit\Debug\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

/**
 * Middleware for collecting SQL queries for the debug bar.
 * Replaces the old DebugStack implementation for DBAL 3.x compatibility.
 */
class DebugMiddleware implements MiddlewareInterface
{
    protected DebugLogger $logger;

    public function __construct(DebugLogger $logger)
    {
        $this->logger = $logger;
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new DebugDriver($driver, $this->logger);
    }

    public function getLogger(): DebugLogger
    {
        return $this->logger;
    }
}