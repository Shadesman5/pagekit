<?php

namespace Pagekit\Debug\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Driver middleware that wraps connections with debug logging.
 */
class DebugDriver extends AbstractDriverMiddleware
{
    protected DebugLogger $logger;

    public function __construct(Driver $driver, DebugLogger $logger)
    {
        parent::__construct($driver);
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(array $params): ConnectionInterface
    {
        $connection = parent::connect($params);

        return new DebugConnection($connection, $this->logger);
    }
}
