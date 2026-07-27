<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Event;

use Pagekit\Event\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class JsonResponseListener implements EventSubscriberInterface
{
    /**
     * Transforms the body of a JSON request to POST parameters.
     */
    public function onRequest(RequestEvent $event, Request $request): void
    {
        if ('json' === $request->getContentType() && $data = @json_decode($request->getContent(), true)) {
            $request->request->replace($data);
        }
    }

    /**
     * Converts a array to a JSON response.
     */
    public function onController(ControllerEvent $event): void
    {
        $result = $event->getControllerResult();

        if (is_array($result) || is_a($result, '\JsonSerializable')) {
            $event->setResponse(new JsonResponse($result));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function subscribe(): array
    {
        return [
            'request' => ['onRequest', 130],
            'controller' => ['onController', 20],
        ];
    }
}
