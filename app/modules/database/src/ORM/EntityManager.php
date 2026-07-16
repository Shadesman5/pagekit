<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

use Pagekit\Database\Connection;
use Pagekit\Database\Events;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Event\PrefixEventDispatcher;

class EntityManager
{
    protected Connection $connection;

    protected MetadataManager $metadata;

    protected EventDispatcherInterface $events;

    /**
     * Per-EntityManager cache of generic repositories, keyed by entity class.
     *
     * @var array<class-string, Repository<object>>
     */
    private array $repositories = [];

    /**
     * Creates a new Manager instance
     *
     * @param  Connection               $connection
     * @param  MetadataManager          $metadata
     * @param  EventDispatcherInterface $events
     */
    public function __construct(Connection $connection, MetadataManager $metadata, ?EventDispatcherInterface $events = null)
    {
        $this->connection = $connection;
        $this->metadata = $metadata;
        $this->events = $events ?: new PrefixEventDispatcher('model.');
    }

    /**
     * Gets the database connection.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Gets the metadata object of an entity class.
     *
     * @param object|class-string $class
     */
    public function getMetadata(object|string $class): Metadata
    {
        return $this->metadata->get($class);
    }

    /**
     * Gets the metadata manager.
     */
    public function getMetadataManager(): MetadataManager
    {
        return $this->metadata;
    }

    /**
     * Gets the generic repository for an entity class.
     *
     * One instance is created and cached per class on this EntityManager (no
     * static state). Custom repositories with extra finders are registered as
     * container services, not built here.
     *
     * @template T of object
     * @param  class-string<T> $entity
     * @return Repository<T>
     */
    public function getRepository(string $entity): Repository
    {
        if (!isset($this->repositories[$entity])) {
            $this->repositories[$entity] = new Repository($this, $this->getMetadata($entity));
        }

        /** @var Repository<T> $repository */
        $repository = $this->repositories[$entity];

        return $repository;
    }

    /**
     * Retrieve an entity by its identifier.
     *
     * @template T of object
     * @param  class-string<T> $entity
     * @param  int|string      $identifier
     * @return T|null
     */
    public function find(string $entity, int|string $identifier): ?object
    {
        return $this->getRepository($entity)->find($identifier);
    }

    /**
     * Checks whether the given managed entity exists in the database.
     *
     * @param  object $entity
     */
    public function exists(object $entity): bool
    {
        $metadata = $this->getMetadata($entity);
        $identifier = $metadata->getIdentifier(true);

        if (empty($identifier)) {
            return false;
        }

        $result = $this->connection->executeQuery('SELECT 1 FROM '.$metadata->getTable().' WHERE '.$identifier.'='.$this->connection->quote($metadata->getValue($entity, $identifier, true)));

        return (bool) $result->fetchOne();
    }

    /**
     * Relate target entities to the entity's relation.
     *
     * @param  array<int|string, object>|object $entities
     * @param  QueryBuilder<object>             $query
     * @throws \LogicException
     */
    public function related(array|object $entities, string $name, QueryBuilder $query): void
    {
        if (!is_array($entities)) {
            $entities = [$entities];
        }

        $first = current($entities);
        if ($first === false) {
            return;
        }

        $metadata = $this->getMetadata($first);
        $mapping = $metadata->getRelationMapping($name);

        if (!class_exists($class = 'Pagekit\Database\ORM\\Relation\\'.$mapping['type'])) {
            throw new \LogicException(sprintf("Unable to find relation class '%s'", $class));
        }

        $relation = new $class($this, $metadata, $mapping);
        if (!$relation instanceof Relation\Relation) {
            throw new \LogicException(sprintf("Class '%s' is not a Relation.", $class));
        }
        $relation->resolve($entities, $query);
    }

