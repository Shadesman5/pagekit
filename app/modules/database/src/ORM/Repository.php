<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

/**
 * Generic data-mapper repository for a single mapped entity class.
 *
 * The {@see EntityManager} only ever builds generic repositories (one cached
 * instance per class); custom repositories with extra finders are registered as
 * container services and extend this class. `@template T of object` carries the
 * mapped entity type through the query/finder methods.
 *
 * @template T of object
 */
class Repository
{
    public function __construct(
        protected readonly EntityManager $em,
        protected readonly Metadata $metadata
    ) {
    }

    /**
     * Creates a new query builder for the mapped entity.
     *
     * @return QueryBuilder<T>
     */
    public function query(): QueryBuilder
    {
        /** @var QueryBuilder<T> $query */
        $query = new QueryBuilder($this->em, $this->metadata);

        return $query;
    }

    /**
     * Creates a new query builder and sets the WHERE condition.
     *
     * @param  array<int|string, mixed> $params
     * @return QueryBuilder<T>
     */
    public function where(mixed $condition, array $params = []): QueryBuilder
    {
        return $this->query()->where($condition, $params);
    }

    /**
     * Retrieves an entity by its identifier.
     *
     * @return T|null
     */
    public function find(int|string $id): ?object
    {
        $identifier = $this->metadata->getIdentifier();

        if ($identifier === null) {
            throw new \LogicException(sprintf("No identifier field found for entity '%s'.", $this->metadata->getClass()));
        }

        return $this->where([$identifier => $id])->first();
    }

    /**
     * Retrieves all entities.
     *
     * @return array<int|string, T>
     */
    public function findAll(): array
    {
        return $this->query()->get();
    }

    /**
     * Creates a new, hydrated instance of the mapped entity.
     *
     * @param  array<string, mixed> $data
     * @return T
     */
    public function create(array $data = []): object
    {
        /** @var T $entity */
        $entity = $this->em->load($this->metadata, $data);

        return $entity;
    }

    /**
     * Persists an entity.
     *
     * @param array<string, mixed> $data
     */
    public function save(object $entity, array $data = []): void
    {
        $this->em->save($entity, $data);
    }

    /**
     * Deletes an entity.
     */
    public function delete(object $entity): void
    {
        $this->em->delete($entity);
    }

    /**
     * Strips a role id from every row's `roles` column.
     *
     * Ports the SQL that used to live on `AccessModelTrait::removeRole()`.
     * Intentionally `int`-only so the database module never has to import
     * {@see \Pagekit\User\Model\Role}; callers narrow a Role to its id.
     *
     * @throws \LogicException when the mapped entity has no `roles` field
     */
    public function removeRole(int $roleId): int
    {
        if ($this->metadata->getField('roles') === null) {
            throw new \LogicException(sprintf("Entity '%s' has no 'roles' field; removeRole() is not supported.", $this->metadata->getClass()));
        }

        $db = $this->em->getConnection();
        $platform = $db->getDatabasePlatform();

        return $db->executeStatement('UPDATE '.$this->metadata->getTable().' SET roles = NULLIF('.$platform->getTrimExpression("REPLACE (".$platform->getConcatExpression($db->quote(','), 'roles', $db->quote(',')).", ',{$roleId},', ',')", 3, $db->quote(',')).", '')");
    }
}
