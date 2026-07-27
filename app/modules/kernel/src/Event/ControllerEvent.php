<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Event;

class ControllerEvent extends KernelEvent
{
    use ResponseTrait;

    /**
     * @var callable|array{0: object|class-string, 1: string}|null
     */
    protected mixed $controller = null;

    protected mixed $controllerResult = null;

    /**
     * @return callable|array{0: object|class-string, 1: string}|null
     */
    public function getController(): callable|array|null
    {
        return $this->controller;
    }

    /**
     * @param callable|array{0: object|class-string, 1: string} $controller
     */
    public function setController(callable|array $controller): void
    {
        $this->controller = $controller;
    }

    /**
     * @return mixed Genuinely unknown type — the controller result may be a Response, an array for view rendering, a string, or any value returned by an action method.
     */
    public function getControllerResult(): mixed
    {
        return $this->controllerResult;
    }

    public function setControllerResult(mixed $controllerResult): void
    {
        $this->controllerResult = $controllerResult;
    }
}
