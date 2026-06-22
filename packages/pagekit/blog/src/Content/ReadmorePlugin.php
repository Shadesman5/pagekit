<?php

declare(strict_types=1);

namespace Pagekit\Blog\Content;

use Pagekit\Content\Event\ContentEvent;
use Pagekit\Event\EventSubscriberInterface;

class ReadmorePlugin implements EventSubscriberInterface
{
    /**
     * Content plugins callback.
     *
     * @param ContentEvent $event
     */
    public function onContentPlugins(ContentEvent $event): void
    {
        $content = preg_split('/\[readmore\]/i', $event->getContent());

        if ($content === false) {
            throw new \LogicException('Failed to split content on [readmore] marker.');
        }

        if ($event['readmore'] && count($content) > 1) {
            $event['post']->readmore = true;
            $event->setContent($content[0]);
        } else {
            $event->setContent(implode('', $content));
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, array{string, int}>
     */
    public function subscribe(): array
    {
        return [
            'content.plugins' => ['onContentPlugins', 20],
        ];
    }
}
