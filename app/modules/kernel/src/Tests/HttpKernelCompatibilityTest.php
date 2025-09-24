<?php

namespace Pagekit\Kernel\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Event\EventDispatcher;
use Pagekit\Kernel\HttpKernel;
use Pagekit\Kernel\SymfonyKernelAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent as SymfonyRequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent as SymfonyResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class HttpKernelCompatibilityTest extends TestCase
{
    protected EventDispatcher $dispatcher;
    protected HttpKernel $kernel;
    protected SymfonyKernelAdapter $adapter;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->kernel = new HttpKernel($this->dispatcher);
        $this->adapter = new SymfonyKernelAdapter($this->kernel, $this->dispatcher);
    }

    /**
     * Test that the adapter implements Symfony's HttpKernelInterface
     */
    public function testImplementsSymfonyInterface()
    {
        $this->assertInstanceOf(HttpKernelInterface::class, $this->adapter);
    }

    /**
     * Test request handling through the adapter
     */
    public function testHandleRequest()
    {
        $request = Request::create('/test');
        $expectedResponse = new Response('Test response');
        
        // Set up a listener to return a response
        $this->dispatcher->on('request', function($event) use ($expectedResponse) {
            $event->setResponse($expectedResponse);
        });

        $response = $this->adapter->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('Test response', $response->getContent());
    }

    /**
     * Test that Symfony kernel events are triggered
     */
    public function testSymfonyKernelEvents()
    {
        $events = [];
        $eventDispatcher = $this->adapter->getEventDispatcher();

        // Listen for Symfony kernel events
        $eventDispatcher->addListener(KernelEvents::REQUEST, function(SymfonyRequestEvent $event) use (&$events) {
            $events[] = 'request';
            $event->setResponse(new Response('Handled'));
        });

        $eventDispatcher->addListener(KernelEvents::RESPONSE, function(SymfonyResponseEvent $event) use (&$events) {
            $events[] = 'response';
        });

        $request = Request::create('/test');
        $response = $this->adapter->handle($request);

        $this->assertContains('request', $events);
        $this->assertContains('response', $events);
        $this->assertEquals('Handled', $response->getContent());
    }

    /**
     * Test exception handling
     */
    public function testExceptionHandling()
    {
        $exception = new \RuntimeException('Test exception');
        
        // Set up a listener that throws an exception
        $this->dispatcher->on('request', function() use ($exception) {
            throw $exception;
        });

        // Set up exception handler
        $this->dispatcher->on('exception', function($event) {
            $event->setResponse(new Response('Error handled', 500));
        });

        $request = Request::create('/test');
        $response = $this->adapter->handle($request);

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('Error handled', $response->getContent());
    }

    /**
     * Test terminate event
     */
    public function testTerminateEvent()
    {
        $terminated = false;
        $eventDispatcher = $this->adapter->getEventDispatcher();

        $eventDispatcher->addListener(KernelEvents::TERMINATE, function() use (&$terminated) {
            $terminated = true;
        });

        $request = Request::create('/test');
        $response = new Response();

        $this->adapter->terminate($request, $response);

        $this->assertTrue($terminated);
    }

    /**
     * Test sub-request handling
     */
    public function testSubRequestHandling()
    {
        $request = Request::create('/test');
        
        // Set up a listener to return a response
        $this->dispatcher->on('request', function($event) {
            $event->setResponse(new Response('Main request'));
        });

        $mainResponse = $this->adapter->handle($request, HttpKernelInterface::MAIN_REQUEST);
        $this->assertEquals('Main request', $mainResponse->getContent());

        $subResponse = $this->adapter->handle($request, HttpKernelInterface::SUB_REQUEST);
        $this->assertEquals('Main request', $subResponse->getContent());
    }

    /**
     * Test that the adapter maintains backward compatibility
     */
    public function testBackwardCompatibility()
    {
        $called = false;

        // Use Pagekit's event system directly
        $this->dispatcher->on('request', function($event) use (&$called) {
            $called = true;
            $event->setResponse(new Response('Pagekit event'));
        });

        $request = Request::create('/test');
        $response = $this->kernel->handle($request);

        $this->assertTrue($called);
        $this->assertEquals('Pagekit event', $response->getContent());
    }
}