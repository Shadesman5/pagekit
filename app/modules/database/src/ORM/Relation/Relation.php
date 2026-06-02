<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Relation;

use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\QueryBuilder;

/**
 * The Relation class handles relations between entities.
 */
abstract class Relation
{
    /**
     * The entity manager
     */
    protected EntityManager $manager;

    /**
     * The parent entity metadata
     */
    protected Metadata $metadata;

    /**
     * The name of the relationship in the parent entity
     */
    protected string $name;

    /**
     * The classname of the target entity
     */
    protected string $targetEntity;

    /**
     * The primary key of source entity
     */
    protected string $keyFrom;

    /**
     * The foreign key of target entity
     */
    protected string $keyTo;

    /**
     * The target metadata
     */
    protected Metadata $targetMetadata;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $mapping
     */
    public function __construct(EntityManager $manager, Metadata $metadata, array $mapping)
    {
        $this->manager = $manager;
        $this->metadata = $metadata;

        if (!$this->name = $mapping['name']) {
            throw new \InvalidArgumentException('The parameter "name" may not be omitted in relations.');
        }
        $this->targetEntity = $mapping['targetEntity'];
        $this->targetMetadata = $manager->getMetadata($mapping['targetEntity']);
    }

    /**
     * Resolves the entity relation.
     *
     * @param array<int, object> $entities
     */
    abstract public function resolve(array $entities, QueryBuilder $query): void;

    /**
     * Initialize the relationship
     *
     * @param array<int, object> $entities
     */
    protected function initRelation(array $entities, mixed $default = false): void
    {
        foreach ($entities as $entity) {
            $this->metadata->setValue($entity, $this->name, $default);
        }
    }

    /**
     * Gets the related keys
     *
     * @param  array<int, object> $entities
     * @return array<int, mixed>
     */
    protected function getKeys(array $entities, ?string $key = null): array
    {

        $key = $key ?: $this->keyFrom;
        $keys = [];

        foreach ($entities as $entity) {
            if ($value = $this->metadata->getValue($entity, $key, true)) {
                $keys[] = $value;
            }
        }

        return array_unique($keys);
    }

    /**
     * Map targets to entities
     *
     * @param array<int, object> $entities
     * @param array<int, object> $targets
     */
    protected function map(array $entities, array $targets): void
    {
        $identifier = $this->targetMetadata->getIdentifier();

        foreach ($targets as $target) {

            $id = $this->targetMetadata->getValue($target, $this->keyTo, true);

            foreach ($entities as $entity) {
                if ($id == $this->metadata->getValue($entity, $this->keyFrom, true)) {
                    $value = $this->metadata->getValue($entity, $this->name);

                    if (is_array($value)) {
                        $value[$this->targetMetadata->getValue($target, $identifier)] = $target;
                    } else {
                        $value = $target;
                    }

                    $this->metadata->setValue($entity, $this->name, $value);
                }
            }
        }
    }

    /**
     * Resolve additional relations
     *
     * @param array<int, object> $targets
     */
    protected function resolveRelations(QueryBuilder $query, array $targets): void
    {
        if (!$targets) {
            return;
        }

        foreach ($query->getRelations() as $name => $q) {
            $this->manager->related($targets, $name, $q);
        }
    }
}
