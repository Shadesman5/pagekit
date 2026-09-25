<?php

declare(strict_types=1);

namespace Pagekit\Event;

interface EventSubscriberInterface
{
    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * @return array<string, mixed>
     */
    public function subscribe(): array;
}
