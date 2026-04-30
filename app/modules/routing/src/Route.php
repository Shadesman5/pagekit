<?php

declare(strict_types=1);

namespace Pagekit\Routing;

use Symfony\Component\Routing\Route as BaseRoute;

class Route extends BaseRoute
{
    protected string $name = '';

    /**
     * Returns the routes name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the routes name
     *
     * @param  string $name
     */
    public function setName(string $name): self
    {
        $this->name = trim((string) $name, '/');

        return $this;
    }

    /**
     * Gets the controller.
     *
     * @return mixed
     */
    public function getController()
    {
        $controller = $this->getDefault('_controller');

        if (is_string($controller)) {
            return explode('::', $controller, 2);
        }

        return $controller;
    }

    /**
     * Gets the controller reflection class.
     *
     * @return \ReflectionClass|null
     */
    public function getControllerClass(): ?\ReflectionClass
    {
        $controller = $this->getController();

        if (is_array($controller)) {
            return new \ReflectionClass($controller[0]);
        }

        return null;
    }

    /**
     * Gets the controller reflection method.
     *
     * @return \ReflectionMethod|null
     */
    public function getControllerMethod(): ?\ReflectionMethod
    {
        $controller = $this->getController();

        if (is_array($controller)) {
            return new \ReflectionMethod($controller[0], $controller[1]);
        }

        return null;
    }
}
