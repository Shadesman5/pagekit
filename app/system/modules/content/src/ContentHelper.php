<?php

declare(strict_types=1);

namespace Pagekit\Content;

use Pagekit\Content\Event\ContentEvent;
use Pagekit\Event\EventDispatcherInterface;

class ContentHelper
{
    public function __construct(
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * Applies content plugins
     *
     * @param array<string, mixed> $parameters
     */
    public function applyPlugins(string $content, array $parameters = []): string
    {
        return $this->events->trigger(new ContentEvent('content.plugins', $content, $parameters))->getContent();
    }
}
