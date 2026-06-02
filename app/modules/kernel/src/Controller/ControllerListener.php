<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Controller;

use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Kernel\Event\ControllerEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ControllerListener implements EventSubscriberInterface
{
    protected ControllerResolver $resolver;

    protected ?LoggerInterface $logger = null;

    public function __construct(ControllerResolver $resolver, ?LoggerInterface $logger = null)
    {
        $this->resolver = $resolver;
        $this->logger = $logger;
    }

    /**
     * Sets the controller.
     */
    public function resolveController(ControllerEvent $event, Request $request): void
    {
        if (!$controller = $this->resolver->getController($request)) {
            return;
        }

        $event->setController($controller);
    }

    /**
     * Executes the controller action and sets the response.
     */
    public function executeController(ControllerEvent $event, Request $request): void
    {
        if (!$controller = $event->getController()) {
            return;
        }

        $arguments = $this->resolver->getArguments($request, $controller);
        $response = call_user_func_array($controller, $arguments);

        if ($response instanceof Response) {
            $event->setResponse($response);
        } else {
            $event->setControllerResult($response);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function subscribe(): array
    {
        return [
            'controller' => [
                ['resolveController', 120],
                ['executeController', 100],
            ],
        ];
    }
}
