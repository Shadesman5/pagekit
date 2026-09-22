<?php

declare(strict_types=1);

namespace Pagekit\Package;

use Pagekit\Application as App;
use Pagekit\Module\Module;

final class PackageModule extends Module
{
    /**
     * @return mixed Genuinely unknown type — overrides Module::main(); the return value is not consumed by the framework (inherited contract from ModuleInterface).
     */
    public function main(App $app): mixed
    {
        return null;
    }
}
