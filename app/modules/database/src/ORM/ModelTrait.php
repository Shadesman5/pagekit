<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

use Pagekit\Database\Connection;

trait ModelTrait
{
    use PropertyTrait;

    /**
     * Pre-computed, serialization-safe mapping data injected by
     * {@see EntityManager::load()} for {@see toArray()}. Null on instances that
     * were not hydrated through the EntityManager (e.g. a raw `new`), in which
     * case {@see toArray()} falls back to the metadata lookup.
     *
     * @var array{relations: list<string>, fieldTypes: array<string, string>}|null
     */
    private ?array $_serializationMap = null;

    /**
     * Injects the pre-computed serialization map (see
     * {@see SerializableModelInterface}).
     *
     * @param array{relations: list<string>, fieldTypes: array<string, string>} $map
     */
    public function setSerializationMap(array $map): void
    {
        $this->_serializationMap = $map;
    }

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
     *
     * The builder is shared across all mapped entities, so its element type
     * resolves to the {@see \Pagekit\Database\ORM\QueryBuilder} `object` bound.
     *
     * @return QueryBuilder<object>
     */
    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::getManager(), static::getMetadata());
    }

    /**
     * Creates a new QueryBuilder instance and set the WHERE condition.
     *
     * @param array<int|string, mixed> $params
     * @return QueryBuilder<object>
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
     * Reads the serialization map injected by {@see EntityManager::load()}.
     * Instances created without going through the EntityManager fall back to a
     * live metadata lookup.
     *
     * @param  array<string, mixed> $data
     * @param  array<int, string>   $ignore
     * @return array<string, mixed>
     */
    public function toArray(array $data = [], array $ignore = []): array
    {
        if ($this->_serializationMap !== null) {
            $relations = $this->_serializationMap['relations'];
            $fieldTypes = $this->_serializationMap['fieldTypes'];
        } else {
            // TODO: TEMPORARY BRIDGE - To be removed in Step 2.1.11 (EntityManager DI)
            // checklist Step 8, when the static model API is gone and every entity is
            // guaranteed to be hydrated through EntityManager::load() (map always present).
            $metadata = static::getMetadata();
            $relations = array_keys($metadata->getRelationMappings());
            $fieldTypes = [];
            foreach ($metadata->getFields() as $name => $field) {
                $fieldTypes[$name] = (string) $field['type'];
            }
        }

        $relationKeys = array_flip($relations);

        foreach (static::getProperties($this) as $name => $value) {

            if (isset($data[$name]) || isset($relationKeys[$name])) {
                continue;
            }

            // Never leak internal scaffolding: `_`-prefixed properties (e.g. the
            // injected serialization map) and Closure values (e.g. the role loader).
            if (str_starts_with($name, '_') || $value instanceof \Closure) {
                continue;
            }

            switch ($fieldTypes[$name] ?? null) {
                case 'json':
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
