<?php

declare(strict_types=1);

namespace Pagekit\Debug\Event;

use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Event\EventInterface;
use Pagekit\Event\EventSubscriberInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @author    Fabien Potencier <fabien@symfony.com>
 * @copyright Copyright (c) 2004-2015 Fabien Potencier
 */
class TraceableEventDispatcher implements EventDispatcherInterface
{
    protected ?\Psr\Log\LoggerInterface $logger = null;
    protected \Symfony\Component\Stopwatch\Stopwatch $stopwatch;
    /** @var array<string, array<int, WrappedListener>> */
    protected array $called;
    protected \Pagekit\Event\EventDispatcherInterface $dispatcher;
    /** @var array<string, array<int, WrappedListener>> */
    protected array $wrappedListeners;

    /**
     * Tracks the listeners registered for each subscriber, keyed by the
     * subscriber object, so unsubscribe() can remove exactly what subscribe()
     * registered. Required because subscriberMethod() returns a fresh Closure
     * on each call and the underlying dispatcher's off() matches by identity.
     *
     * @var \SplObjectStorage<EventSubscriberInterface, list<array{event: string, listener: callable}>>
     */
    protected \SplObjectStorage $subscriberListeners;

    /**
     * Constructor.
     *
     * @param EventDispatcherInterface $dispatcher
     * @param Stopwatch                $stopwatch
     * @param LoggerInterface          $logger
     */
    public function __construct(EventDispatcherInterface $dispatcher, Stopwatch $stopwatch, ?LoggerInterface $logger = null)
    {
        $this->dispatcher = $dispatcher;
        $this->stopwatch = $stopwatch;
        $this->logger = $logger;
        $this->called = [];
        $this->wrappedListeners = [];
        $this->subscriberListeners = new \SplObjectStorage();
    }

    public function on(string $event, callable $listener, int $priority = 0): self
    {
        $this->dispatcher->on($event, $listener, $priority);

        return $this;
    }

