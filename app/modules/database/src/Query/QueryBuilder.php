<?php

namespace Pagekit\Database\Query;

use Closure;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Types\Type;
use Pagekit\Database\Connection;
use Pagekit\Database\Query\QueryBuilder;
use PDO;

class QueryBuilder
{
    /**
     * The Connection.
     */
    protected \Pagekit\Database\Connection $connection;

    /**
     * The query parts.
     */
    protected array $parts = [
        'select' => [],
        'from' => null,
        'join' => [],
        'set' => [],
        'where' => null,
        'group' => [],
        'having' => null,
        'order' => [],
        'offset' => null,
        'limit' => null
    ];

    /**
     * The query parameters.
     */
    protected array $params = [];

    /**
     * Constructor.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Gets the connection for the query builder.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Creates and adds a "select" to the query.
     *
     * @param  mixed $columns
     */
    public function select($columns = ['*']): QueryBuilder
    {
        return $this->addPart('select', is_array($columns) ? $columns : func_get_args());
    }

    /**
     * Creates and sets a "from" to the query.
     *
     * @param  string $table
     */
    public function from($table): QueryBuilder
    {
        return $this->setPart('from', $table);
    }

    /**
     * Creates and adds a "join" to the query.
     *
     * @param  string $table
     * @param  string $condition
     * @param  string $type
     */
    public function join($table, $condition = null, $type = 'inner'): QueryBuilder
    {
        return $this->addPart('join', compact('type', 'table', 'condition'));
    }

    /**
     * Creates and adds an "inner join" to the query.
     *
     * @param  string $table
     * @param  string $condition
     */
    public function innerJoin($table, $condition = null): QueryBuilder
    {
        return $this->join($table, $condition);
    }

    /**
     * Creates and adds a "left join" to the query.
     *
     * @param  string $table
     * @param  string $condition
     */
    public function leftJoin($table, $condition = null): QueryBuilder
    {
        return $this->join($table, $condition, 'left');
    }

    /**
     * Creates and adds a "right join" to the query.
     *
     * @param  string $table
     * @param  string $condition
     */
    public function rightJoin($table, $condition = null): QueryBuilder
    {
        return $this->join($table, $condition, 'right');
    }

    /**
     * Creates and adds a "where" to the query.
     *
     * @param  mixed $condition
     * @param  array $params
     */
    public function where($condition, array $params = []): QueryBuilder
    {
        return $this->addWhere($condition, $params);
    }

    /**
     * Creates and adds a "or where" to the query.
     *
     * @param  mixed $condition
     * @param  array $params
     */
    public function orWhere($condition, array $params = []): QueryBuilder
    {
        return $this->addWhere($condition, $params, CompositeExpression::TYPE_OR);
    }

    /**
     * Creates and adds a "where in" to the query.
     *
     * @param  string $column
     * @param  mixed  $values
     * @param  bool   $not
     * @param  string $type
     */
    public function whereIn($column, $values, $not = false, $type = null): QueryBuilder
    {
        $params = [];

        if (is_array($values)) {

            $values = implode(', ', array_map([$this->connection, 'quote'], $values));

        } elseif ($values instanceof Closure) {

            $query = $this->newQuery();

            call_user_func($values, $query);

            $values = $query->getSQL();
            $params = $query->params();
        }

        $not = $not ? ' NOT' : '';

        return $this->addWhere("{$column}{$not} IN ({$values})", $params, $type);
    }

    /**
     * Creates and adds a "or where in" to the query.
     *
     * @param  string $column
     * @param  mixed  $values
     * @param  bool   $not
     */
    public function orWhereIn($column, $values, $not = false): QueryBuilder
    {
        return $this->whereIn($column, $values, $not, CompositeExpression::TYPE_OR);
    }

