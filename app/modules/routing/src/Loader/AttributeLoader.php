<?php

declare(strict_types=1);

namespace Pagekit\Routing\Loader;

use Pagekit\Routing\Attribute\Route as RouteAttribute;
use Pagekit\Routing\Route;

/**
 * Loads routes from PHP 8 Attributes on controller classes.
 *
 * Replaces AnnotationLoader as part of the Routing Attributes migration.
 */
class AttributeLoader implements LoaderInterface
{
    protected ?int $routeIndex = null;

    /**
     * {@inheritdoc}
     *
     * @return Route[]
     */
    public function load($class): array
    {
        if (class_exists($class)) {
            $class = new \ReflectionClass($class);
        } else {
            throw new \InvalidArgumentException(sprintf('Controller class "%s" does not exist.', $class));
        }

        if ($class->isAbstract()) {
            throw new \InvalidArgumentException(sprintf('Attributes from class "%s" cannot be read as it is abstract.', $class->getName()));
        }

        $routes = [];
        $globals = $this->getGlobals($class);

        foreach ($class->getMethods() as $method) {
            $this->routeIndex = 0;

            if ($method->isPublic() && 'Action' == substr($method->name, -6)) {
                $count = count($routes);

                // Get Route attributes from method
                $attributes = $method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);

                foreach ($attributes as $attribute) {
                    $routeAttr = $attribute->newInstance();
                    $this->addRoute($routes, $class, $method, $routeAttr, $globals);
                }

                // If no Route attribute found, create default route
                if ($count === count($routes)) {
                    $this->addRoute($routes, $class, $method, new RouteAttribute(), $globals);
                }
            }
        }

        return $routes;
    }

    /**
     * Adds a new route.
     *
     * @param Route[]                  $routes
     * @param \ReflectionClass<object> $class
     * @param array<string, mixed>     $globals
     */
    protected function addRoute(array &$routes, \ReflectionClass $class, \ReflectionMethod $method, RouteAttribute $attribute, array $globals): void
    {
        $name = $attribute->getName() ?? $this->getDefaultRouteName($method);
        $path = $attribute->getPath() ?? $this->getDefaultRoutePath($method);

        $routes[] = (new Route(rtrim($globals['path'] . $path, '/')))
            ->setName($globals['name'] . '/' . $name)
            ->setDefaults(array_replace($globals['defaults'], $attribute->getDefaults(), ['_controller' => $class->name . '::' . $method->name]))
            ->setRequirements(array_replace($globals['requirements'], $attribute->getRequirements()))
            ->setOptions(array_replace($globals['options'], $attribute->getOptions()))
            ->setHost($attribute->getHost() ?? $globals['host'])
            ->setSchemes(array_replace($globals['schemes'], $attribute->getSchemes()))
            ->setMethods(array_replace($globals['methods'], $attribute->getMethods()))
            ->setCondition($attribute->getCondition() ?? $globals['condition']);
    }

    /**
     * Gets global route configuration from class-level Route attribute.
     *
     * @param  \ReflectionClass<object> $class
     * @return array<string, mixed>
     */
    protected function getGlobals(\ReflectionClass $class): array
    {
        $globals = [
            'name' => '',
            'path' => '',
            'defaults' => [],
            'requirements' => [],
            'options' => [],
            'host' => '',
            'schemes' => [],
            'methods' => [],
            'condition' => '',
        ];

        $attributes = $class->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);

        if (!empty($attributes)) {
            $attribute = $attributes[0]->newInstance();

            if (null !== $value = $attribute->getName()) {
                $globals['name'] = $value;
            }
            if (null !== $value = $attribute->getPath()) {
                $globals['path'] = $value;
            }
            if (!empty($value = $attribute->getDefaults())) {
                $globals['defaults'] = $value;
            }
            if (!empty($value = $attribute->getRequirements())) {
                $globals['requirements'] = $value;
            }
            if (!empty($value = $attribute->getOptions())) {
                $globals['options'] = $value;
            }
            if (null !== $value = $attribute->getHost()) {
                $globals['host'] = $value;
            }
            if (!empty($value = $attribute->getSchemes())) {
                $globals['schemes'] = $value;
            }
            if (!empty($value = $attribute->getMethods())) {
                $globals['methods'] = $value;
            }
            if (null !== $value = $attribute->getCondition()) {
                $globals['condition'] = $value;
            }
        }

        return $globals;
    }

    /**
     * Gets the default route path for a class method.
     */
    protected function getDefaultRoutePath(\ReflectionMethod $method): string
    {
        $action = strtolower('/' . $this->parseControllerActionName($method));

        if ($action == '/index') {
            $action = '';
        }

        return $action;
    }

    /**
     * Gets the default route name for a class method.
     */
    protected function getDefaultRouteName(\ReflectionMethod $method): string
    {
        if ('index' === $action = strtolower($this->parseControllerActionName($method))) {
            $action = '';
        }

        $name = "/{$action}";

        if ($this->routeIndex > 0) {
            $name .= "_{$this->routeIndex}";
        }

        $this->routeIndex++;

        return trim($name, '/');
    }

    /**
     * Parses the controller action name.
     *
     * @throws \LogicException
     */
    protected function parseControllerActionName(\ReflectionMethod $method): string
    {
        if (!preg_match('/([a-zA-Z0-9]+)Action$/', $method->name, $matches)) {
            throw new \LogicException(sprintf('Unable to retrieve action name. The controller class method %s does not follow the naming convention. (e.g. indexAction)', $method->name));
        }

        return $matches[1];
    }
}
