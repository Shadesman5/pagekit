<?php

namespace Pagekit\Event;

use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface as SymfonyEventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event as SymfonyEvent;

/**
 * Bridge class to provide Symfony EventDispatcher interface compatibility
 * This is a thin compatibility layer that allows Symfony components to work
 * with Pagekit's event system when they explicitly require a Symfony dispatcher
 */
class SymfonyEventDispatcherBridge implements SymfonyEventDispatcherInterface
{
    protected EventDispatcherInterface $dispatcher;
    
    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * {@inheritdoc}
     * Note: This is a minimal implementation for compatibility
     */
    public function dispatch(object $event, ?string $eventName = null): object
    {
        // Simply return the event without processing
        // Pagekit's event system continues to work independently
        return $event;
    }

    /**
     * {@inheritdoc}
     */
    public function addListener(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->dispatcher->on($eventName, $listener, $priority);
    }

    /**
     * {@inheritdoc}
     */
    public function addSubscriber(SymfonyEventSubscriberInterface $subscriber): void
    {
        // Convert Symfony subscriber to Pagekit format
        foreach ($subscriber::getSubscribedEvents() as $eventName => $params) {
            if (is_string($params)) {
                $this->addListener($eventName, [$subscriber, $params]);
            } elseif (is_string($params[0])) {
                $this->addListener($eventName, [$subscriber, $params[0]], $params[1] ?? 0);
            } else {
                foreach ($params as $listener) {
                    if (is_string($listener[0])) {
                        $this->addListener($eventName, [$subscriber, $listener[0]], $listener[1] ?? 0);
                    }
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeListener(string $eventName, callable $listener): void
    {
        $this->dispatcher->off($eventName, $listener);
    }

    /**
     * {@inheritdoc}
     */
    public function removeSubscriber(SymfonyEventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber::getSubscribedEvents() as $eventName => $params) {
            if (is_string($params)) {
                $this->removeListener($eventName, [$subscriber, $params]);
            } elseif (is_string($params[0])) {
                $this->removeListener($eventName, [$subscriber, $params[0]]);
            } else {
                foreach ($params as $listener) {
                    if (is_string($listener[0])) {
                        $this->removeListener($eventName, [$subscriber, $listener[0]]);
                    }
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getListeners(?string $eventName = null): array
    {
        return $this->dispatcher->getListeners($eventName);
    }

    /**
     * {@inheritdoc}
     */
    public function getListenerPriority(string $eventName, callable $listener): ?int
    {
        return $this->dispatcher->getListenerPriority($eventName, $listener);
    }

    /**
     * {@inheritdoc}
     */
    public function hasListeners(?string $eventName = null): bool
    {
        return $this->dispatcher->hasListeners($eventName);
    }
}