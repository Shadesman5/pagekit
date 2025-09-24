<?php

namespace Pagekit\Event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface as SymfonyEventSubscriberInterface;

/**
 * Adapter to make Symfony event subscribers work with Pagekit's event system
 */
class SymfonySubscriberAdapter implements EventSubscriberInterface
{
    protected SymfonyEventSubscriberInterface $subscriber;

    public function __construct(SymfonyEventSubscriberInterface $subscriber)
    {
        $this->subscriber = $subscriber;
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        $events = [];
        
        // Convert Symfony subscriber format to Pagekit format
        foreach ($this->subscriber::getSubscribedEvents() as $eventName => $params) {
            if (is_string($params)) {
                // Simple method name
                $events[$eventName] = function($event) use ($params) {
                    return $this->callSubscriberMethod($params, $event);
                };
            } elseif (is_array($params)) {
                if (isset($params[0]) && is_string($params[0])) {
                    // Single listener with priority
                    $method = $params[0];
                    $priority = $params[1] ?? 0;
                    $events[$eventName] = [
                        function($event) use ($method) {
                            return $this->callSubscriberMethod($method, $event);
                        },
                        $priority
                    ];
                } else {
                    // Multiple listeners
                    $listeners = [];
                    foreach ($params as $listener) {
                        if (is_string($listener)) {
                            $listeners[] = function($event) use ($listener) {
                                return $this->callSubscriberMethod($listener, $event);
                            };
                        } elseif (is_array($listener)) {
                            $method = $listener[0];
                            $priority = $listener[1] ?? 0;
                            $listeners[] = [
                                function($event) use ($method) {
                                    return $this->callSubscriberMethod($method, $event);
                                },
                                $priority
                            ];
                        }
                    }
                    $events[$eventName] = $listeners;
                }
            }
        }
        
        return $events;
    }

    /**
     * Call a method on the subscriber with proper event conversion
     */
    protected function callSubscriberMethod(string $method, $event)
    {
        // If it's a Pagekit event wrapping a Symfony event, unwrap it
        if ($event instanceof SymfonyEventAdapter) {
            $symfonyEvent = $event->getSymfonyEvent();
            $result = $this->subscriber->$method($symfonyEvent);
            
            // Sync propagation status
            if ($symfonyEvent->isPropagationStopped()) {
                $event->stopPropagation();
            }
            
            return $result;
        }
        
        // Otherwise, call with the event as-is
        return $this->subscriber->$method($event);
    }

    /**
     * Get the wrapped subscriber
     */
    public function getSubscriber(): SymfonyEventSubscriberInterface
    {
        return $this->subscriber;
    }
}