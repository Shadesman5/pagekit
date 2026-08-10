<?php

declare(strict_types=1);

use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;
use Psr\Container\ContainerInterface;

/**
 * The system's own lifecycle.
 *
 * Structure - tables, columns, system roles - belongs to the Doctrine
 * migrations under app/migrations. What is left here is configuration: the
 * preferences a fresh installation starts with, and the one-off data changes an
 * update to a newer version needs.
 */
return new class extends PackageLifecycle {
    /**
     * Runs after the migrations have created the schema.
     */
    public function install(ContainerInterface $app): void
    {
        // Initialize default dashboard widgets configuration
        $app->get('config')->set('system/dashboard', [
            '55dda578e93b5' => ['type' => 'location', 'column' => 1, 'idx' => 0, 'units' => 'metric', 'id' => '55dda578e93b5', 'uid' => 2911298, 'city' => 'Hamburg', 'country' => 'DE', 'coords' => ['lon' => 10, 'lat' => 53.549999]],
            '55dda581d5781' => ['type' => 'feed', 'column' => 2, 'idx' => 0, 'count' => 5, 'content' => '1', 'id' => '55dda581d5781', 'title' => 'Pagekit News', 'url' => 'http://pagekit.com/blog/feed'],
            '55dda6e3dd661' => ['type' => 'user', 'column' => 0, 'idx' => 100, 'show' => 'registered', 'display' => 'thumbnail', 'total' => '1', 'count' => 12, 'id' => '55dda6e3dd661'],
        ]);

        // Initialize default site configuration (main menu)
        $app->get('config')->set('system/site', [
            'menus' => ['main' => ['id' => 'main', 'label' => 'Main']],
        ]);
    }

    /*
     * updates() - DATA changes only, keyed by the version that introduces them.
     * New schema belongs in a migration, which the migration service runs on
     * its own.
     *
     * Example:
     * public function updates(): array
     * {
     *     return [
     *         '2.1.0' => function (ContainerInterface $app): void {
     *             $app->get('db')->executeStatement('UPDATE ...');
     *         },
     *     ];
     * }
     */
};
