<?php

declare(strict_types=1);

namespace Pagekit\Database;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as BaseConnection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Pagekit\Database\Query\QueryBuilder;

class Connection extends BaseConnection
{
    public const SINGLE_QUOTED_TEXT = '\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'';
    public const DOUBLE_QUOTED_TEXT = '"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"';

    /**
     * The database utility.
     */
    protected ?Utility $utility = null;

    /**
     * The table prefix.
     */
    protected ?string $prefix = null;

    /**
     * The table prefix placeholder.
     */
    protected string $placeholder = '@';

    /**
     * The regex for parsing SQL query parts.
     *
     * @var array<string, string>
     */
    protected array $regex;

    /**
     * Flag to track if type mappings have been registered.
     */
    protected bool $_typeMappingsRegistered = false;

    /**
     * Initializes a new instance of the Connection class.
     *
     * @param array<string, mixed> $params
     */
    public function __construct(array $params, Driver $driver, ?Configuration $config = null, ?EventManager $eventManager = null)
    {
        if (!isset($params['defaultTableOptions'])) {
            $params['defaultTableOptions'] = [];
        }

        foreach (['engine', 'charset', 'collate'] as $name) {
            if (isset($params[$name])) {
                $params['defaultTableOptions'][$name] = $params[$name];
                unset($params[$name]);
            }
        }

        if (isset($params['prefix'])) {
            $this->prefix = $params['prefix'];
        }

        $this->regex = [
            'quotes' => "/([^'\"]+)(?:".self::DOUBLE_QUOTED_TEXT."|".self::SINGLE_QUOTED_TEXT.")?/As",
            'placeholder' => "/".preg_quote($this->placeholder, '/')."([a-zA-Z_][a-zA-Z0-9_]*)/",
        ];

        parent::__construct($params, $driver, $config, $eventManager);
    }

    /**
     * {@inheritdoc}
     */
    public function connect(): bool
    {
        $result = parent::connect();

        // Register custom type mappings after connection is established
        if ($result && !$this->_typeMappingsRegistered) {
            try {
                $platform = parent::getDatabasePlatform();
                $this->registerCustomTypeMappings($platform);
                $this->_typeMappingsRegistered = true;
            } catch (\Exception $e) {
                // Connection might not be ready yet
            }
        }

        return $result;
    }

    /**
     * Ensure type mappings are registered when getting the platform.
     * {@inheritdoc}
     */
    public function getDatabasePlatform(): \Doctrine\DBAL\Platforms\AbstractPlatform
    {
        $platform = parent::getDatabasePlatform();

        // Register type mappings if not already done
        if (!$this->_typeMappingsRegistered && $this->isConnected()) {
            $this->registerCustomTypeMappings($platform);
            $this->_typeMappingsRegistered = true;
        }

        return $platform;
    }

    /**
     * Registers custom Doctrine type mappings so introspected DB column types resolve
     * to Pagekit's array-safe types. The JSON type is registered globally as the 'json'
     * type via Type::overrideType() in the database module bootstrap, so only the
     * simple_array platform mapping needs to be established here.
     *
     * @param \Doctrine\DBAL\Platforms\AbstractPlatform|null $platform
     */
    protected function registerCustomTypeMappings($platform = null): void
    {
        try {
            if ($platform === null) {
                $platform = parent::getDatabasePlatform();
            }

            $platform->registerDoctrineTypeMapping('simple_array', 'simple_array');
        } catch (\Exception $e) {
            // Silently fail if platform is not available yet
        }
    }

    /**
     * Gets the database utility.
     */
    public function getUtility(): Utility
    {
        if (!$this->utility) {
            $this->utility = new Utility($this);
        }

        return $this->utility;
    }

    /**
     * Gets the table prefix.
     */
    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    /**
     * Replaces the table prefix placeholder with actual one.
     */
    public function replacePrefix(string $query): string
    {
        $offset = 0;
        $length = strlen($this->prefix ?? '') - strlen($this->placeholder);

        foreach ($this->getUnquotedQueryParts($query) as $part) {

            if (strpos($part[0], $this->placeholder) === false) {
                continue;
            }

            $replace = preg_replace($this->regex['placeholder'], $this->prefix.'$1', $part[0], -1, $count);

            if ($count && $replace !== null) {
                $query = substr_replace($query, $replace, $part[1] + $offset, strlen($part[0]));
                $offset += $length;
            }
        }

        return $query;
    }

    /**
     * Prepares and executes an SQL query and returns the first row of the result as an object.
     *
     * @template T of object
     * @param  array<string, mixed> $params
     * @param  class-string<T>      $class
     * @param  array<int, mixed>    $args
     * @return T|false
     */
    public function fetchObject(string $statement, array $params = [], string $class = 'stdClass', array $args = []): object|false
    {
        $result = $this->executeQuery($statement, $params);
        $row = $result->fetchAssociative();
        if ($row === false) {
            return false;
        }
        $object = new $class(...$args);
        foreach ($row as $key => $value) {
            $object->$key = $value;
        }

        return $object;
    }

    /**
     * Prepares and executes an SQL query and returns the result as an array of objects.
     *
     * @template T of object
     * @param  array<string, mixed> $params
     * @param  class-string<T>      $class
     * @param  array<int, mixed>    $args
     * @return array<int, T>
     */
    public function fetchAllObjects(string $statement, array $params = [], string $class = 'stdClass', array $args = []): array
    {
        $result = $this->executeQuery($statement, $params);
        $rows = $result->fetchAllAssociative();
        $objects = [];
        foreach ($rows as $row) {
            $object = new $class(...$args);
            foreach ($row as $key => $value) {
                $object->$key = $value;
            }
            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * @{inheritdoc}
     */
    public function prepare($sql): Statement
    {
        return parent::prepare($this->replacePrefix($sql));
    }

    /**
     * @{inheritdoc}
     */
    public function exec(string $sql): int
    {
        // DBAL declares executeStatement() as int|string for driver compatibility, but the
        // value is the affected-rows count returned by Statement::rowCount(), which is int.
        return (int) parent::executeStatement($this->replacePrefix($sql));
    }

    /**
     * @{inheritdoc}
     */
    public function executeQuery($sql, array $params = [], $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        return parent::executeQuery($this->replacePrefix($sql), $params, $types, $qcp);
    }

    /**
     * @{inheritdoc}
     */
    public function executeStatement($sql, array $params = [], array $types = []): int
    {
        // DBAL declares executeStatement() as int|string for driver compatibility, but the
        // value is the affected-rows count returned by Statement::rowCount(), which is int.
        return (int) parent::executeStatement($this->replacePrefix($sql), $params, $types);
    }

    /**
     * @{inheritdoc}
     */
    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /**
     * Parses the unquoted SQL query parts.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    protected function getUnquotedQueryParts(string $query): array
    {
        preg_match_all($this->regex['quotes'], $query, $parts, PREG_OFFSET_CAPTURE);

        return $parts[1];
    }
}