    /**
     * Creates and adds a "where exists" to the query.
     *
     * @param  Closure $callback
     * @param  bool    $not
     * @param  string  $type
     */
    public function whereExists(Closure $callback, $not = false, $type = null): QueryBuilder
    {
        $query = $this->newQuery();

        call_user_func($callback, $query);

        $exists = $query->getSQL();

        $not = $not ? 'NOT ' : '';

        return $this->addWhere("{$not}EXISTS ({$exists})", $query->params(), $type);
    }

    /**
     * Creates and adds a "or where exists" to the query.
     *
     * @param  Closure $callback
     * @param  bool    $not
     */
    public function orWhereExists(Closure $callback, $not = false): QueryBuilder
    {
        return $this->whereExists($callback, $not, CompositeExpression::TYPE_OR);
    }

    /**
     * Creates and adds a "where FIND_IN_SET" equivalent to the query.
     *
     * @param  string $column
     * @param  mixed  $values
     * @param  bool   $not
     * @param  string $type
     */
    public function whereInSet($column, $values, $not = false, $type = null): QueryBuilder
    {
        $not    = $not ? ' NOT' : '';
        $values = (array) $values;

        if (count($values) === 1 && $this->connection->getDatabasePlatform() instanceof MySqlPlatform) {
            $value = $this->connection->quote(current($values));
            return $this->addWhere("{$not} FIND_IN_SET({$value}, {$column})", [], $type);
        }

        $values = implode('|', (array) $values);
        return $this->addWhere("{$column}{$not} REGEXP ".$this->connection->quote("(^|,)({$values})($|,)"), [], $type);
    }

    /**
     * Creates and adds a "where" to the query.
     *
     * @param  mixed  $condition
     * @param  array  $params
     * @param  string $type
     */
    protected function addWhere($condition, array $params, $type = null): QueryBuilder
    {
        $args = [];

        if (null === $type) {
            $type = CompositeExpression::TYPE_AND;
        }

        if (is_string($condition)) {
            $condition = [$condition];
        }

        if (is_array($condition)) {

            foreach ($condition as $key => $value) {

                if (!is_numeric($key)) {
                    $name          = $this->parameter($key);
                    $params[$name] = $value;
                    $value         = "$key = :$name";
                }

                $args[] = $value;
            }

        } elseif ($condition instanceof Closure) {

            $query = $this->newQuery();
            $query->from($this->getPart('from'));

            call_user_func($condition, $query);

            $args[] = $query->getPart('where');
            $params = $query->params();
        }

        $this->params($params);

        $where = $this->getPart('where');

        if ($where instanceof CompositeExpression && $where->getType() === $type) {
            $where->addMultiple($args);
        } else {
            array_unshift($args, $where);
            $where = new CompositeExpression($type, $args);
        }

        return $this->addPart('where', $where);
    }

    /**
     * Creates and adds a "group by" to the query.
     *
     * @param  mixed $groupBy
     */
    public function groupBy($groupBy): QueryBuilder
    {
        return $this->addPart('group', is_array($groupBy) ? $groupBy : func_get_args());
    }

    /**
     * Creates and adds a "having" to the query.
     *
     * @param  mixed  $having
     * @param  string $type
     */
    public function having($having, $type = CompositeExpression::TYPE_AND): QueryBuilder
    {
        $args   = func_get_args();
        $having = $this->getPart('having');

        if ($having instanceof CompositeExpression && $having->getType() === $type) {
            $having->addMultiple($args);
        } else {
            array_unshift($args, $having);
            $having = new CompositeExpression($type, $args);
        }

        return $this->setPart('having', $having);
    }

    /**
     * Creates and adds a "or having" to the query.
     *
     * @param  mixed $having
     */
    public function orHaving($having): QueryBuilder
    {
        return $this->having($having, CompositeExpression::TYPE_OR);
    }

    /**
     * Creates and adds an "order by" to the query.
     *
     * @param  string $sort
     * @param  string $order
     */
    public function orderBy($sort, $order = null): QueryBuilder
    {
        return $this->addPart('order', "$sort ".($order ?: 'ASC'));
    }

