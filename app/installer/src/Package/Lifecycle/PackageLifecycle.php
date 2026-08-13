<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Lifecycle;

use Psr\Container\ContainerInterface;

/**
 * A lifecycle that does nothing, for packages to override what they need.
 *
 * Most packages care about one or two moments of their life and have nothing to
 * say about the rest. Extending this keeps a package's lifecycle file down to
 * the hooks it actually uses, and keeps the interface free to gain a hook
 * without every package having to grow an empty method for it.
 */
abstract class PackageLifecycle implements PackageLifecycleInterface
{
    public function install(ContainerInterface $app): void
    {
    }

    public function enable(ContainerInterface $app): void
    {
    }

    public function disable(ContainerInterface $app): void
    {
    }

    public function uninstall(ContainerInterface $app): void
    {
    }

    /**
     * @return array<string, callable(ContainerInterface): void>
     */
    public function updates(): array
    {
        return [];
    }

    public function migrations(): ?MigrationSet
    {
        return null;
    }
}
