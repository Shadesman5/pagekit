<?php

namespace Pagekit\Container;

use Pagekit\Container;
use Psr\Container\ContainerInterface;

/**
 * PSR-11 Adapter for Pagekit Container.
 * 
 * This adapter provides PSR-11 compliance while avoiding method name conflicts
 * with the existing static methods in the Application class.
 */
class Psr11Adapter implements ContainerInterface
{
    protected Container $container;

    /**
     * Constructor.
     *
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * PSR-11: Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @throws NotFoundExceptionInterface  No entry was found for this identifier.
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     *
     * @return mixed Entry.
     */
    public function get(string $id)
    {
        return $this->container->getService($id);
    }

    /**
     * PSR-11: Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @return bool
     */
    public function has(string $id): bool
    {
        return $this->container->hasService($id);
    }
}