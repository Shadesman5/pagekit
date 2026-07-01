<?php

declare(strict_types=1);

namespace Pagekit\Event;

/**
 * @implements \ArrayAccess<string, mixed>
 */
class Event implements EventInterface, \ArrayAccess
{
    protected string $name;

    /** @var array<string, mixed> */
    protected array $parameters;

    protected bool $propagationStopped = false;

    protected ?EventDispatcherInterface $dispatcher = null;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(string $name, array $parameters = [])
    {
        $this->name = $name;
        $this->parameters = $parameters;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function setParameters(array $parameters): self
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function addParameters(array $values, bool $replace = false): self
    {
        if ($replace) {
            $this->parameters = array_replace_recursive($this->parameters, $values);

            return $this;
        }

        foreach ($values as $key => $value) {
            if (!isset($this->parameters[$key])) {
                $this->parameters[$key] = $value;
            } elseif (is_array($value) && is_array($this->parameters[$key])) {
                $this->parameters[$key] = array_replace_recursive($this->parameters[$key], $value);
            } else {
                $this->parameters[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getDispatcher(): EventDispatcherInterface
    {
        if ($this->dispatcher === null) {
            throw new \LogicException('Event: dispatcher has not been set.');
        }

        return $this->dispatcher;
    }

    /**
     * Sets the event dispatcher.
     *
     * @param EventDispatcherInterface $dispatcher
     */
    public function setDispatcher(EventDispatcherInterface $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * {@inheritdoc}
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * {@inheritdoc}
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function offsetExists(mixed $name): bool
    {
        return isset($this->parameters[$name]);
    }

    /** @return mixed Genuinely unknown type — implements \ArrayAccess on event parameters; value type depends on what was registered at offset. */
    public function offsetGet(mixed $name): mixed
    {
        return $this->parameters[$name] ?? null;
    }

    public function offsetSet(mixed $name, mixed $callback): void
    {
        $this->parameters[$name] = $callback;
    }

    public function offsetUnset(mixed $name): void
    {
        unset($this->parameters[$name]);
    }
}
