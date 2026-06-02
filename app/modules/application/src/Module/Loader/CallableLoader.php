<?php

declare(strict_types=1);

namespace Pagekit\Module\Loader;

class CallableLoader implements LoaderInterface
{
    /**
     * @var callable
     */
    protected $callable;

    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    public function load(mixed $module): mixed
    {
        return call_user_func($this->callable, $module);
    }
}
