<?php

declare(strict_types=1);

use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;
use Psr\Container\ContainerInterface;

/**
 * The blog extension's lifecycle.
 *
 * Schema lives in Doctrine migrations under src/Migrations: install runs all of
 * them, enable runs the ones an installation has not seen yet, and both are
 * idempotent. Uninstall keeps the data - dropping the tables is a decision that
 * belongs to whoever is removing the extension, not to the extension.
 */
return new class extends PackageLifecycle {
    public function install(ContainerInterface $app): void
    {
        $this->migrate($app);
    }

    public function enable(ContainerInterface $app): void
    {
        $this->migrate($app);
    }

    public function uninstall(ContainerInterface $app): void
    {
        // Per Pagekit philosophy, uninstall does NOT auto-drop tables.
        // Extensions explicitly choose whether to remove their data.
        //
        // To roll back all blog migrations (DELETES ALL DATA):
        // $app->get('migration')->rollbackExtension(
        //     'Pagekit\\Blog\\Migrations',
        //     __DIR__ . '/src/Migrations',
        //     '0'
        // );

        if ($app->has('cache')) {
            $app->get('cache')->clear();
        }
    }

    private function migrate(ContainerInterface $app): void
    {
        $result = $app->get('migration')->migrateExtension(
            'Pagekit\\Blog\\Migrations',
            __DIR__ . '/src/Migrations'
        );

        if (!$result['success']) {
            throw new \RuntimeException('Blog migration failed: ' . ($result['error'] ?? 'unknown error'));
        }
    }
};
