<?php

namespace Pagekit\Content\Plugin;

use Pagekit\Content\Event\ContentEvent;
use Pagekit\Event\EventSubscriberInterface;

class MarkdownPlugin implements EventSubscriberInterface
{
    public function __construct(
        private readonly mixed $markdown,
    ) {}

    /**
     * Content plugins callback.
     *
     * @param ContentEvent $event
     */
    public function onContentPlugins(ContentEvent $event): void
    {
        if (!$event['markdown']) {
            return;
        }

        $content = $event->getContent();
        $content = $this->markdown->parse($content, is_array($event['markdown']) ? $event['markdown'] : []);

        $event->setContent($content);
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'content.plugins' => ['onContentPlugins', 5]
        ];
    }
}
