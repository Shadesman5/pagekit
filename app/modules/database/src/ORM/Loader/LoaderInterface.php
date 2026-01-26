<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM\Loader;

interface LoaderInterface
{
    /**
     * Loads the metadata config for a given class.
     *
     * @param  \ReflectionClass $class
     * @param  array            $config
     */
    public function load(\ReflectionClass $class, array $config = []): array;

    /**
     * A transient class does NOT have #[Entity] or #[MappedSuperclass] attribute.
     *
     * @param  \ReflectionClass $class
     */
    public function isTransient(\ReflectionClass $class): bool;
}
