<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Relation;

use Pagekit\Database\ORM\QueryBuilder;

class BelongsTo extends Relation
{
    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $mapping
     */
    public function __construct(\Pagekit\Database\ORM\EntityManager $manager, \Pagekit\Database\ORM\Metadata $metadata, array $mapping)
    {
        parent::__construct($manager, $metadata, $mapping);

        // Validate required parameter
        if (empty($mapping['keyFrom'])) {
            throw new \InvalidArgumentException(sprintf(
                'BelongsTo relation "%s" on "%s" requires "keyFrom" parameter.',
                $mapping['name'] ?? 'unknown',
                $metadata->getClass()
            ));
        }

        $this->keyFrom = $mapping['keyFrom'];
        $this->keyTo = (isset($mapping['keyTo']) && $mapping['keyTo']) ? $mapping['keyTo'] : ($this->targetMetadata->getIdentifier() ?? throw new \InvalidArgumentException(sprintf('BelongsTo relation "%s" on "%s": target entity has no identifier and "keyTo" was not specified.', $mapping['name'] ?? 'unknown', $metadata->getClass())));
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int|string, object> $entities
     * @param QueryBuilder<object>      $query
     */
    public function resolve(array $entities, QueryBuilder $query): void
    {
        $this->initRelation($entities);

        if (!$keys = $this->getKeys($entities)) {
            return;
        }

        $targets = $query->whereIn($this->keyTo, $keys)->get();

        $this->map($entities, $targets);
        $this->resolveRelations($query, $targets);
    }
}
