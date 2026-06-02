<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Relation;

use Pagekit\Database\ORM\QueryBuilder;

class HasMany extends HasOne
{
    /** @var array<string, string> */
    protected array $orderBy;

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $mapping
     */
    public function __construct(\Pagekit\Database\ORM\EntityManager $manager, \Pagekit\Database\ORM\Metadata $metadata, array $mapping)
    {
        parent::__construct($manager, $metadata, $mapping);

        $this->orderBy = $mapping['orderBy'] ?? [];
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int, object> $entities
     */
    public function resolve(array $entities, QueryBuilder $query): void
    {
        $this->initRelation($entities, []);

        if (!$keys = $this->getKeys($entities)) {
            return;
        }

        if ($this->orderBy) {
            foreach ($this->orderBy as $column => $order) {
                $query->orderBy($column, $order);
            }
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
                foreach ($this->metadata->getValue($entity, $this->name) as $target) {
                    $this->targetMetadata->setValue($target, $this->belongsTo, $entity, true);
                }
            }
        }
    }
}
