<?php

namespace Pagekit\Debug\Middleware;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * Statement middleware that logs SQL queries with parameters.
 */
class DebugStatement extends AbstractStatementMiddleware
{
    protected DebugLogger $logger;
    protected string $sql;
    protected array $params = [];
    protected array $types = [];

    public function __construct(Statement $statement, DebugLogger $logger, string $sql)
    {
        parent::__construct($statement);
        $this->logger = $logger;
        $this->sql = $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, $type = ParameterType::STRING): void
    {
        $this->params[$param] = $value;
        $this->types[$param] = $type;
        parent::bindValue($param, $value, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): void
    {
        $this->params[$param] = &$variable;
        $this->types[$param] = $type;
        parent::bindParam($param, $variable, $type, $length);
    }

    /**
     * {@inheritdoc}
     */
    public function execute($params = null): Result
    {
        if ($params !== null) {
            $this->params = $params;
        }

        $this->logger->startQuery($this->sql, $this->params, $this->types);
        
        try {
            $result = parent::execute($params);
            $this->logger->stopQuery();
            return $result;
        } catch (\Throwable $e) {
            $this->logger->stopQuery();
            throw $e;
        }
    }
}