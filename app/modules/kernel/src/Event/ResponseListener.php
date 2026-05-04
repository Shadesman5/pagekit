<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Event;

use Pagekit\Event\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @copyright Copyright (c) 2004-2015 Fabien Potencier
 */
class ResponseListener implements EventSubscriberInterface
{
    protected string $charset;

    public function __construct(string $charset = 'UTF-8')
    {
        $this->charset = $charset;
    }

    /**
     * Filters the Response.
     */
    public function onResponse(KernelEvent $event, Request $request, Response $response): void
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        if ($response->getCharset() === null) {
            $response->setCharset($this->charset);
        }

        $response->prepare($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function subscribe(): array
    {
        return [
            'response' => ['onResponse', -10],
        ];
    }
}
