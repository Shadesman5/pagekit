<?php

namespace Pagekit\Module\Loader;

use Pagekit\Application;
use Pagekit\Module\Module;

class ModuleLoader implements LoaderInterface
{
    protected \Pagekit\Application $app;

    /**
     * Constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * {@inheritdoc}
     */
    public function load($module)
    {
        // Handle callable main (for modules with anonymous functions)
        if (isset($module['main']) && is_callable($module['main']) && !is_string($module['main'])) {
            $moduleObj = new Module($module);

            // Bind the callable to the module object so $this refers to the module
            $callable = $module['main']->bindTo($moduleObj, Module::class);
            $callable($this->app);

            if (is_a($moduleObj, 'Pagekit\Event\EventSubscriberInterface')) {
                $this->app->get('events')->subscribe($moduleObj);
            }

            return $moduleObj;
        }

        // Handle class-based main
        $class = $module[is_string($module['main']) ? 'main' : 'class'];

        $module = new $class($module);
        $module->main($this->app);

        if (is_a($module, 'Pagekit\Event\EventSubscriberInterface')) {
            $this->app->get('events')->subscribe($module);
        }

        return $module;
    }
}