    /**
     * Sets the offset of the query.
     *
     * @param  int $offset
     */
    public function offset($offset): self
    {
        $this->parts['offset'] = $offset;

        return $this;
    }

    /**
     * Sets the limit of the query.
     *
     * @param  int $limit
     */
    public function limit($limit): self
    {
        $this->parts['limit'] = $limit;

        return $this;
    }

    /**
     * Get or set multiple query parameters.
     *
     * @param  array $params
     * @return array|self
     */
    public function params(array $params = null)
    {
        if ($params === null) {
            return $this->params;
        }

        $this->params = array_merge($this->params, $params);

        return $this;
    }

    /**
     * Gets a query part by its name.
     *
     * @param  string $name
     * @return mixed
     */
    public function getPart($name)
    {
        return $this->parts[$name];
    }

    /**
     * Gets all query parts.
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    /**
     * Sets a query part and replaces all previous values.
     *
     * @param  string $name
     * @param  mixed  $parts
     */
    public function setPart($name, $parts): self
    {
        if (is_array($this->parts[$name]) && !is_array($parts)) {
            $parts = [$parts];
        }

        $this->parts[$name] = $parts;

        return $this;
    }

    /**
     * Adds a query part.
     *
     * @param  string $name
     * @param  mixed  $parts
     */
    public function addPart($name, $parts): self
    {
        if (is_array($this->parts[$name]) && !is_array($parts)) {
            $parts = [$parts];
        }

        if (in_array($name, ['select', 'set', 'order', 'group'])) {
            $this->parts[$name] = array_merge($this->parts[$name], $parts);
        } elseif (is_array($this->parts[$name])) {
            $this->parts[$name][] = $parts;
        } else {
            $this->parts[$name] = $parts;
        }

        return $this;
    }

    /**
     * Execute the query and get all results.
     *
     * @param  mixed $columns
     */
    public function get($columns = ['*']): array
    {
        return $this->execute($columns)->fetchAll();
    }

