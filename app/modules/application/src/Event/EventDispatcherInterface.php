<?php

namespace Pagekit\Event;

interface EventDispatcherInterface
{
    /**
     * Adds an event listener.
     *
     * @param string   $event
     * @param callable $listener
     * @param int      $priority
     */
    public function on($event, $listener, $priority = 0);

    /**
     * Removes one or more event listeners.
     *
     * @param string   $event
     * @param callable $listener
     */
    public function off($event, $listener = null);

    /**
     * Adds an event subscriber.
     *
     * @param EventSubscriberInterface $subscriber
     */
    public function subscribe(EventSubscriberInterface $subscriber);

    /**
     * Removes an event subscriber.
     *
     * @param EventSubscriberInterface $subscriber
     */
    public function unsubscribe(EventSubscriberInterface $subscriber);

    /**
     * Triggers an event.
     *
     * @param  string|EventInterface $event
     * @param  array                 $arguments
     */
    public function trigger($event, array $arguments = []): EventInterface;

    /**
     * Checks if a event has listeners.
     *
     * @param  string $event
     */
    public function hasListeners($event = null): bool;

    /**
     * Gets all listeners of an event.
     *
     * @param  string $event
     */
    public function getListeners($event = null): array;

    /**
     * Gets the listener priority for a specific event.
     *
     * @param  string   $event
     * @param  callable $listener
     * @return int|null The event listener priority
     */
    public function getListenerPriority($event, $listener): ?int;

    /**
     * Gets the default Event class.
     */
    public function getEventClass(): string;
}
