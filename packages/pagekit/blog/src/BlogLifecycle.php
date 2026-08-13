<?php

declare(strict_types=1);

namespace Pagekit\Blog;

use Pagekit\Installer\Package\Lifecycle\MigrationSet;
use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;
use Psr\Container\ContainerInterface;

/**
 * The blog extension's lifecycle.
 *
 * The schema is Doctrine migrations under src/Migrations, and declaring where
 * they live is all the extension does about them: the installation runs them
 * when the extension is installed and again whenever it is switched on, and an
 * activation that fails halfway has them unwound for it.
 *
 * Removing the extension keeps the posts. Dropping the tables is a decision for
 * whoever removes it - a reinstall after a mistake gets the content back - so
 * all that goes is the cache, which still holds the routes and views of an
 * extension that is leaving.
 */
final class BlogLifecycle extends PackageLifecycle
{
    public function migrations(): MigrationSet
    {
        return new MigrationSet('Pagekit\\Blog\\Migrations', __DIR__ . '/Migrations');
    }

    public function uninstall(ContainerInterface $app): void
    {
        // A package is removed from the console and from the installer too, and
        // neither of those has a cache to forget anything.
        if ($app->has('cache')) {
            $app->get('cache')->clear();
        }
    }
}
