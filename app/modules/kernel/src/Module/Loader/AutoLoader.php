<?php

declare(strict_types=1);

namespace Pagekit\Module\Loader;

use Composer\Autoload\ClassLoader;

class AutoLoader implements LoaderInterface
{
    public function __construct(protected ClassLoader $loader)
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
        if (is_array($module) && isset($module['autoload'])) {
            foreach ($module['autoload'] as $namespace => $path) {
                $this->loader->addPsr4($namespace, $this->resolvePath($module, $path));
            }
        }

        return $module;
    }

    /**
     * Resolves a path to a absolute module path.
     *
     * @param array<string, mixed> $module
     */
    protected function resolvePath(array $module, string $path): string
    {
        $path = strtr($path, '\\', '/');

        if ($path[0] != '/' && !(strlen($path) > 3 && ctype_alpha($path[0]) && $path[1] == ':' && $path[2] == '/')) {
            $path = $module['path']."/$path";
        }

        return $path;
    }
}
