<?php

declare(strict_types=1);

namespace Pagekit\Module;

use Pagekit\Application as App;

interface ModuleInterface
{
    /**
     * Main bootstrap method.
     */
    public function main(App $app): mixed;

    /**
     * Gets a option value.
     *
     * @param string|array<int, string> $key
     */
    public function get(string|array $key, mixed $default = null): mixed;

    /**
     * Gets a config value.
     *
     * @param string|array<int, string>|null $key
     */
    public function config(string|array|null $key = null, mixed $default = null): mixed;
}
