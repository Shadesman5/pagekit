<?php

namespace Pagekit\Application\Traits;

/**
 * Provides static access to the container instance.
 * 
 * Note: To avoid conflicts with PSR-11 non-static methods in the Container class,
 * static methods are implemented as wrappers that delegate to the instance methods.
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
     * This handles all static calls including App::get(), App::has(), App::db(), etc.
     *
     * @param  string $name
     * @param  array  $args
     * @return mixed
     */
    public static function __callStatic($name, $args)
    {
        // Handle special container methods
        switch ($name) {
            case 'has':
                return static::$instance->hasService($args[0] ?? '');
            
            case 'get':
                return static::$instance->offsetGet($args[0] ?? '');
            
            case 'set':
                static::$instance->offsetSet($args[0] ?? '', $args[1] ?? null);
                return;
            
            case 'remove':
                static::$instance->offsetUnset($args[0] ?? '');
                return;
            
            default:
                // For all service calls (like db(), module(), etc.), 
                // get the service from container and optionally call it with args
                $value = static::$instance->offsetGet($name);
                return $args ? call_user_func_array($value, $args) : $value;
        }
    }
}
