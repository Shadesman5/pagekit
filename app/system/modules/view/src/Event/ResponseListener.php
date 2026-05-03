<?php

declare(strict_types=1);

namespace Pagekit\View\Event;

use Pagekit\Event\EventSubscriberInterface;

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

    // TODO: Must be refactored in Step 2.1.6 (PHPStan Level 7→8 — Strict Typing) —
    // $url is used as callable ($this->url)($path) but typed as mixed.
    // Type-narrow to the correct interface or callable once DI is modernized.
    public function __construct(
        private readonly mixed $url,
    ) {
    }

    /**
     * Filter the response content.
     */
    public function onResponse($event, $request, $response): void
    {
        if (!is_string($content = $response->getContent())) {
            return;
        }

        $response->setContent(preg_replace_callback(self::REGEX_URL, fn ($matches) => sprintf(' %s="%s"', $matches['attr'], ($this->url)($matches['url'])), $content));
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'response' => ['onResponse', -20],
        ];
    }
}
