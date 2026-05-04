<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

use Pagekit\Database\Connection;

trait ModelTrait
{
    use PropertyTrait;

    /**
     * Gets the related EntityManager.
     */
    public static function getManager(): EntityManager
    {
        static $manager;

        if (!$manager) {
            $manager = EntityManager::getInstance();
            if (!$manager) {
                throw new \RuntimeException('EntityManager has not been initialized. Make sure the application is fully bootstrapped.');
            }
        }

        return $manager;
    }

    public static function getConnection(): Connection
    {
        return static::getManager()->getConnection();
    }

    /**
     * Gets the related Metadata object with mapping information of the class.
     */
    public static function getMetadata(): Metadata
    {
        return static::getManager()->getMetadata(get_called_class());
    }

    /**
     * Creates a new instance of this model.
     *
     * `EntityManager::load()` is typed `: object` because it works for any
     * mapped entity class; the runtime instance always matches the calling
     * `static::class` because `Metadata::newInstance()` reflects on it.
     * The defensive `instanceof static` check both enforces that contract at
     * runtime and gives PHPStan the narrowing it needs to honour `: static`.
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public static function create(array $data = []): static
    {
        $entity = static::getManager()->load(self::getMetadata(), $data);
        if (!$entity instanceof static) {
            throw new \LogicException(sprintf(
                'EntityManager::load() returned %s, expected %s',
                get_class($entity),
                static::class
            ));
        }

        return $entity;
    }

    /**
     * Creates a new QueryBuilder instance.
     */
    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::getManager(), static::getMetadata());
    }

    /**
     * Creates a new QueryBuilder instance and set the WHERE condition.
     *
     * @param array<int|string, mixed> $params
     */
    public static function where(mixed $condition, array $params = []): QueryBuilder
    {
        return static::query()->where($condition, $params);
    }

    /**
     * Retrieves an entity by its identifier.
     *
     * `QueryBuilder::first()` is typed `: ?object` because the ORM query
     * builder is shared across all mapped entities; the hydrated row is
     * always an instance of the calling `static::class` (see
     * {@see create()} for the same reasoning).
     *
     * @param  mixed $id
     * @return static|null
     */
    public static function find(mixed $id): ?static
    {
        $entity = static::where([static::getMetadata()->getIdentifier() => $id])->first();
        if ($entity === null) {
            return null;
        }
        if (!$entity instanceof static) {
            throw new \LogicException(sprintf(
                'QueryBuilder::first() returned %s, expected %s',
                get_class($entity),
                static::class
            ));
        }

        return $entity;
    }

    /**
     * Retrieves all entities.
     *
     * @return array<int|string, object>
     */
    public static function findAll(): array
    {
        return static::query()->get();
    }

    /**
     * Saves the entity.
     *
     * @param array<string, mixed> $data
     */
    public function save(array $data = []): void
    {
        static::getManager()->save($this, $data);
    }

    /**
     * Deletes the entity.
     */
    public function delete(): void
    {
        static::getManager()->delete($this);
    }

    /**
     * Gets model data as array.
     *
     * @param  array<string, mixed> $data
     * @param  array<int, string>   $ignore
     * @return array<string, mixed>
     */
    public function toArray(array $data = [], array $ignore = []): array
    {
        $metadata = static::getMetadata();
        $mappings = $metadata->getRelationMappings();

        foreach (static::getProperties($this) as $name => $value) {

            if (isset($data[$name]) || isset($mappings[$name])) {
                continue;
            }

            switch ($metadata->getField($name, 'type')) {
                case 'json_array':
                    $value = $value ?: new \stdClass();

                    break;
                case 'datetime':
                    $value = $value ? $value->format(\DateTime::ATOM) : null;

                    break;
            }

            $data[$name] = $value;
        }

        return array_diff_key($data, array_flip($ignore));
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
