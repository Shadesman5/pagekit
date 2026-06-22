<?php

declare(strict_types=1);

namespace Pagekit\Event;

interface EventInterface
{
    /**
     * Gets the event name.
     */
    public function getName(): string;

    /**
     * Sets the event name. Called by dispatchers that prefix event names
     * (e.g. PrefixEventDispatcher) before delegating to the inner dispatcher.
     */
    public function setName(string $name): self;

    /**
     * Gets the event dispatcher.
     */
    public function getDispatcher(): EventDispatcherInterface;

    /**
     * Sets the event dispatcher. Called by EventDispatcher::trigger() before
     * invoking listeners so they can access the dispatcher via $event->getDispatcher().
     */
    public function setDispatcher(EventDispatcherInterface $dispatcher): void;

    /**
     * Is propagation stopped?
     */
    public function isPropagationStopped(): bool;

    /**
     * Stop further event propagation.
     */
    public function stopPropagation(): void;
}
