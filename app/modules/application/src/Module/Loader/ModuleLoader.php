<?php

declare(strict_types=1);

namespace Pagekit\Module\Loader;

use Pagekit\Application;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleInterface;

class ModuleLoader implements LoaderInterface
{
    public function __construct(protected Application $app)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @param  mixed $module Genuinely unknown type — see LoaderInterface::load().
     * @return mixed Genuinely unknown type — see LoaderInterface::load().
     */
    public function load(mixed $module): mixed
    {
        if (!is_array($module)) {
            return $module;
        }

        if (isset($module['main']) && $module['main'] instanceof \Closure) {
            $moduleObj = new Module($module);

            $callable = $module['main']->bindTo($moduleObj, Module::class);
            if ($callable === null) {
                throw new \LogicException(sprintf('Unable to bind module "%s" main closure to its module instance.', $moduleObj->name));
            }
            $callable($this->app);

            $this->app->get('events')->subscribe($moduleObj);

            return $moduleObj;
        }

        $class = $module[is_string($module['main'] ?? null) ? 'main' : 'class'] ?? null;

        if (!is_string($class) || !is_subclass_of($class, ModuleInterface::class)) {
            throw new \LogicException(sprintf('Module class must be a class-string implementing %s, %s given.', ModuleInterface::class, get_debug_type($class)));
        }

        $instance = new $class($module);
        $instance->main($this->app);

        if ($instance instanceof EventSubscriberInterface) {
            $this->app->get('events')->subscribe($instance);
        }

        return $instance;
    }
}
