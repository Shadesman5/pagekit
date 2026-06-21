<?php

declare(strict_types=1);

namespace Pagekit\Event;

class EventDispatcher implements EventDispatcherInterface
{
    /** @var class-string<EventInterface> */
    protected string $event;

    /** @var array<string, array<int, array<int, callable>>> */
    protected array $listeners = [];

    /** @var array<string, list<callable>> */
    protected array $sorted = [];

    /**
     * Tracks the listeners registered for each subscriber, keyed by the
     * subscriber object, so unsubscribe() can remove exactly what subscribe()
     * registered (including closures wrapped to bypass PHPStan's strict
     * callable narrowing on `[$subscriber, $methodName]` arrays).
     *
     * @var \SplObjectStorage<EventSubscriberInterface, list<array{event: string, listener: callable}>>
     */
    protected \SplObjectStorage $subscriberListeners;

    /**
     * @param class-string<EventInterface> $event
     */
    public function __construct(string $event = Event::class)
    {
        $this->event = $event;
        $this->subscriberListeners = new \SplObjectStorage();
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
        $registrations = [];

        foreach ($subscriber->subscribe() as $event => $params) {

            if (is_string($params)) {
                $listener = $this->subscriberMethod($subscriber, $params);
                $this->on($event, $listener);
                $registrations[] = ['event' => $event, 'listener' => $listener];
            } elseif ($params instanceof \Closure) {
                $listener = $params->bindTo($subscriber, $subscriber);
                if ($listener === null) {
                    continue;
                }
                $this->on($event, $listener);
                $registrations[] = ['event' => $event, 'listener' => $listener];
            } elseif (is_array($params) && is_string($params[0])) {
                $listener = $this->subscriberMethod($subscriber, $params[0]);
                $this->on($event, $listener, isset($params[1]) && is_int($params[1]) ? $params[1] : 0);
                $registrations[] = ['event' => $event, 'listener' => $listener];
            } elseif (is_array($params) && $params[0] instanceof \Closure) {
                $listener = $params[0]->bindTo($subscriber, $subscriber);
                if ($listener === null) {
                    continue;
                }
                $this->on($event, $listener, isset($params[1]) && is_int($params[1]) ? $params[1] : 0);
                $registrations[] = ['event' => $event, 'listener' => $listener];
            } elseif (is_array($params)) {
                foreach ($params as $listenerSpec) {
                    if (!is_array($listenerSpec)) {
                        continue;
                    }
                    if (is_string($listenerSpec[0])) {
                        $listener = $this->subscriberMethod($subscriber, $listenerSpec[0]);
                        $this->on($event, $listener, isset($listenerSpec[1]) && is_int($listenerSpec[1]) ? $listenerSpec[1] : 0);
                        $registrations[] = ['event' => $event, 'listener' => $listener];
                    } elseif ($listenerSpec[0] instanceof \Closure) {
                        $listener = $listenerSpec[0]->bindTo($subscriber, $subscriber);
                        if ($listener === null) {
                            continue;
                        }
                        $this->on($event, $listener, isset($listenerSpec[1]) && is_int($listenerSpec[1]) ? $listenerSpec[1] : 0);
                        $registrations[] = ['event' => $event, 'listener' => $listener];
                    }
                }
            }

        }

        $this->subscriberListeners[$subscriber] = $registrations;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(EventSubscriberInterface $subscriber): self
    {
        if (!isset($this->subscriberListeners[$subscriber])) {
            return $this;
        }

        foreach ($this->subscriberListeners[$subscriber] as $registration) {
            $this->off($registration['event'], $registration['listener']);
        }

        unset($this->subscriberListeners[$subscriber]);

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int|string, mixed> $arguments
     */
    public function trigger(string|EventInterface $event, array $arguments = []): EventInterface
    {
        $e = is_string($event) ? new $this->event($event) : $event;

        $e->setDispatcher($this);

        array_unshift($arguments, $e);

        foreach ($this->sortedListenersFor($e->getName()) as $listener) {

            $listener(...$arguments);

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
     * @return ($event is null ? array<string, list<callable>> : list<callable>)
     */
    public function getListeners(?string $event = null): array
    {
        if ($event !== null) {
            return $this->sortedListenersFor($event);
        }

        foreach (array_keys($this->listeners) as $name) {
            if (!isset($this->sorted[$name])) {
                $this->sortListeners($name);
            }
        }

        return array_filter($this->sorted);
    }

    /**
     * Returns the sorted listener list for a single event. Split from
     * getListeners() so the return type is statically `list<callable>`
     * (PHPStan does not narrow the conditional return type at call sites
     * deeply enough for `foreach (... as $listener)` to infer `callable`).
     *
     * @return list<callable>
     */
    protected function sortedListenersFor(string $event): array
    {
        return $this->sorted[$event] ?? $this->sortListeners($event);
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
            $sorted = array_merge(...array_map('array_values', $this->listeners[$event]));
        }

        return $this->sorted[$event] = $sorted;
    }

    /**
     * Builds a Closure that invokes a method on a subscriber by name. Using
     * first-class callable syntax with a dynamic method name lets PHPStan
     * verify the resulting value is a Closure (callable), unlike
     * `[$subscriber, $method]` arrays which PHPStan cannot statically prove
     * to be callable when the method name comes from a string variable.
     */
    protected function subscriberMethod(EventSubscriberInterface $subscriber, string $method): \Closure
    {
        return $subscriber->{$method}(...);
    }
}
