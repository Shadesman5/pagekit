<?php

declare(strict_types=1);

namespace Pagekit\Module\Loader;

class CallableLoader implements LoaderInterface
{
    protected \Closure $callable;

    public function __construct(callable $callable)
    {
        $this->callable = $callable instanceof \Closure
            ? $callable
            : \Closure::fromCallable($callable);
    }

    public function load(mixed $module): mixed
    {
        return ($this->callable)($module);
    }
}
