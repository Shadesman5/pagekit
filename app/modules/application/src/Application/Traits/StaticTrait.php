<?php

namespace Pagekit\Application\Traits;

/**
 * Provides static access to the container instance.
 *
 * App::get() and App::has() delegate to Container's PSR-11 get()/has() via __callStatic.
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
                return static::$instance->has($args[0] ?? '');
            
            case 'get':
                return static::$instance->get($args[0] ?? '');
            
            case 'set':
                static::$instance->offsetSet($args[0] ?? '', $args[1] ?? null);
                return;
            
            case 'remove':
                static::$instance->offsetUnset($args[0] ?? '');
                return;
            
            case 'config':
                // Special handling for config() during installation
                if (!static::$instance->has('config')) {
                    // Return a dummy config object during installation
                    return new class {
                        public function get($key) { return null; }
                        public function set($key, $value) { return $this; }
                        public function push($key, $value) { return $this; }
                        public function pull($key, $value) { return $this; }
                        public function remove($key) { return $this; }
                    };
                }
                // Fall through to default if config exists
                
            default:
                // For all service calls (like db(), module(), etc.), 
                // get the service from container and optionally call it with args
                try {
                    $value = static::$instance->get($name);
                } catch (\Exception $e) {
                    // Service not found - return null or throw depending on context
                    error_log("Service '$name' not found: " . $e->getMessage());
                    return null;
                }
                
                // Special handling for module() calls
                if ($name === 'module' && $args) {
                    return $value->get($args[0]);
                }
                
                // For callable services, call them with arguments
                if (is_callable($value) && $args) {
                    return call_user_func_array($value, $args);
                }
                
                return $value;
        }
    }
}
