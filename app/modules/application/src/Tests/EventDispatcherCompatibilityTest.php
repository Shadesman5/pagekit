<?php

namespace Pagekit\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Event\Event;
use Pagekit\Event\EventDispatcher;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Event\SymfonyEventDispatcherBridge;
use Pagekit\Event\SymfonyEventAdapter;
use Pagekit\Event\SymfonySubscriberAdapter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface as SymfonyEventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event as SymfonyEvent;

class EventDispatcherCompatibilityTest extends TestCase
{
    protected EventDispatcher $dispatcher;
    protected SymfonyEventDispatcherBridge $bridge;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->bridge = new SymfonyEventDispatcherBridge($this->dispatcher);
    }

    /**
     * Test that Symfony events can be dispatched through the bridge
     */
    public function testSymfonyEventDispatch()
    {
        $event = new SymfonyEvent();
        $called = false;

        $this->bridge->addListener('test.event', function($e) use (&$called) {
            $called = true;
            $this->assertInstanceOf(SymfonyEventAdapter::class, $e);
        });

        $result = $this->bridge->dispatch($event, 'test.event');

        $this->assertTrue($called);
        $this->assertSame($event, $result);
    }

    /**
     * Test that event propagation stops correctly
     */
    public function testEventPropagationStop()
    {
        $event = new SymfonyEvent();
        $firstCalled = false;
        $secondCalled = false;

        $this->bridge->addListener('test.stop', function($e) use (&$firstCalled) {
            $firstCalled = true;
            if ($e instanceof SymfonyEventAdapter) {
                $e->stopPropagation();
            }
        }, 10);

        $this->bridge->addListener('test.stop', function($e) use (&$secondCalled) {
            $secondCalled = true;
        }, 5);

        $this->bridge->dispatch($event, 'test.stop');

        $this->assertTrue($firstCalled);
        $this->assertFalse($secondCalled);
        $this->assertTrue($event->isPropagationStopped());
    }

    /**
     * Test listener priority
     */
    public function testListenerPriority()
    {
        $order = [];

        $this->bridge->addListener('test.priority', function() use (&$order) {
            $order[] = 'low';
        }, -10);

        $this->bridge->addListener('test.priority', function() use (&$order) {
            $order[] = 'high';
        }, 10);

        $this->bridge->addListener('test.priority', function() use (&$order) {
            $order[] = 'medium';
        }, 0);

        $this->bridge->dispatch(new SymfonyEvent(), 'test.priority');

        $this->assertEquals(['high', 'medium', 'low'], $order);
    }

    /**
     * Test Symfony subscriber compatibility
     */
    public function testSymfonySubscriber()
    {
        $subscriber = new TestSymfonySubscriber();
        $this->bridge->addSubscriber($subscriber);

        $event = new SymfonyEvent();
        $this->bridge->dispatch($event, 'test.subscriber');

        $this->assertTrue($subscriber->called);
    }

    /**
     * Test removing listeners
     */
    public function testRemoveListener()
    {
        $called = false;
        $listener = function() use (&$called) {
            $called = true;
        };

        $this->bridge->addListener('test.remove', $listener);
        $this->assertTrue($this->bridge->hasListeners('test.remove'));

        $this->bridge->removeListener('test.remove', $listener);
        $this->assertFalse($this->bridge->hasListeners('test.remove'));

        $this->bridge->dispatch(new SymfonyEvent(), 'test.remove');
        $this->assertFalse($called);
    }

    /**
     * Test getting listeners
     */
    public function testGetListeners()
    {
        $listener1 = function() {};
        $listener2 = function() {};

        $this->bridge->addListener('test.get', $listener1);
        $this->bridge->addListener('test.get', $listener2);

        $listeners = $this->bridge->getListeners('test.get');
        $this->assertCount(2, $listeners);
    }

    /**
     * Test listener priority retrieval
     */
    public function testGetListenerPriority()
    {
        $listener = function() {};
        $this->bridge->addListener('test.priority', $listener, 42);

        $priority = $this->bridge->getListenerPriority('test.priority', $listener);
        $this->assertEquals(42, $priority);
    }

    /**
     * Test kernel event name mapping
     */
    public function testKernelEventMapping()
    {
        $called = false;

        // Listen to Pagekit's 'request' event
        $this->dispatcher->on('request', function() use (&$called) {
            $called = true;
        });

        // Dispatch Symfony's 'kernel.request' event
        $this->bridge->dispatch(new SymfonyEvent(), 'kernel.request');

        $this->assertTrue($called);
    }

    /**
     * Test backward compatibility with Pagekit events
     */
    public function testPagekitEventCompatibility()
    {
        $pagekitEvent = new Event('pagekit.test');
        $called = false;

        $this->dispatcher->on('pagekit.test', function($e) use (&$called) {
            $called = true;
            $this->assertInstanceOf(Event::class, $e);
        });

        $result = $this->dispatcher->trigger($pagekitEvent);

        $this->assertTrue($called);
        $this->assertSame($pagekitEvent, $result);
    }
}

/**
 * Test Symfony subscriber for testing
 */
class TestSymfonySubscriber implements SymfonyEventSubscriberInterface
{
    public bool $called = false;

    public function onTestEvent(SymfonyEvent $event)
    {
        $this->called = true;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'test.subscriber' => 'onTestEvent'
        ];
    }
}