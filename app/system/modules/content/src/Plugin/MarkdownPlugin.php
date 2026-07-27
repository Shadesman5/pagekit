<?php

declare(strict_types=1);

namespace Pagekit\Content\Plugin;

use Pagekit\Content\Event\ContentEvent;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Markdown\Markdown;

class MarkdownPlugin implements EventSubscriberInterface
{
    public function __construct(
        private readonly Markdown $markdown,
    ) {
    }

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
     *
     * @return array<string, array{string, int}>
     */
    public function subscribe(): array
    {
        return [
            'content.plugins' => ['onContentPlugins', 5],
        ];
    }
}
