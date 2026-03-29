<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Relation;

use Pagekit\Database\ORM\QueryBuilder;

class ManyToMany extends Relation
{
    /**
     * The identifier of the intermediate table
     */
    protected string $tableThrough;

    /**
     * The foreign key of the parent entity in the intermediate table
     */
    protected string $keyThroughFrom;

    /**
     * The foreign key of the target entity in the intermediate table
     */
    protected string $keyThroughTo;

    /**
     * The order by condition
     */
    protected array $orderBy;

    /**
     * {@inheritdoc}
     */
    public function __construct(\Pagekit\Database\ORM\EntityManager $manager, \Pagekit\Database\ORM\Metadata $metadata, array $mapping)
    {
        parent::__construct($manager, $metadata, $mapping);

        // Validate required ManyToMany parameters
        $requiredParams = ['tableThrough', 'keyThroughFrom', 'keyThroughTo'];
        foreach ($requiredParams as $param) {
            if (empty($mapping[$param])) {
                throw new \InvalidArgumentException(sprintf(
                    'ManyToMany relation "%s" on "%s" requires "%s" parameter.',
                    $mapping['name'] ?? 'unknown',
                    $metadata->getClass(),
                    $param
                ));
            }
        }

        $this->keyFrom = (isset($mapping['keyFrom']) && $mapping['keyFrom']) ? $mapping['keyFrom'] : $this->targetMetadata->getIdentifier();
        $this->keyTo = (isset($mapping['keyTo']) && $mapping['keyTo']) ? $mapping['keyTo'] : $metadata->getIdentifier();
        $this->tableThrough = $mapping['tableThrough'];
        $this->keyThroughFrom = $mapping['keyThroughFrom'];
        $this->keyThroughTo = $mapping['keyThroughTo'];
        $this->orderBy = $mapping['orderBy'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(array $entities, QueryBuilder $query): void
    {
        $this->initRelation($entities, []);

        $keys = $this->getKeys($entities);
        $result = $this->manager->getConnection()
            ->executeQuery("SELECT {$this->keyThroughFrom}, {$this->keyThroughTo} FROM {$this->tableThrough} WHERE {$this->keyThroughFrom} IN (".implode(", ", $keys).")")
            ->fetchAllNumeric();

        // Manually group by first column, values from second column
        $mapping = [];
        foreach ($result as $row) {
            $mapping[$row[0]][] = $row[1];
        }

        $table = $this->tableThrough;
        $to = $this->keyThroughTo;
        $from = $this->keyThroughFrom;
        $targets = $query->whereIn($this->keyTo, fn ($query) => $query
            ->select($to)
            ->from($table)
            ->whereIn($from, $keys))->get();

        $metadata = $this->metadata;
        $targetMetadata = $this->targetMetadata;
        $to = $this->keyTo;
        $from = $this->keyFrom;

        foreach ($mapping as $id => $targetIds) {

            $entity = current(array_filter($entities, fn ($entity) => $metadata->getValue($entity, $from, true) == $id));

            $metadata->setValue($entity, $this->name, array_filter($targets, fn ($target) => in_array($targetMetadata->getValue($target, $to, true), $targetIds)));
        }

        $this->resolveRelations($query, $targets);
    }
}