    public function off(string $event, ?callable $listener = null): self
    {
        if (isset($this->wrappedListeners[$event])) {
            foreach ($this->wrappedListeners[$event] as $index => $wrappedListener) {
                if ($wrappedListener->getWrappedListener() === $listener) {
                    $listener = $wrappedListener;
                    unset($this->wrappedListeners[$event][$index]);

                    break;
                }
            }
        }

        $this->dispatcher->off($event, $listener);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(EventSubscriberInterface $subscriber): self
    {
        if (isset($this->subscriberListeners[$subscriber])) {
            $this->unsubscribe($subscriber);
        }

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
     * Builds a Closure that invokes a method on a subscriber by name. Using
     * first-class callable syntax lets PHPStan verify the resulting value is a
     * Closure (callable), unlike `[$subscriber, $method]` arrays which PHPStan
     * cannot statically prove to be callable when the method name comes from a
     * string variable.
     */
    protected function subscriberMethod(EventSubscriberInterface $subscriber, string $method): \Closure
    {
        return $subscriber->{$method}(...);
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int|string, mixed> $arguments
     */
    public function trigger(string|EventInterface $event, array $arguments = []): EventInterface
    {
        if (is_string($event)) {
            $class = $this->dispatcher->getEventClass();
            $e = new $class($event);
        } else {
            $e = $event;
        }

        $eventName = $e->getName();

        $this->preProcess($eventName);

        $watch = $this->stopwatch->start($eventName, 'section');

        $this->dispatcher->trigger($e, $arguments);

        if ($watch->isStarted()) {
            $watch->stop();
        }

        $this->postProcess($eventName);

        return $e;
    }

    public function hasListeners(?string $event = null): bool
    {
        return $this->dispatcher->hasListeners($event);
    }

    /**
     * @return ($event is null ? array<string, list<callable>> : list<callable>)
     */
    public function getListeners(?string $event = null): array
    {
        return $this->dispatcher->getListeners($event);
    }

    public function getListenerPriority(string $event, callable $listener): ?int
    {
        return $this->dispatcher->getListenerPriority($event, $listener);
    }

    /**
     * {@inheritdoc}
     *
     * @return class-string<EventInterface>
     */
    public function getEventClass(): string
    {
        return $this->dispatcher->getEventClass();
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, array<string, mixed>>
     */
    public function getCalledListeners(): array
    {
        $called = [];
        foreach ($this->called as $eventName => $listeners) {
            foreach ($listeners as $listener) {
                $info = $this->getListenerInfo($listener->getWrappedListener(), $eventName);
                $called[$eventName.'.'.$info['pretty']] = $info;
            }
        }

        return $called;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, array<string, mixed>>
     */
    public function getNotCalledListeners(): array
    {
        try {
            $allListeners = $this->getListeners();
        } catch (\Exception $e) {
            if (null !== $this->logger) {
                $this->logger->info('An exception was thrown while getting the uncalled listeners.', ['exception' => $e]);
            }

            return [];
        }

        $notCalled = [];
        foreach ($allListeners as $eventName => $listeners) {
            $eventName = (string) $eventName;
            foreach ($listeners as $listener) {
                $called = false;
                if (isset($this->called[$eventName])) {
                    foreach ($this->called[$eventName] as $l) {
                        if ($l->getWrappedListener() === $listener) {
                            $called = true;

                            break;
                        }
                    }
                }

                if (!$called) {
                    $info = $this->getListenerInfo($listener, $eventName);
                    $notCalled[$eventName.'.'.$info['pretty']] = $info;
                }
            }
        }

        uasort($notCalled, fn ($a, $b) => $this->sortListenersByPriority($a, $b));

        return $notCalled;
    }

    /**
     * Proxies all method calls to the original event dispatcher.
     *
     * @param  array<int|string, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->dispatcher->{$method}(...$arguments);
    }

    protected function preProcess(string $eventName): void
    {
        foreach ($this->dispatcher->getListeners($eventName) as $listener) {
            $priority = $this->getListenerPriority($eventName, $listener);
            $this->dispatcher->off($eventName, $listener);
            $info = $this->getListenerInfo($listener, $eventName);
            $name = isset($info['class']) ? $info['class'] : $info['type'];
            $wrappedListener = new WrappedListener($listener, $name, $priority, $this->stopwatch, $this);
            $this->wrappedListeners[$eventName][] = $wrappedListener;
            $this->dispatcher->on($eventName, $wrappedListener);
        }
    }

    protected function postProcess(string $eventName): void
    {
        unset($this->wrappedListeners[$eventName]);
        $skipped = false;
        foreach ($this->dispatcher->getListeners($eventName) as $listener) {
            if (!$listener instanceof WrappedListener) {
                continue;
            }
            // Unwrap listener
            $this->dispatcher->off($eventName, $listener);
            $this->dispatcher->on($eventName, $listener->getWrappedListener(), $listener->getPriority() ?? 0);

            $info = $this->getListenerInfo($listener->getWrappedListener(), $eventName);
            if ($listener->wasCalled()) {
                if (null !== $this->logger) {
                    $this->logger->debug(sprintf('Notified event "%s" to listener "%s".', $eventName, $info['pretty']));
                }

                $this->called[$eventName][] = $listener;
            }

            if (null !== $this->logger && $skipped) {
                $this->logger->debug(sprintf('Listener "%s" was not called for event "%s".', $info['pretty'], $eventName));
            }

            if ($listener->stoppedPropagation()) {
                if (null !== $this->logger) {
                    $this->logger->debug(sprintf('Listener "%s" stopped propagation of the event "%s".', $info['pretty'], $eventName));
                }

                $skipped = true;
            }
        }
    }

    /**
     * Returns information about the listener.
     *
     * @param  callable               $listener
     * @param  string                 $eventName
     * @return array<string, mixed>
     */
    protected function getListenerInfo($listener, $eventName): array
    {
        $info = [
            'event' => $eventName,
            'priority' => $this->getListenerPriority($eventName, $listener),
        ];
        if ($listener instanceof \Closure) {

            $refl = new \ReflectionFunction($listener);

            $info += [
                'type' => 'Closure',
                'file' => $refl->getFileName(),
                'line' => $refl->getStartLine(),
                'endline' => $refl->getEndLine(),
                'pretty' => (string) $refl,
            ];
        } elseif (is_string($listener)) {
            try {
                $r = new \ReflectionFunction($listener);
                $file = $r->getFileName();
                $line = $r->getStartLine();
            } catch (\ReflectionException $e) {
                $file = null;
                $line = null;
            }
            $info += [
                'type' => 'Function',
                'function' => $listener,
                'file' => $file,
                'line' => $line,
                'pretty' => $listener,
            ];
        } elseif (is_array($listener) || is_object($listener)) {
            if (!is_array($listener)) {
                $listener = [$listener, '__invoke'];
            }
            $class = is_object($listener[0]) ? get_class($listener[0]) : $listener[0];

            try {
                $r = new \ReflectionMethod($class, $listener[1]);
                $file = $r->getFileName();
                $line = $r->getStartLine();
            } catch (\ReflectionException $e) {
                $file = null;
                $line = null;
            }
            $info += [
                'type' => 'Method',
                'class' => $class,
                'method' => $listener[1],
                'file' => $file,
                'line' => $line,
                'pretty' => $class.'::'.$listener[1],
            ];
        }

        return $info;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    protected function sortListenersByPriority(array $a, array $b): int
    {
        if (is_int($a['priority']) && !is_int($b['priority'])) {
            return 1;
        }

        if (!is_int($a['priority']) && is_int($b['priority'])) {
            return -1;
        }

        if ($a['priority'] === $b['priority']) {
            return 0;
        }

        if ($a['priority'] > $b['priority']) {
            return -1;
        }

        return 1;
    }
}
