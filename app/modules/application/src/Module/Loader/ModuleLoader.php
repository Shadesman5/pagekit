<?php

declare(strict_types=1);

namespace Pagekit\Module\Loader;

use Pagekit\Application;
use Pagekit\Module\Module;

class ModuleLoader implements LoaderInterface
{
    public function __construct(protected Application $app)
    {
    }

    public function load(mixed $module): mixed
    {
        if (!is_array($module)) {
            return $module;
        }

        if (isset($module['main']) && is_callable($module['main']) && !is_string($module['main'])) {
            $moduleObj = new Module($module);

            $callable = $module['main']->bindTo($moduleObj, Module::class);
            $callable($this->app);

            if (is_a($moduleObj, 'Pagekit\Event\EventSubscriberInterface')) {
                $this->app->get('events')->subscribe($moduleObj);
            }

            return $moduleObj;
        }

        $class = $module[is_string($module['main']) ? 'main' : 'class'];

        $module = new $class($module);
        $module->main($this->app);

        if (is_a($module, 'Pagekit\Event\EventSubscriberInterface')) {
            $this->app->get('events')->subscribe($module);
        }

        return $module;
    }
}
