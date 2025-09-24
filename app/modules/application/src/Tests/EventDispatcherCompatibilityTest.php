<?php

namespace Pagekit\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Event\Event;
use Pagekit\Event\EventDispatcher;
use Pagekit\Event\SymfonyEventDispatcherBridge;
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
     * Test that the bridge implements Symfony interface
     */
    public function testImplementsSymfonyInterface()
    {
        $this->assertInstanceOf(
            \Symfony\Component\EventDispatcher\EventDispatcherInterface::class,
            $this->bridge
        );
    }

    /**
     * Test adding and removing listeners through the bridge
     */
    public function testAddRemoveListener()
    {
        $called = false;
        $listener = function() use (&$called) {
            $called = true;
        };

        // Add listener through bridge
        $this->bridge->addListener('test.event', $listener);
        $this->assertTrue($this->bridge->hasListeners('test.event'));

        // Trigger through Pagekit dispatcher
        $this->dispatcher->trigger('test.event');
        $this->assertTrue($called);

        // Remove listener
        $called = false;
        $this->bridge->removeListener('test.event', $listener);
        $this->assertFalse($this->bridge->hasListeners('test.event'));

        // Verify it's not called
        $this->dispatcher->trigger('test.event');
        $this->assertFalse($called);
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

        $this->dispatcher->trigger('test.priority');

        $this->assertEquals(['high', 'medium', 'low'], $order);
    }

    /**
     * Test Symfony subscriber compatibility
     */
    public function testSymfonySubscriber()
    {
        $subscriber = new TestSymfonySubscriber();
        $this->bridge->addSubscriber($subscriber);

        $this->dispatcher->trigger('test.subscriber');
        $this->assertTrue($subscriber->called);

        // Test removal
        $subscriber->called = false;
        $this->bridge->removeSubscriber($subscriber);
        $this->dispatcher->trigger('test.subscriber');
        $this->assertFalse($subscriber->called);
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
     * Test that dispatch returns the event unchanged
     */
    public function testDispatchReturnsEvent()
    {
        $event = new SymfonyEvent();
        $result = $this->bridge->dispatch($event, 'test.event');
        $this->assertSame($event, $result);
    }

    /**
     * Test backward compatibility with Pagekit events
     */
    public function testPagekitEventSystemContinuesWorking()
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

    public function onTestEvent()
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