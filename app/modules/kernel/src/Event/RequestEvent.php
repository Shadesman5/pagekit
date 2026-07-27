<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Event;

class RequestEvent extends KernelEvent
{
    use ResponseTrait;
}
