<?php

namespace Pagekit\Debug\Middleware;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * Connection middleware that logs SQL queries.
 */
class DebugConnection extends AbstractConnectionMiddleware
{
    protected DebugLogger $logger;

    public function __construct(ConnectionInterface $connection, DebugLogger $logger)
    {
        parent::__construct($connection);
        $this->logger = $logger;
        error_log("DebugConnection created - logger enabled: " . ($logger->enabled ? 'yes' : 'no'));
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(string $sql): Statement
    {
        error_log("DebugConnection::prepare called - SQL: " . substr($sql, 0, 50));
        return new DebugStatement(
            parent::prepare($sql),
            $this->logger,
            $sql
        );
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $sql): Result
    {
        error_log("DebugConnection::query called - SQL: " . substr($sql, 0, 50));
        $this->logger->startQuery($sql);
        
        try {
            $result = parent::query($sql);
            $this->logger->stopQuery();
            return $result;
        } catch (\Throwable $e) {
            $this->logger->stopQuery();
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $sql): int|string
    {
        $this->logger->startQuery($sql);
        
        try {
            $result = parent::exec($sql);
            $this->logger->stopQuery();
            return $result;
        } catch (\Throwable $e) {
            $this->logger->stopQuery();
            throw $e;
        }
    }
}