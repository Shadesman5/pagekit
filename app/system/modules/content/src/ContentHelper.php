<?php

namespace Pagekit\Content;

use Pagekit\Content\Event\ContentEvent;
use Pagekit\Event\EventDispatcher;

class ContentHelper
{
    public function __construct(
        private readonly EventDispatcher $events,
    ) {}

    /**
     * Applies content plugins
     *
     * @param  string $content
     * @param  array  $parameters
     * @return mixed
     */
    public function applyPlugins($content, $parameters = [])
    {
        return $this->events->trigger(new ContentEvent('content.plugins', $content, $parameters))->getContent();
    }
}
