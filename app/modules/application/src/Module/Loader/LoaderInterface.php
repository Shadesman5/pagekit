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
     */
    public function load(mixed $module): mixed;
}
