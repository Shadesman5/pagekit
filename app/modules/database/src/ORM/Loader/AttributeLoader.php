<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Loader;

use Pagekit\Database\ORM\Attribute\BelongsTo;
use Pagekit\Database\ORM\Attribute\Column;
use Pagekit\Database\ORM\Attribute\Entity;
use Pagekit\Database\ORM\Attribute\HasMany;
use Pagekit\Database\ORM\Attribute\HasOne;
use Pagekit\Database\ORM\Attribute\Id;
use Pagekit\Database\ORM\Attribute\ManyToMany;
use Pagekit\Database\ORM\Attribute\MappedSuperclass;
use Pagekit\Database\ORM\Attribute\OrderBy;

/**
 * Loads entity metadata from PHP 8 Attributes.
 *
 * Replaces AnnotationLoader as part of Step 1.14 (ORM Attributes migration).
 */
class AttributeLoader implements LoaderInterface
{
    /**
     * {@inheritdoc}
     *
     * @param \ReflectionClass<object> $class
     * @param array<string, mixed>     $config
     * @return array<string, mixed>
     */
    public function load(\ReflectionClass $class, array $config = []): array
    {
        // Get Entity or MappedSuperclass attribute
        $entityAttr = $this->getClassAttribute($class, Entity::class);
        $mappedSuperclassAttr = $this->getClassAttribute($class, MappedSuperclass::class);

        if ($entityAttr) {
            $config['table'] = $entityAttr->tableClass ?: strtolower($class->getShortName());
            $config['eventPrefix'] = $entityAttr->eventPrefix;
        } elseif ($mappedSuperclassAttr) {
            $config['isMappedSuperclass'] = true;
        } else {
            throw new \Exception(sprintf('No #[Entity] or #[MappedSuperclass] attribute found for class %s', $class->getName()));
        }

        // Process properties
        foreach ($class->getProperties() as $property) {
            $name = $property->getName();

            // Skip logic matching old AnnotationLoader behavior:
            // 1. Skip non-private properties in MappedSuperclass context
            // 2. Always skip inherited properties (regardless of visibility)
            if ((!$property->isPrivate() && isset($config['isMappedSuperclass'])) || isset($config['fields'][$name]['inherited']) || isset($config['relations'][$name]['inherited'])) {
                continue;
            }

            // Check for Column attribute
            $columnAttr = $this->getPropertyAttribute($property, Column::class);
            if ($columnAttr) {
                $field = ['name' => $name];

                if (isset($config['fields'][$name])) {
                    throw new \Exception(sprintf('Duplicate field mapping detected, "%s" already exists.', $name));
                }

                if ($columnAttr->type) {
                    $field['type'] = (string) $columnAttr->type;
                }

                if ($columnAttr->name) {
                    $field['column'] = $columnAttr->name;
                }

                // Check for Id attribute
                if ($this->getPropertyAttribute($property, Id::class)) {
                    $field['id'] = true;
                }

                $config['fields'][$name] = $field;
            } else {
                // Check for relation attributes
                $belongsToAttr = $this->getPropertyAttribute($property, BelongsTo::class);
                $hasOneAttr = $this->getPropertyAttribute($property, HasOne::class);
                $hasManyAttr = $this->getPropertyAttribute($property, HasMany::class);
                $manyToManyAttr = $this->getPropertyAttribute($property, ManyToMany::class);

                $relationAttr = $belongsToAttr ?: $hasOneAttr ?: $hasManyAttr ?: $manyToManyAttr;
                $relationType = null;
                $relationData = [];

                if ($belongsToAttr) {
                    $relationType = 'BelongsTo';
                    $relationData = [
                        'targetEntity' => $belongsToAttr->targetEntity,
                        'keyFrom' => $belongsToAttr->keyFrom,
                        'keyTo' => $belongsToAttr->keyTo,
                    ];
                } elseif ($hasOneAttr) {
                    $relationType = 'HasOne';
                    $relationData = [
                        'targetEntity' => $hasOneAttr->targetEntity,
                        'keyFrom' => $hasOneAttr->keyFrom,
                        'keyTo' => $hasOneAttr->keyTo,
                    ];
                } elseif ($hasManyAttr) {
                    $relationType = 'HasMany';
                    $relationData = [
                        'targetEntity' => $hasManyAttr->targetEntity,
                        'keyFrom' => $hasManyAttr->keyFrom,
                        'keyTo' => $hasManyAttr->keyTo,
                    ];
                } elseif ($manyToManyAttr) {
                    $relationType = 'ManyToMany';
                    $relationData = [
                        'targetEntity' => $manyToManyAttr->targetEntity,
                        'keyFrom' => $manyToManyAttr->keyFrom,
                        'keyTo' => $manyToManyAttr->keyTo,
                        'keyThroughFrom' => $manyToManyAttr->keyThroughFrom,
                        'keyThroughTo' => $manyToManyAttr->keyThroughTo,
                        'tableThrough' => $manyToManyAttr->tableThrough,
                    ];
                }

                if ($relationAttr) {
                    if (isset($config['fields'][$name]) || isset($config['relations'][$name])) {
                        throw new \Exception(sprintf('Duplicate relation mapping detected, "%s" already exists.', $name));
                    }

                    // Check for OrderBy attribute
                    $orderByAttr = $this->getPropertyAttribute($property, OrderBy::class);
                    if ($orderByAttr) {
                        // Parse "column DESC" or "column ASC" format into array
                        $relationData['orderBy'] = $this->parseOrderBy($orderByAttr->value);
                    }

                    $config['relations'][$name] = array_merge(
                        ['name' => $name, 'type' => $relationType],
                        $relationData
                    );
                }
            }
        }

        // Process methods for event attributes
        foreach ($class->getMethods() as $method) {
            $name = $method->getName();

            if (!$method->isPublic() || $method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            // Check for event attributes: Saving, Saved, Updating, Updated, Deleting, Deleted, Created, Creating, Init
            $eventAttributes = [
                'Saving', 'Saved', 'Updating', 'Updated',
                'Deleting', 'Deleted', 'Created', 'Creating', 'Init',
            ];

            foreach ($eventAttributes as $eventName) {
                $eventClass = "Pagekit\\Database\\ORM\\Attribute\\{$eventName}";
                if ($this->getMethodAttribute($method, $eventClass)) {
                    $config['events'][lcfirst($eventName)][] = $name;
                    // No break - allow multiple event attributes on same method
                }
            }
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     *
     * @param \ReflectionClass<object> $class
     */
    public function isTransient(\ReflectionClass $class): bool
    {
        $entityAttr = $this->getClassAttribute($class, Entity::class);
        $mappedSuperclassAttr = $this->getClassAttribute($class, MappedSuperclass::class);

        return !$entityAttr && !$mappedSuperclassAttr;
    }

    /**
     * Parses OrderBy string value into array format.
     *
     * Converts "column DESC" or "column ASC" to ['column' => 'DESC']
     * Also supports multiple columns: "col1 DESC, col2 ASC" -> ['col1' => 'DESC', 'col2' => 'ASC']
     *
     * @return array<string, string>
     */
    protected function parseOrderBy(string $value): array
    {
        $result = [];
        $parts = array_map('trim', explode(',', $value));

        foreach ($parts as $part) {
            if (preg_match('/^(\w+)\s+(ASC|DESC)$/i', $part, $matches)) {
                $result[$matches[1]] = strtoupper($matches[2]);
            } elseif (preg_match('/^(\w+)$/i', $part, $matches)) {
                // Default to ASC if no direction specified
                $result[$matches[1]] = 'ASC';
            }
        }

        return $result;
    }

    /**
     * Gets a class attribute.
     *
     * @param \ReflectionClass<object> $class
     */
    protected function getClassAttribute(\ReflectionClass $class, string $attributeClass): ?object
    {
        $attributes = $class->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);

        return !empty($attributes) ? $attributes[0]->newInstance() : null;
    }

    /**
     * Gets a property attribute.
     */
    protected function getPropertyAttribute(\ReflectionProperty $property, string $attributeClass): ?object
    {
        $attributes = $property->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);

        return !empty($attributes) ? $attributes[0]->newInstance() : null;
    }

    /**
     * Gets a method attribute.
     */
    protected function getMethodAttribute(\ReflectionMethod $method, string $attributeClass): ?object
    {
        $attributes = $method->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);

        return !empty($attributes) ? $attributes[0]->newInstance() : null;
    }
}
