<?php

declare(strict_types=1);

namespace Pagekit\Event;

interface EventDispatcherInterface
{
    /**
     * Adds an event listener.
     */
    public function on(string $event, callable $listener, int $priority = 0): void;

    /**
     * Removes one or more event listeners.
     */
    public function off(string $event, ?callable $listener = null): void;

    /**
     * Adds an event subscriber.
     */
    public function subscribe(EventSubscriberInterface $subscriber): void;

    /**
     * Removes an event subscriber.
     */
    public function unsubscribe(EventSubscriberInterface $subscriber): void;

    /**
     * Triggers an event.
     *
     * @param string|EventInterface    $event
     * @param array<int|string, mixed> $arguments
     */
    public function trigger($event, array $arguments = []): EventInterface;

    /**
     * Checks if a event has listeners.
     */
    public function hasListeners(?string $event = null): bool;

    /**
     * Gets all listeners of an event.
     *
     * @return list<callable>|array<string, list<callable>>
     */
    public function getListeners(?string $event = null): array;

    /**
     * Gets the listener priority for a specific event.
     */
    public function getListenerPriority(string $event, callable $listener): ?int;

    /**
     * Gets the default Event class.
     */
    public function getEventClass(): string;
}
