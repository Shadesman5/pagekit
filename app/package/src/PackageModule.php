<?php

declare(strict_types=1);

namespace Pagekit\Package;

use Pagekit\Application as App;
use Pagekit\Module\Module;
use Pagekit\Package\Extension\ExtensionFailureStore;

final class PackageModule extends Module
{
    /**
     * @return mixed Genuinely unknown type — overrides Module::main(); the return value is not consumed by the framework (inherited contract from ModuleInterface).
     */
    public function main(App $app): mixed
    {
        // Where a failure outlives the request that hit it: the next boot and the
        // extension manager both read which packages are broken from here. The
        // service is defined only where the record has a directory to live in, so
        // that the one question a caller can ask the container - whether the id is
        // there - is the same question as whether it resolves.
        if ($app->has('path.system')) {
            $app->set('extension.failures', fn () => new ExtensionFailureStore($app->get('path.system'), $app->get('file')));
        }

        return null;
    }
}
