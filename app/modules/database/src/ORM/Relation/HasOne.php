<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Relation;

use Pagekit\Database\ORM\QueryBuilder;

class HasOne extends Relation
{
    protected string $belongsTo;

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $mapping
     */
    public function __construct(\Pagekit\Database\ORM\EntityManager $manager, \Pagekit\Database\ORM\Metadata $metadata, array $mapping)
    {
        parent::__construct($manager, $metadata, $mapping);

        // Validate required parameter
        if (empty($mapping['keyTo'])) {
            throw new \InvalidArgumentException(sprintf(
                '%s relation "%s" on "%s" requires "keyTo" parameter.',
                (new \ReflectionClass($this))->getShortName(),
                $mapping['name'] ?? 'unknown',
                $metadata->getClass()
            ));
        }

        $this->keyFrom = (isset($mapping['keyFrom']) && $mapping['keyFrom']) ? $mapping['keyFrom'] : $metadata->getIdentifier();
        $this->keyTo = $mapping['keyTo'];

        foreach ($this->targetMetadata->getRelationMappings() as $relationMapping) {
            if ($relationMapping['type'] == 'BelongsTo' && $relationMapping['targetEntity'] == $this->metadata->getClass()) {
                $this->belongsTo = $relationMapping['name'];

                break;
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int, object> $entities
     */
    public function resolve(array $entities, QueryBuilder $query): void
    {
        $this->initRelation($entities);

        if (!$keys = $this->getKeys($entities)) {
            return;
        }

        $targets = $query->whereIn($this->keyTo, $keys)->get();

        $this->map($entities, $targets);
        $this->mapBelongsTo($entities);
        $this->resolveRelations($query, $targets);
    }

    /**
     * @param array<int, object> $entities
     */
    protected function mapBelongsTo(array $entities): void
    {
        if ($this->belongsTo) {
            foreach ($entities as $entity) {
                if ($target = $this->metadata->getValue($entity, $this->name)) {
                    $this->targetMetadata->setValue($target, $this->belongsTo, $entity, true);
                }
            }
        }
    }
}
