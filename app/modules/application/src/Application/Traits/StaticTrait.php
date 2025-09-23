<?php

namespace Pagekit\Application\Traits;

/**
 * Provides static access to the container instance.
 * 
 * Note: Static methods has(), get(), set(), and remove() have been removed
 * to avoid conflicts with PSR-11 non-static methods in the Container class.
 * 
 * Use getInstance() to access the container and then call PSR-11 methods:
 * - Application::getInstance()->has($id)
 * - Application::getInstance()->get($id)
 * 
 * Or use the ArrayAccess interface for backward compatibility:
 * - Application::getInstance()[$id]
 */
trait StaticTrait
{
    protected static $instance;

    /**
     * Gets a container instance.
     *
     * @return Container
     */
    public static function getInstance()
    {
        return static::$instance;
    }

    /**
     * Magic method to access the container in a static context.
     *
     * @param  string $name
     * @param  array  $args
     * @return mixed
     */
    public static function __callStatic($name, $args)
    {
        $value = static::$instance->offsetGet($name);

        return $args ? call_user_func_array($value, $args) : $value;
    }
}
