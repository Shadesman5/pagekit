<?php

namespace Pagekit\Event;

use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface as SymfonyEventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event as SymfonyEvent;

/**
 * Bridge class to make Pagekit's EventDispatcher compatible with Symfony 6.4
 * This allows Symfony components to work seamlessly with Pagekit's event system
 */
class SymfonyEventDispatcherBridge implements SymfonyEventDispatcherInterface
{
    protected EventDispatcherInterface $dispatcher;
    
    /**
     * Maps Symfony event names to Pagekit event names
     */
    protected array $eventMap = [
        'kernel.request' => 'request',
        'kernel.controller' => 'controller',
        'kernel.controller_arguments' => 'controller',
        'kernel.response' => 'response',
        'kernel.terminate' => 'terminate',
        'kernel.exception' => 'exception',
        'kernel.view' => 'view'
    ];

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(object $event, ?string $eventName = null): object
    {
        // Convert Symfony event to Pagekit event if needed
        $pagekitEventName = $this->mapEventName($eventName);
        
        if ($event instanceof SymfonyEvent) {
            // Create a wrapper that maintains the original event type
            $listeners = $this->dispatcher->getListeners($pagekitEventName);
            
            // Call listeners directly with the Symfony event
            foreach ($listeners as $listener) {
                call_user_func($listener, $event);
                
                if ($event->isPropagationStopped()) {
                    break;
                }
            }
        } else {
            // Assume it's already a Pagekit event
            $this->dispatcher->trigger($event);
        }
        
        return $event;
    }

    /**
     * {@inheritdoc}
     */
    public function addListener(string $eventName, callable $listener, int $priority = 0): void
    {
        $pagekitEventName = $this->mapEventName($eventName);
        $this->dispatcher->on($pagekitEventName, $listener, $priority);
    }

    /**
     * {@inheritdoc}
     */
    public function addSubscriber(SymfonyEventSubscriberInterface $subscriber): void
    {
        // Convert Symfony subscriber to Pagekit subscriber
        $adapter = new SymfonySubscriberAdapter($subscriber);
        $this->dispatcher->subscribe($adapter);
    }

    /**
     * {@inheritdoc}
     */
    public function removeListener(string $eventName, callable $listener): void
    {
        $pagekitEventName = $this->mapEventName($eventName);
        $this->dispatcher->off($pagekitEventName, $listener);
    }

    /**
     * {@inheritdoc}
     */
    public function removeSubscriber(SymfonyEventSubscriberInterface $subscriber): void
    {
        // This is more complex as we need to track the adapter
        // For now, we'll remove individual listeners
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
        if ($eventName !== null) {
            $pagekitEventName = $this->mapEventName($eventName);
            return $this->dispatcher->getListeners($pagekitEventName);
        }
        
        return $this->dispatcher->getListeners();
    }

    /**
     * {@inheritdoc}
     */
    public function getListenerPriority(string $eventName, callable $listener): ?int
    {
        $pagekitEventName = $this->mapEventName($eventName);
        return $this->dispatcher->getListenerPriority($pagekitEventName, $listener);
    }

    /**
     * {@inheritdoc}
     */
    public function hasListeners(?string $eventName = null): bool
    {
        if ($eventName !== null) {
            $pagekitEventName = $this->mapEventName($eventName);
            return $this->dispatcher->hasListeners($pagekitEventName);
        }
        
        return $this->dispatcher->hasListeners();
    }

    /**
     * Maps Symfony event names to Pagekit event names
     */
    protected function mapEventName(?string $eventName): string
    {
        if ($eventName === null) {
            return '';
        }
        
        return $this->eventMap[$eventName] ?? $eventName;
    }
    
    /**
     * Get the underlying Pagekit dispatcher
     */
    public function getPagekitDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcher;
    }
}