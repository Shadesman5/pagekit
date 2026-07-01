<?php

declare(strict_types=1);

namespace Pagekit\Module\Loader;

interface LoaderInterface
{
    /**
     * Loads the module.
     *
     * Accepts an array module definition (during pre/post loading) or a
     * resolved module object (after ModuleLoader has instantiated it),
     * and returns whatever shape downstream loaders need.
     *
     * @param  mixed $module Genuinely unknown type — accepts an array definition, a ModuleInterface instance, or any intermediate form during the loader chain.
     * @return mixed Genuinely unknown type — the loader chain may return arrays, ModuleInterface instances, or null depending on the pipeline stage.
     */
    public function load(mixed $module): mixed;
}
