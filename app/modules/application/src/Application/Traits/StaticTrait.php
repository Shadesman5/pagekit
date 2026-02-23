<?php

namespace Pagekit\Application\Traits;

/**
 * Provides static access to the container instance.
 *
 * Since Container now implements PSR-11 ContainerInterface with get()/has() as
 * instance methods, App::get() and App::has() cannot be routed via __callStatic
 * (PHP does not trigger __callStatic when the method exists as non-static).
 * Use App::getInstance()->get() / App::getInstance()->has() instead.
 *
 * Service shortcuts like App::db(), App::module(), App::cache() still work
 * via __callStatic since those names don't collide with instance methods.
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
     * Handles App::db(), App::module(), App::cache(), App::set(), etc.
     *
     * Note: App::get() and App::has() are NOT handled here because Container
     * defines non-static get()/has() methods (PSR-11). Use App::getInstance()->get().
     *
     * @param  string $name
     * @param  array  $args
     * @return mixed
     */
    public static function __callStatic($name, $args)
    {
        switch ($name) {
            case 'set':
                static::$instance->offsetSet($args[0] ?? '', $args[1] ?? null);
                return;

            case 'remove':
                static::$instance->offsetUnset($args[0] ?? '');
                return;

            case 'config':
                if (!static::$instance->has('config')) {
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
                try {
                    $value = static::$instance->get($name);
                } catch (\Exception $e) {
                    error_log("Service '$name' not found: " . $e->getMessage());
                    return null;
                }

                if ($name === 'module' && $args) {
                    return $value->get($args[0]);
                }

                if (is_callable($value) && $args) {
                    return call_user_func_array($value, $args);
                }

                return $value;
        }
    }
}
