<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

use Pagekit\Database\Connection;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Database\Events;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Event\PrefixEventDispatcher;

class EntityManager
{
    protected Connection $connection;

    protected MetadataManager $metadata;

    protected EventDispatcherInterface $events;

    protected static ?self $instance = null;

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
        $this->metadata   = $metadata;
        $this->events     = $events ?: new PrefixEventDispatcher('model.');

        static::$instance = $this;
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
     * @param  mixed $class
     */
    public function getMetadata($class): Metadata
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
     * Retrieve an entity by its identifier.
     *
     * @param  string $entity
     * @param  mixed  $identifier
     * @return object|null
     */
    public function find(string $entity, mixed $identifier): ?object
    {
        $callable = "{$entity}::find";
        if (is_callable($callable)) {
            return call_user_func($callable, $identifier);
        }
        
        return null;
    }

    /**
     * Checks whether the given managed entity exists in the database.
     *
     * @param  object $entity
     */
    public function exists(object $entity): bool
    {
        $metadata   = $this->getMetadata($entity);
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
     * @param  array|object $entities
     * @param  string       $name
     * @param  QueryBuilder $query
     * @throws \LogicException
     */
    public function related(array|object $entities, string $name, QueryBuilder $query): void
    {
        if (!is_array($entities)) {
            $entities = [$entities];
        }

        $metadata = $this->getMetadata(current($entities));
        $mapping  = $metadata->getRelationMapping($name);

        if (!class_exists($class = 'Pagekit\Database\ORM\\Relation\\'.$mapping['type'])) {
            throw new \LogicException(sprintf("Unable to find relation class '%s'", $class));
        }

        $relation = new $class($this, $metadata, $mapping);
        $relation->resolve($entities, $query);
    }

    /**
     * Saves an entity.
     *
     * @param object $entity
     * @param array  $data
     */
    public function save(object $entity, array $data = []): void
    {
        $metadata   = $this->getMetadata($entity);
        $identifier = $metadata->getIdentifier(true);

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
        $metadata   = $this->getMetadata($entity);
        $identifier = $metadata->getIdentifier(true);

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
     *
     * @param  object   $statement
     * @param  Metadata $metadata
     * @return object|false
     */
    public function hydrateOne(object $statement, Metadata $metadata): object|false
    {
        if ($row = $statement->fetchAssociative()) {
            return $this->load($metadata, $row, true, true);
        }

        return false;
    }

    /**
     * Hydrates all rows returned by the passed statement instance at once.
     *
     * @param  object   $statement
     * @param  Metadata $metadata
     * @return array
     */
    public function hydrateAll(object $statement, Metadata $metadata): array
    {
        $result     = [];
        $identifier = $metadata->getIdentifier();

        while ($row = $statement->fetchAssociative()) {
            $entity = $this->load($metadata, $row, true, true);
            $result[$metadata->getValue($entity, $identifier)] = $entity;
        }

        return $result;
    }

    /**
     * Loads an entity or creates a new one if it does not already exist.
     *
     * @param  Metadata $metadata
     * @param  array    $data
     * @param  bool     $column
     * @param  bool     $convert
     */
    public function load(Metadata $metadata, array $data, bool $column = false, bool $convert = false): object
    {
        $entity = $metadata->newInstance();
        $metadata->setValues($entity, $data, $column, $convert);

        $this->trigger(Events::INIT, $metadata, [$entity]);

        return $entity;
    }

    /**
     * Dispatches an event to all registered listeners.
     *
     * @param  string   $name
     * @param  Metadata $metadata
     * @param  array    $arguments
     */
    public function trigger(string $name, Metadata $metadata, array $arguments): void
    {
        $this->events->trigger("{$metadata->getEventPrefix()}.{$name}", $arguments);
    }

    /**
     * Gets the instance.
     */
    public static function getInstance(): ?self
    {
        return static::$instance;
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
        
        // Clear all cache items
        // TODO: Implement cache invalidation strategy
        // Note: PSR-6 doesn't have a built-in way to delete by pattern
        // This is a simplified implementation - in production, you might use cache tags
        // or a more sophisticated cache invalidation strategy
        if ($cache instanceof \Psr\Cache\CacheItemPoolInterface) {
            $cache->clear();
        } else {
            // Legacy CacheInterface
            $cache->flushAll();
        }
    }
}
