<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Lifecycle;

use Psr\Container\ContainerInterface;

/**
 * What a package runs when it is installed, switched on or off, or removed.
 *
 * The hooks are declared, not looked up: a package's lifecycle file returns an
 * implementation of this interface, so every hook that exists is a method with a
 * signature. A hook whose name is wrong is then a broken class rather than a
 * hook that silently never runs, and what a package declares can be asked
 * instead of guessed.
 *
 * Every hook is handed the container rather than reaching for a global one,
 * because a package is installed and enabled from three different entry points
 * (web installer, admin panel, console) whose containers are not the same.
 */
interface PackageLifecycleInterface
{
    /**
     * Runs once, when the package is first installed.
     */
    public function install(ContainerInterface $app): void;

    /**
     * Runs every time the package is switched on, the first time included.
     */
    public function enable(ContainerInterface $app): void;

    /**
     * Runs every time the package is switched off.
     */
    public function disable(ContainerInterface $app): void;

    /**
     * Runs when the package is removed, while its folder still exists.
     */
    public function uninstall(ContainerInterface $app): void;

    /**
     * One-off data changes, keyed by the version that introduces them.
     *
     * An update is run when the installed version is older than its key, which
     * makes the keys a schedule rather than a list: what an installation has
     * already passed is not run again.
     *
     * @return array<string, callable(ContainerInterface): void>
     */
    public function updates(): array;

    /**
     * The package's schema migrations, or null where it declares none.
     */
    public function migrations(): ?MigrationSet;
}