    /**
     * Saves an entity.
     *
     * @param array<string, mixed> $data
     */
    public function save(object $entity, array $data = []): void
    {
        $metadata = $this->getMetadata($entity);
        $identifier = $metadata->getIdentifier(true);

        if ($identifier === null) {
            throw new \LogicException(sprintf("No identifier column mapping found for entity '%s'.", get_class($entity)));
        }

        $metadata->setValues($entity, $data, false, true);

        $this->trigger(Events::SAVING, $metadata, [$entity, $data]);

        if (!$id = $metadata->getValue($entity, $identifier, true)) {

            $this->trigger(Events::CREATING, $metadata, [$entity, $data]);

            $this->connection->insert($metadata->getTable(), $metadata->getValues($entity, true, true));

            $metadata->setValue($entity, $identifier, $this->connection->lastInsertId(), true, true);

            $this->trigger(Events::CREATED, $metadata, [$entity, $data]);

        } else {

            $this->trigger(Events::UPDATING, $metadata, [$entity, $data]);

            $this->connection->update($metadata->getTable(), $metadata->getValues($entity, true, true), [$identifier => $id]);

            $this->trigger(Events::UPDATED, $metadata, [$entity, $data]);
        }

        $this->trigger(Events::SAVED, $metadata, [$entity, $data]);

        // Invalidate query cache for this entity type
        $this->invalidateCache($metadata);
    }

    /**
     * Deletes an entity.
     *
     * @param  object $entity
     * @throws \InvalidArgumentException
     */
    public function delete(object $entity): void
    {
        $metadata = $this->getMetadata($entity);
        $identifier = $metadata->getIdentifier(true);

        if ($identifier === null) {
            throw new \LogicException(sprintf("No identifier column mapping found for entity '%s'.", get_class($entity)));
        }

        if ($value = $metadata->getValue($entity, $identifier, true)) {

            $this->trigger(Events::DELETING, $metadata, [$entity]);

            $this->connection->delete($metadata->getTable(), [$identifier => $value]);

            $this->trigger(Events::DELETED, $metadata, [$entity]);

            $metadata->setValue($entity, $identifier, null, true);

            // Invalidate query cache for this entity type
            $this->invalidateCache($metadata);

        } else {
            throw new \InvalidArgumentException("Can't remove entity with empty identifier value.");
        }
    }

    /**
     * Hydrates only one row of the passed statement.
     */
    public function hydrateOne(\Doctrine\DBAL\Result $statement, Metadata $metadata): object|false
    {
        if ($row = $statement->fetchAssociative()) {
            return $this->load($metadata, $row, true, true);
        }

        return false;
    }

    /**
     * Hydrates all rows returned by the passed statement instance at once.
     *
     * @return array<int|string, object>
     */
    public function hydrateAll(\Doctrine\DBAL\Result $statement, Metadata $metadata): array
    {
        $result = [];
        $identifier = $metadata->getIdentifier();

        if ($identifier === null) {
            throw new \LogicException(sprintf("No identifier field found for entity '%s'.", $metadata->getClass()));
        }

        while ($row = $statement->fetchAssociative()) {
            $entity = $this->load($metadata, $row, true, true);
            $result[$metadata->getValue($entity, $identifier)] = $entity;
        }

        return $result;
    }

    /**
     * Loads an entity or creates a new one if it does not already exist.
     *
     * @param array<string, mixed> $data
     */
    public function load(Metadata $metadata, array $data, bool $column = false, bool $convert = false): object
    {
        $class = $metadata->getClass();
        $entity = $metadata->newInstance();

        if (!$entity instanceof $class) {
            throw new \LogicException(sprintf(
                'EntityManager::load() expected an instance of %s, got %s.',
                $class,
                get_class($entity)
            ));
        }

        $metadata->setValues($entity, $data, $column, $convert);

        if ($entity instanceof SerializableModelInterface) {
            $entity->setSerializationMap($metadata->getSerializationMap());
        }

        $this->trigger(Events::INIT, $metadata, [$entity]);

        return $entity;
    }

    /**
     * Dispatches an entity lifecycle event to all registered listeners.
     *
     * The emitted {@see EntityEvent} carries this EntityManager, so lifecycle
     * handlers can reach queries and persistence via DI instead of statics.
     *
     * @param array<int, mixed> $arguments
     */
    public function trigger(string $name, Metadata $metadata, array $arguments): void
    {
        $event = new EntityEvent("{$metadata->getEventPrefix()}.{$name}", $this);

        $this->events->trigger($event, $arguments);
    }

    /**
     * Invalidates the query cache for the given entity type.
     *
     * @param  Metadata $metadata
     * @return void
     */
    protected function invalidateCache(Metadata $metadata): void
    {
        $cache = $this->metadata->getCache();

        if (!$cache) {
            return;
        }

        // TODO: Must be refactored in Step 4.3 (Performance Optimization) —
        // Replace $cache->clear() with tag-based invalidation (TagAwareCacheInterface)
        // to only invalidate cache entries for this specific entity type instead of the entire pool.
        $cache->clear();
    }
}
