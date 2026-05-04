<?php

declare(strict_types=1);

namespace Pagekit\Event;

class PrefixEventDispatcher implements EventDispatcherInterface
{
    protected string $prefix = '';

    protected EventDispatcherInterface $events;

    public function __construct(string $prefix, ?EventDispatcherInterface $events = null)
    {
        $this->prefix = $prefix;
        $this->events = $events ?: new EventDispatcher();
    }

    public function on(string $event, callable $listener, int $priority = 0): void
    {
        $this->events->on($this->prefix.$event, $listener, $priority);
    }

    public function off(string $event, ?callable $listener = null): void
    {
        $this->events->off($this->prefix.$event, $listener);
    }

    public function subscribe(EventSubscriberInterface $subscriber): void
    {
        $this->events->subscribe($subscriber);
    }

    public function unsubscribe(EventSubscriberInterface $subscriber): void
    {
        $this->events->unsubscribe($subscriber);
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int|string, mixed> $arguments
     */
    public function trigger($event, array $arguments = []): EventInterface
    {
        if (is_string($event)) {
            $event = $this->prefix.$event;
        } elseif ($event instanceof EventInterface) {
            $event->setName($this->prefix.$event->getName());
        }

        return $this->events->trigger($event, $arguments);
    }

    public function hasListeners(?string $event = null): bool
    {
        return $this->events->hasListeners($event);
    }

    /**
     * @return list<callable>|array<string, list<callable>>
     */
    public function getListeners(?string $event = null): array
    {
        return $this->events->getListeners($event);
    }

    public function getListenerPriority(string $event, callable $listener): ?int
    {
        return $this->events->getListenerPriority($event, $listener);
    }

    public function getEventClass(): string
    {
        return $this->events->getEventClass();
    }
}