    /**
     * Execute the query and get the first result.
     *
     * @param  mixed $columns
     * @return mixed
     */
    public function first($columns = ['*'])
    {
        return $this->limit(1)->execute($columns)->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Execute the query and get the "count" result.
     *
     * @param  string $column
     */
    public function count($column = '*'): int
    {
        return (int) $this->aggregate('count', $column);
    }

    /**
     * Execute the query and get the "min" result.
     *
     * @param  string $column
     * @return mixed
     */
    public function min($column)
    {
        return $this->aggregate('min', $column);
    }

    /**
     * Execute the query and get the "max" result.
     *
     * @param  string $column
     * @return mixed
     */
    public function max($column)
    {
        return $this->aggregate('max', $column);
    }

    /**
     * Execute the query and get the "sum" result.
     *
     * @param  string $column
     * @return mixed
     */
    public function sum($column)
    {
        return $this->aggregate('sum', $column);
    }

    /**
     * Execute the query and get the "avg" result.
     *
     * @param  string $column
     * @return mixed
     */
    public function avg($column)
    {
        return $this->aggregate('avg', $column);
    }

    /**
     * Execute the query with an aggregate function.
     *
     * @param  string $function
     * @param  string $column
     * @return mixed
     */
    public function aggregate($function, $column)
    {
        $select  = $this->getPart('select');
        $results = $this->setPart('select', sprintf('%s(%s) aggregate', strtoupper($function), $column))->get();

        $this->setPart('select', $select);

        if ($results) {
            return $results[0]['aggregate'];
        }
    }

    /**
     * Execute the "select" query.
     *
     * @param  mixed $columns
     */
    public function execute($columns = ['*']): Statement
    {
        if (empty($this->parts['select'])) {
            $this->select($columns);
        }

        return $this->executeQuery();
    }

    /**
     * Execute the "update" query with the given values.
     *
     * @param  array $values
     */
    public function update(array $values): int
    {
        foreach ($values as $key => $value) {
            $name          = $this->parameter($key);
            $values[$name] = $value;
            $this->addPart('set', "$key = :$name");
        }

        return $this->params($values)->executeQuery('update');
    }

    /**
     * Execute the "delete" query.
     */
    public function delete(): int
    {
        return $this->executeQuery('delete');
    }

    /**
     * Gets the query SQL.
     */
    public function getSQL(): string
    {
        return $this->getSQLForSelect();
    }

    /**
     * {@see QueryBuilder::getSQL}
     */
    public function __toString()
    {
        return $this->getSQLForSelect();
    }

    /**
     * Creates a new query builder.
     *
     * @return QueryBuilder
     */
    protected function newQuery()
    {
        return new static($this->connection);
    }

    /**
     * Execute the query as select, update or delete.
     *
     * @param  string $type
     * @return mixed
     */
    protected function executeQuery($type = 'select')
    {
        switch ($type) {
            case 'update':
                $sql = $this->getSQLForUpdate();
                break;

            case 'delete':
                $sql = $this->getSQLForDelete();
                break;

            default:
                $sql = $this->getSQLForSelect();
        }

        if ($type == 'select') {
            return $this->connection->executeQuery($sql, $this->params, $this->guessParamTypes($this->params));
        } else {
            return $this->connection->executeUpdate($sql, $this->params, $this->guessParamTypes($this->params));
        }
    }

    /**
     * Creates the "select" SQL string from the query parts.
     */
    protected function getSQLForSelect(): string
    {
        extract($this->parts);

        $query = sprintf('SELECT %s FROM '.$from, $select ? implode(', ', $select) : '*');

        foreach ($join as $j) {
            $query .= sprintf(' %s JOIN %s ON %s', strtoupper($j['type']), $j['table'], (string) $j['condition']);
        }

        if ($where) {
            $query .= ' WHERE '.$where;
        }

        if ($group) {
            $query .= ' GROUP BY '.implode(', ', $group);
        }

        if ($having) {
            $query .= ' HAVING '.$having;
        }

        if ($order) {
            $query .= ' ORDER BY '.implode(', ', $order);
        }

        return ($limit === null && $offset === null) ? $query : $this->connection->getDatabasePlatform()->modifyLimitQuery($query, $limit, $offset);
    }

    /**
     * Creates the "update" SQL string from the query parts.
     */
    protected function getSQLForUpdate(): string
    {
        extract($this->parts);

        $query = "UPDATE $from";

        foreach ($join as $j) {
            $query .= sprintf(' %s JOIN %s ON %s', strtoupper($j['type']), $j['table'], (string) $j['condition']);
        }

        $query .= " SET ".implode(', ', $set);

        if ($where) {
            $query .= ' WHERE '.$where;
        }

        return $query;
    }

    /**
     * Creates the "delete" SQL string from the query parts.
     */
    protected function getSQLForDelete(): string
    {
        extract($this->parts);

        $query = 'DELETE FROM '.$from;

        foreach ($join as $j) {
            $query .= sprintf(' %s JOIN %s ON %s', strtoupper($j['type']), $j['table'], (string) $j['condition']);
        }

        if ($where) {
            $query .= ' WHERE '.$where;
        }

        return $query;
    }

    /**
     * Tries to guess param types
     *
     * @param  array $params
     */
    protected function guessParamTypes(array $params = []): array
    {
        $types = [];
        foreach ($params as $key => $param) {
            if ($param instanceof \DateTimeInterface) {
                // DBAL 2.10.2 - Make sure that the $types array has the same keys $params. https://github.com/doctrine/dbal/pull/3894
                $types[$key] = Type::DATETIME;
            }
        }
        return $types;
    }

    protected function parameter($name)
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }
}
