<?php

declare(strict_types=1);

namespace Pagekit\Config;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Pagekit\Database\Connection;

/**
 * @implements \IteratorAggregate<string, Config>
 */
class ConfigManager implements \IteratorAggregate
{
    protected Connection $connection;

    protected string $table;

    /** @var array<string, string>|null */
    protected ?array $cache = null;

    /** @var array<string, Config> */
    protected array $configs = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(Connection $connection, array $config)
    {
        $this->connection = $connection;
        $this->table = $config['table'];
    }

    /**
     * Get shortcut.
     *
     * @see get()
     */
    public function __invoke(string $name): ?Config
    {
        return $this->get($name);
    }

    public function has(string $name): bool
    {
        return isset($this->configs[$name]) || (bool) $this->fetch($name);
    }

    /**
     * Gets a config, creates a new config if none existent.
     */
    public function get(string $name): ?Config
    {
        if (!$this->has($name)) {
            $this->set($name, new Config());
        }

        return $this->configs[$name] ?? null;
    }

    /**
     * @param Config|array<int|string, mixed> $config
     */
    public function set(string $name, Config|array $config): void
    {
        if (is_array($config)) {
            $config = (new Config())->merge($config);
        }

        $this->configs[$name] = $config;

        if ($config->dirty()) {

            $data = ['name' => $name, 'value' => json_encode($config, JSON_UNESCAPED_UNICODE)];

            if ($this->connection->getDatabasePlatform() instanceof MySqlPlatform) {
                $this->connection->executeQuery("INSERT INTO {$this->table} (name, value) VALUES (:name, :value) ON DUPLICATE KEY UPDATE value = :value", $data);
            } elseif (!$this->connection->update($this->table, $data, compact('name'))) {
                $this->connection->insert($this->table, $data);
            }
        }
    }

    public function remove(string $name): void
    {
        $this->connection->delete($this->table, compact('name'));
    }

    /**
     * Returns an iterator.
     *
     * @return \ArrayIterator<string, Config>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->configs);
    }

    /**
     * Fetches config from database.
     */
    protected function fetch(string $name): ?Config
    {
        if ($this->cache === null) {
            $result = $this->connection->executeQuery("SELECT name, value FROM {$this->table}")->fetchAllNumeric();
            $this->cache = [];
            foreach ($result as $row) {
                $this->cache[$row[0]] = $row[1];
            }
        }

        if (isset($this->cache[$name]) && $values = @json_decode($this->cache[$name], true)) {
            return $this->configs[$name] = new Config($values);
        }

        return null;
    }
}
