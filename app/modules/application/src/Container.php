<?php

namespace Pagekit;

use Pagekit\Container\ContainerException;
use Pagekit\Container\NotFoundException;
use Psr\Container\ContainerInterface;

class Container implements ContainerInterface
{
    protected array $values = [];

    protected array $raw = [];

    protected array $factories = [];

    /**
     * Constructor.
     *
     * @param array $values
     */
    public function __construct(array $values = [])
    {
        foreach ($values as $name => $value) {
            $this->set($name, $value);
        }

    }

    /**
     * Sets a closure as a factory service.
     *
     * @param string   $name
     * @param \Closure $closure
     */
    public function factory(string $name, \Closure $closure): void
    {
        $this->set($name, $closure);
        $this->factories[$name] = true;
    }

    /**
     * Extends an existing service definition.
     *
     * @param string   $name
     * @param \Closure $closure
     *
     * @throws \InvalidArgumentException
     */
    public function extend(string $name, \Closure $closure): void
    {
        if (!array_key_exists($name, $this->values)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not defined.', $name));
        }

        if (array_key_exists($name, $this->raw)) {
            // Service already resolved — apply decorator to the live instance.
            // Use case: debug module wraps EventDispatcher with TraceableEventDispatcher.
            // NOTE for extension developers: this branch only runs if get() was called
            // before extend(). The result replaces the resolved singleton; factory
            // services (registered via factory()) are never affected since they are
            // not stored in $raw.
            $this->values[$name] = $closure($this->values[$name], $this);
            $this->raw[$name] = $this->values[$name];

            return;
        }

        if (!($this->values[$name] instanceof \Closure)) {
            throw new \InvalidArgumentException(sprintf('"%s" service definition is not a Closure.', $name));
        }

        $factory = $this->values[$name];

        $this->values[$name] = fn ($c) => $closure($factory($c), $c);
    }

    /**
     * Gets a parameter/service without resolving.
     *
     * @param  string $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function raw($name)
    {
        if (!array_key_exists($name, $this->values)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not defined.', $name));
        }

        return isset($this->raw[$name]) ? $this->raw[$name] : $this->values[$name];
    }

    /**
     * Returns all defined names.
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /**
     * PSR-11: Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @throws NotFoundException  No entry was found for this identifier.
     * @throws ContainerException Error while retrieving the entry.
     *
     * @return mixed Entry.
     */
    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->values)) {
            throw new NotFoundException(sprintf('"%s" is not defined.', $id));
        }

        try {
            if (array_key_exists($id, $this->raw) || !($this->values[$id] instanceof \Closure)) {
                return $this->values[$id];
            }

            if (isset($this->factories[$id])) {
                return $this->values[$id]($this);
            }

            $this->raw[$id] = $this->values[$id];

            return $this->values[$id] = $this->values[$id]($this);
        } catch (\Exception $e) {
            throw new ContainerException(sprintf('Error while retrieving "%s"', $id), 0, $e);
        }
    }

    /**
     * PSR-11: Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for.
     *
     * @return bool
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->values);
    }

    /**
     * Sets a parameter/service.
     *
     * @throws \RuntimeException
     */
    public function set(string $id, mixed $value): void
    {
        if (array_key_exists($id, $this->raw)) {
            throw new \RuntimeException(sprintf('Cannot override service definition "%s".', $id));
        }

        $this->values[$id] = $value;
    }

    /**
     * Removes a parameter/service.
     */
    public function remove(string $id): void
    {
        unset($this->values[$id], $this->raw[$id], $this->factories[$id]);
    }
}
