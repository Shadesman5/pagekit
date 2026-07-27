<?php

declare(strict_types=1);

namespace Pagekit\Kernel\Controller;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class ControllerResolver
{
    protected ?ContainerInterface $container = null;
    protected ?LoggerInterface $logger = null;

    public function __construct(?ContainerInterface $container = null, ?LoggerInterface $logger = null)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Resolves the controller callable for a request. Returns false when no
     * controller is found or the configured controller is not callable.
     *
     * @return callable|array{0: object, 1: string}|false
     */
    public function getController(Request $request): callable|array|false
    {
        $controller = $request->attributes->get('_controller');

        if ($controller === null || $controller === false || $controller === '') {
            if ($this->logger !== null) {
                $this->logger->warning('Unable to look for the controller as the "_controller" parameter is missing');
            }

            return false;
        }

        if (is_array($controller)) {
            if (
                count($controller) === 2
                && isset($controller[0], $controller[1])
                && is_object($controller[0])
                && is_string($controller[1])
                && is_callable($controller)
            ) {
                return [$controller[0], $controller[1]];
            }

            throw new \InvalidArgumentException(sprintf('Controller for URI "%s" is not callable.', $request->getPathInfo()));
        }

        if (is_object($controller)) {
            if (method_exists($controller, '__invoke')) {
                return $controller;
            }

            throw new \InvalidArgumentException(sprintf('Controller "%s" for URI "%s" is not callable.', get_class($controller), $request->getPathInfo()));
        }

        if (!is_string($controller)) {
            throw new \InvalidArgumentException(sprintf('Controller for URI "%s" must be a string, array, or callable object.', $request->getPathInfo()));
        }

        if (false === strpos($controller, ':')) {
            if (class_exists($controller) && method_exists($controller, '__invoke')) {
                $instance = $this->instantiateController($controller);
                if (!method_exists($instance, '__invoke')) {
                    throw new \InvalidArgumentException(sprintf('Controller "%s" for URI "%s" is not callable.', $controller, $request->getPathInfo()));
                }

                return $instance;
            }
            if (function_exists($controller)) {
                return $controller;
            }
        }

        $callable = $this->createController($controller);

        if (!is_callable($callable)) {
            throw new \InvalidArgumentException(sprintf('Controller "%s" for URI "%s" is not callable.', $controller, $request->getPathInfo()));
        }

        return $callable;
    }

    /**
     * @param callable|array{0: object|class-string, 1: string} $controller
     * @return list<mixed>
     */
    public function getArguments(Request $request, callable|array $controller): array
    {
        if (is_array($controller)) {
            if (
                !isset($controller[0], $controller[1])
                || (!is_object($controller[0]) && !is_string($controller[0]))
                || !is_string($controller[1])
            ) {
                throw new \InvalidArgumentException('Controller array must be [object|class-string, methodName].');
            }
            $r = new \ReflectionMethod($controller[0], $controller[1]);
            $callable = [$controller[0], $controller[1]];
        } elseif ($controller instanceof \Closure) {
            $r = new \ReflectionFunction($controller);
            $callable = $controller;
        } elseif (is_object($controller)) {
            $reflectionObject = new \ReflectionObject($controller);
            $r = $reflectionObject->getMethod('__invoke');
            $callable = $controller;
        } elseif (is_string($controller)) {
            $r = new \ReflectionFunction($controller);
            $callable = $controller;
        } else {
            throw new \InvalidArgumentException('Controller must be callable.');
        }

        return $this->doGetArguments($request, $callable, $r->getParameters());
    }

    /**
     * @param callable|array{0: object|string, 1: string} $controller
     * @param list<\ReflectionParameter>                  $parameters
     * @return list<mixed>
     */
    protected function doGetArguments(Request $request, callable|array $controller, array $parameters): array
    {
        $attributes = $request->attributes->all();
        $arguments = [];
        foreach ($parameters as $param) {
            if (array_key_exists($param->name, $attributes)) {
                $arguments[] = $attributes[$param->name];
            } elseif ($param->getType() instanceof \ReflectionNamedType && is_a($request, $param->getType()->getName())) {
                $arguments[] = $request;
            } elseif ($param->isDefaultValueAvailable()) {
                $arguments[] = $param->getDefaultValue();
            } else {
                if (is_array($controller)) {
                    $repr = sprintf('%s::%s()', is_object($controller[0]) ? get_class($controller[0]) : $controller[0], $controller[1]);
                } elseif (is_object($controller)) {
                    $repr = get_class($controller);
                } elseif (is_string($controller)) {
                    $repr = $controller;
                } else {
                    $repr = '[callable]';
                }

                throw new \RuntimeException(sprintf('Controller "%s" requires that you provide a value for the "$%s" argument (because there is no default value or because there is a non optional argument after this one).', $repr, $param->name));
            }
        }

        return $arguments;
    }

    /**
     * Returns a callable for the given controller.
     *
     * @return array{0: object, 1: string}
     * @throws \InvalidArgumentException
     */
    protected function createController(string $controller): array
    {
        if (false === strpos($controller, '::')) {
            throw new \InvalidArgumentException(sprintf('Unable to find controller "%s".', $controller));
        }

        list($class, $method) = explode('::', $controller, 2);

        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $class));
        }

        return [$this->instantiateController($class), $method];
    }

    /**
     * @param class-string $class
     */
    protected function instantiateController(string $class): object
    {
        if ($this->container === null) {
            return new $class();
        }

        $reflectionClass = new \ReflectionClass($class);
        $constructor = $reflectionClass->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $parameters = $constructor->getParameters();
        $args = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();

            if ($this->container->has($paramName)) {
                $args[] = $this->container->get($paramName);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new \RuntimeException(sprintf(
                    'Controller "%s" requires a value for constructor parameter "$%s" '
                    . '(no container service "%s" found and no default value available).',
                    $class,
                    $paramName,
                    $paramName
                ));
            }
        }

        return $reflectionClass->newInstanceArgs($args);
    }
}
