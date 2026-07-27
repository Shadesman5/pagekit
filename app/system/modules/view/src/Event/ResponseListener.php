<?php

declare(strict_types=1);

namespace Pagekit\View\Event;

use Pagekit\Application\UrlProvider;
use Pagekit\Event\EventInterface;
use Pagekit\Event\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ResponseListener implements EventSubscriberInterface
{
    public const REGEX_URL = '/
                        \s                              # match a space
                        (?<attr>href|src|poster)=       # match the attribute
                        ([\"\'])                        # start with a single or double quote
                        (?!\/|\#|[a-z0-9\-\.]+\:)       # make sure it is a relative path
                        (?<url>[^\"\'>]+)               # match the actual src value
                        \2                              # match the previous quote
                       /xiU';

    public function __construct(
        private readonly UrlProvider $url,
    ) {
    }

    /**
     * Filter the response content.
     */
    public function onResponse(EventInterface $event, Request $request, Response $response): void
    {
        if (!is_string($content = $response->getContent())) {
            return;
        }

        $response->setContent(preg_replace_callback(self::REGEX_URL, fn ($matches) => sprintf(' %s="%s"', $matches['attr'], ($this->url)($matches['url'])), $content));
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, array{string, int}>
     */
    public function subscribe(): array
    {
        return [
            'response' => ['onResponse', -20],
        ];
    }
}
