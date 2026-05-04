<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

trait PropertyTrait
{
    /** @var array<string, array<string, mixed>> */
    protected static array $_properties = [];

    /**
     * Gets an object property.
     *
     * @param  string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        if ($descriptor = static::getPropertyDescriptor($name)) {

            $get = $descriptor['get'];

            if (is_string($get)) {
                $get = [$this, $get];
            } elseif ($get instanceof \Closure) {
                $get = $get->bindTo($this, $this);
            }

            return call_user_func($get);

        } else {

            trigger_error(sprintf('Undefined property: %s::$%s', __CLASS__, $name), E_USER_NOTICE);

            return null;
        }
    }

    /**
     * Sets an object property.
     *
     * @param string $name
     * @param mixed  $value
     */
    public function __set(string $name, mixed $value): void
    {
        if ($descriptor = static::getPropertyDescriptor($name)) {

            $set = $descriptor['set'];

            if (is_string($set)) {
                $set = [$this, $set];
            } elseif ($set instanceof \Closure) {
                $set = $set->bindTo($this, $this);
            }

            if (is_callable($set)) {
                call_user_func($set, $value);
            } elseif ($set === true) {
                $this->$name = $value;
            }

        } else {

            $this->$name = $value;
        }
    }

    /**
     * Clones the object properties.
     */
    public function __clone()
    {
        foreach (array_keys(static::$_properties) as $name) {
            $this->$name = $this->__get($name);
        }
    }

    /**
     * Checks for an object property.
     *
     * @param  string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset(static::$_properties[$name]);
    }

    /**
     * Gets all object properties.
     *
     * Consumers may declare a `protected static array $properties` map of
     * virtual property names to accessor methods; this trait does not declare
     * the field itself, so we look it up dynamically via `get_class_vars()`
     * to remain compatible with both consumer shapes (with and without the
     * map). PHPStan cannot statically type-narrow `static::$properties` in a
     * trait that does not own the property, hence this reflection lookup.
     *
     * @return array<string, mixed>
     */
    public static function getProperties(object $object): array
    {
        $properties = get_object_vars($object);

        foreach (array_keys(array_diff_key(static::$_properties, $properties)) as $name) {
            $properties[$name] = $object->$name;
        }

        $vars = get_class_vars(static::class);
        if (isset($vars['properties']) && is_array($vars['properties'])) {
            foreach (array_keys(array_diff_key($vars['properties'], $properties)) as $name) {
                $properties[$name] = $object->$name;
            }
        }

        return $properties;
    }

    /**
     * Defines an object property.
     *
     * @param  string|callable|array<string, mixed> $get
     * @param  string|callable|bool|null            $set
     * @return array<string, mixed>
     */
    public static function defineProperty(string $name, mixed $get, mixed $set = null): array
    {
        $descriptor = is_array($get) ? $get : compact('get', 'set');

        if (isset($descriptor[0])) {
            $descriptor['get'] = $descriptor[0];
        }

        if (isset($descriptor[1])) {
            $descriptor['set'] = $descriptor[1];
        }

        unset($descriptor[0], $descriptor[1]);

        return static::$_properties[$name] = $descriptor;
    }

    /**
     * Gets an object property descriptor.
     *
     * See {@see getProperties()} for the reasoning behind the `get_class_vars()`
     * lookup of the optional consumer-declared `$properties` map.
     *
     * @return array<string, mixed>|null
     */
    protected static function getPropertyDescriptor(string $name): ?array
    {
        if (isset(static::$_properties[$name])) {
            return static::$_properties[$name];
        }

        $vars = get_class_vars(static::class);
        if (isset($vars['properties'][$name]) && is_array($vars['properties'])) {
            return static::defineProperty($name, $vars['properties'][$name]);
        }

        return null;
    }
}
