<?php

namespace Pagekit\Event;

use Symfony\Contracts\EventDispatcher\Event as SymfonyEvent;

/**
 * Adapter to wrap Symfony events for use in Pagekit's event system
 */
class SymfonyEventAdapter extends Event
{
    protected SymfonyEvent $symfonyEvent;

    public function __construct(string $name, SymfonyEvent $symfonyEvent, array $parameters = [])
    {
        parent::__construct($name, $parameters);
        $this->symfonyEvent = $symfonyEvent;
    }

    /**
     * Get the wrapped Symfony event
     */
    public function getSymfonyEvent(): SymfonyEvent
    {
        return $this->symfonyEvent;
    }

    /**
     * {@inheritdoc}
     */
    public function stopPropagation(): void
    {
        parent::stopPropagation();
        $this->symfonyEvent->stopPropagation();
    }

    /**
     * {@inheritdoc}
     */
    public function isPropagationStopped(): bool
    {
        return parent::isPropagationStopped() || $this->symfonyEvent->isPropagationStopped();
    }

    /**
     * Proxy method calls to the Symfony event
     */
    public function __call($method, $arguments)
    {
        if (method_exists($this->symfonyEvent, $method)) {
            return call_user_func_array([$this->symfonyEvent, $method], $arguments);
        }
        
        throw new \BadMethodCallException(sprintf('Method "%s" does not exist.', $method));
    }

    /**
     * Proxy property access to the Symfony event
     */
    public function __get($property)
    {
        if (property_exists($this->symfonyEvent, $property)) {
            return $this->symfonyEvent->$property;
        }
        
        return $this->parameters[$property] ?? null;
    }

    /**
     * Proxy property setting to the Symfony event
     */
    public function __set($property, $value)
    {
        if (property_exists($this->symfonyEvent, $property)) {
            $this->symfonyEvent->$property = $value;
        } else {
            $this->parameters[$property] = $value;
        }
    }
}