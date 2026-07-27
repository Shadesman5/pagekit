<?php

declare(strict_types=1);

namespace Pagekit\Module;

use Pagekit\Application as App;

interface ModuleInterface
{
    /**
     * Main bootstrap method.
     *
     * @return mixed Genuinely unknown type — module bootstrap closures may return a service object, an array, or nothing; the return value is not consumed by the framework.
     */
    public function main(App $app): mixed;

    /**
     * Gets a option value.
     *
     * @param string|array<int, string> $key
     * @return mixed Genuinely unknown type — option values are user-supplied via module config arrays; any scalar, array, or object is valid.
     */
    public function get(string|array $key, mixed $default = null): mixed;

    /**
     * Gets a config value.
     *
     * @param string|array<int, string>|null $key
     * @return mixed Genuinely unknown type — config values are user-supplied via module config arrays; any scalar, array, or object is valid.
     */
    public function config(string|array|null $key = null, mixed $default = null): mixed;
}
