<?php

declare(strict_types=1);

namespace Pagekit\Event;

class EventDispatcher implements EventDispatcherInterface
{
    protected string $event;

    /** @var array<string, array<int, list<callable>>> */
    protected array $listeners = [];

    /** @var array<string, list<callable>> */
    protected array $sorted = [];

    /**
     * @param class-string<EventInterface> $event
     */
    public function __construct(string $event = Event::class)
    {
        $this->event = $event;
    }

    public function on(string $event, callable $listener, int $priority = 0): self
    {
        $this->listeners[$event][$priority][] = $listener;
        unset($this->sorted[$event]);

        return $this;
    }

    public function off(string $event, ?callable $listener = null): self
    {
        if (!isset($this->listeners[$event])) {
            return $this;
        }

        if ($listener === null) {
            unset($this->listeners[$event], $this->sorted[$event]);

            return $this;
        }

        foreach ($this->listeners[$event] as $priority => $listeners) {
            if (false !== ($key = array_search($listener, $listeners, true))) {
                unset($this->listeners[$event][$priority][$key], $this->sorted[$event]);
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(EventSubscriberInterface $subscriber): self
    {
        foreach ($subscriber->subscribe() as $event => $params) {

            if (is_string($params)) {
                $this->on($event, [$subscriber, $params]);
            } elseif (is_callable($params)) {
                $this->on($event, $params->bindTo($subscriber, $subscriber));
            } elseif (is_string($params[0])) {
                $this->on($event, [$subscriber, $params[0]], isset($params[1]) ? $params[1] : 0);
            } elseif (is_callable($params[0])) {
                $this->on($event, $params[0]->bindTo($subscriber, $subscriber), isset($params[1]) ? $params[1] : 0);
            } else {
                foreach ($params as $listener) {
                    if (is_string($listener[0])) {
                        $this->on($event, [$subscriber, $listener[0]], isset($listener[1]) ? $listener[1] : 0);
                    } else {
                        $this->on($event, $listener[0]->bindTo($subscriber, $subscriber), isset($listener[1]) ? $listener[1] : 0);
                    }
                }
            }

        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(EventSubscriberInterface $subscriber): self
    {
        foreach ($subscriber->subscribe() as $event => $params) {
            if (is_array($params) && is_array($params[0])) {
                foreach ($params as $listener) {
                    $this->off($event, [$subscriber, $listener[0]]);
                }
            } else {
                $this->off($event, [$subscriber, is_string($params) ? $params : $params[0]]);
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int|string, mixed> $arguments
     */
    public function trigger($event, array $arguments = []): EventInterface
    {
        $e = is_string($event) ? new $this->event($event) : $event;

        $e->setDispatcher($this);

        array_unshift($arguments, $e);

        foreach ($this->getListeners($e->getName()) as $listener) {

            call_user_func_array($listener, $arguments);

            if ($e->isPropagationStopped()) {
                break;
            }
        }

        return $e;
    }

    public function hasListeners(?string $event = null): bool
    {
        return (bool) count($this->getListeners($event));
    }

    /**
     * @return list<callable>|array<string, list<callable>>
     */
    public function getListeners(?string $event = null): array
    {
        if ($event !== null) {
            return $this->sorted[$event] ?? $this->sortListeners($event);
        }

        foreach (array_keys($this->listeners) as $event) {
            if (!isset($this->sorted[$event])) {
                $this->sortListeners($event);
            }
        }

        return array_filter($this->sorted);
    }

    public function getListenerPriority(string $event, callable $listener): ?int
    {
        if (!isset($this->listeners[$event])) {
            return null;
        }

        foreach ($this->listeners[$event] as $priority => $listeners) {
            if (in_array($listener, $listeners, true)) {
                return $priority;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventClass(): string
    {
        return $this->event;
    }

    /**
     * Sorts all listeners of an event by their priority.
     *
     * @return list<callable>
     */
    protected function sortListeners(string $event): array
    {
        $sorted = [];

        if (isset($this->listeners[$event])) {
            krsort($this->listeners[$event]);
            $sorted = call_user_func_array('array_merge', $this->listeners[$event]);
        }

        return $this->sorted[$event] = $sorted;
    }
}
