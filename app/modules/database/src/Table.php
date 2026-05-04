<?php

declare(strict_types=1);

namespace Pagekit\Database;

use Doctrine\DBAL\Schema\Table as BaseTable;

class Table
{
    protected BaseTable $table;

    protected Connection $connection;

    public function __construct(BaseTable $table, Connection $connection)
    {
        $this->table = $table;
        $this->connection = $connection;
    }

    /**
     * @param array<int, string>   $columnNames
     * @param array<int, string>   $flags
     * @param array<string, mixed> $options
     */
    public function addIndex(array $columnNames, ?string $indexName = null, array $flags = [], array $options = []): self
    {
        if ($indexName) {
            $indexName = $this->connection->replacePrefix($indexName);
        }

        $this->table->addIndex($columnNames, $indexName, $flags, $options);

        return $this;
    }

    /**
     * @param array<int, string>   $columnNames
     * @param array<string, mixed> $options
     */
    public function addUniqueIndex(array $columnNames, ?string $indexName = null, array $options = []): self
    {
        if ($indexName) {
            $indexName = $this->connection->replacePrefix($indexName);
        }

        $this->table->addUniqueIndex($columnNames, $indexName, $options);

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function addColumn(string $columnName, string $typeName, array $options = []): \Doctrine\DBAL\Schema\Column
    {
        if ($this->connection->getDatabasePlatform()->getName() === 'sqlite' && in_array($typeName, ['string', 'text'])) {
            $options['customSchemaOptions']['collation'] = 'NOCASE';
        }

        return $this->table->addColumn($columnName, $typeName, $options);
    }

    /**
     * Proxy method call to table.
     *
     * @param array<int, mixed> $args
     * @throws \BadMethodCallException
     */
    public function __call(string $method, array $args): mixed
    {
        if (!method_exists($this->table, $method)) {
            throw new \BadMethodCallException(sprintf('Undefined method call "%s::%s"', get_class($this->table), $method));
        }

        return call_user_func_array([$this->table, $method], $args);
    }
}
