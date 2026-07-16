<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

/**
 * Implemented by entities whose {@see \Pagekit\Database\ORM\ModelTrait::toArray()}
 * serialization depends on mapping data (relation names + field types).
 *
 * {@see EntityManager::load()} injects a pre-computed, serialization-safe map
 * (plain arrays, never a live {@see Metadata} instance) into every hydrated
 * instance via {@see setSerializationMap()}. This keeps `toArray()` free of the
 * global model statics while leaving the entity itself safe to `serialize()`.
 */
interface SerializableModelInterface
{
    /**
     * Injects the pre-computed serialization map produced by
     * {@see Metadata::getSerializationMap()}.
     *
     * @param array{relations: list<string>, fieldTypes: array<string, string>} $map
     */
    public function setSerializationMap(array $map): void;
}
