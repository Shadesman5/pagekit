<?php

namespace Pagekit\Kernel;

use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Event\SymfonyEventDispatcherBridge;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface as SymfonyHttpKernelInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent as SymfonyControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent as SymfonyExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent as SymfonyRequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent as SymfonyResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent as SymfonyTerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adapter to make Pagekit's HttpKernel compatible with Symfony 6.4
 * This allows Symfony bundles and components to work with Pagekit
 */
class SymfonyKernelAdapter implements SymfonyHttpKernelInterface
{
    protected HttpKernelInterface $kernel;
    protected SymfonyEventDispatcherBridge $eventDispatcher;

    public function __construct(HttpKernelInterface $kernel, EventDispatcherInterface $dispatcher)
    {
        $this->kernel = $kernel;
        $this->eventDispatcher = new SymfonyEventDispatcherBridge($dispatcher);
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        try {
            // Create Symfony-compatible request event
            $event = new SymfonyRequestEvent($this, $request, $type);
            $this->eventDispatcher->dispatch($event, KernelEvents::REQUEST);

            if ($event->hasResponse()) {
                return $this->filterResponse($event->getResponse(), $request, $type);
            }

            // Handle controller
            $controller = $this->getController($request);
            
            // Create controller event
            $event = new SymfonyControllerEvent($this, $controller, $request, $type);
            $this->eventDispatcher->dispatch($event, KernelEvents::CONTROLLER);
            $controller = $event->getController();

            // Execute controller
            $response = $controller($request);

            if (!$response instanceof Response) {
                $msg = 'The controller must return a response.';
                if ($response === null) {
                    $msg .= ' Did you forget to add a return statement somewhere in your controller?';
                }
                throw new \LogicException($msg);
            }

            return $this->filterResponse($response, $request, $type);

        } catch (\Exception $e) {
            if (!$catch) {
                throw $e;
            }

            return $this->handleThrowable($e, $request, $type);
        }
    }

    /**
     * Filters a response object
     */
    protected function filterResponse(Response $response, Request $request, int $type): Response
    {
        $event = new SymfonyResponseEvent($this, $request, $type, $response);
        $this->eventDispatcher->dispatch($event, KernelEvents::RESPONSE);

        return $event->getResponse();
    }

    /**
     * Handles exceptions
     */
    protected function handleThrowable(\Throwable $e, Request $request, int $type): Response
    {
        $event = new SymfonyExceptionEvent($this, $request, $type, $e);
        $this->eventDispatcher->dispatch($event, KernelEvents::EXCEPTION);

        $e = $event->getThrowable();

        if (!$event->hasResponse()) {
            throw $e;
        }

        $response = $event->getResponse();

        if (!$response->isClientError() && !$response->isServerError() && !$response->isRedirect()) {
            if ($e instanceof HttpException) {
                $response->setStatusCode($e->getCode());
            } else {
                $response->setStatusCode(500);
            }
        }

        return $this->filterResponse($response, $request, $type);
    }

    /**
     * Get controller for the request
     */
    protected function getController(Request $request)
    {
        // This would need to be implemented based on Pagekit's routing
        // For now, delegate to the original kernel
        return function(Request $request) {
            return $this->kernel->handle($request);
        };
    }

    /**
     * Terminate the request/response cycle
     */
    public function terminate(Request $request, Response $response): void
    {
        $event = new SymfonyTerminateEvent($this, $request, $response);
        $this->eventDispatcher->dispatch($event, KernelEvents::TERMINATE);
    }

    /**
     * Get the event dispatcher
     */
    public function getEventDispatcher(): SymfonyEventDispatcherBridge
    {
        return $this->eventDispatcher;
    }
}