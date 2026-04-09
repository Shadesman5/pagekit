<?php

/**
 * Blog Extension Lifecycle Scripts
 *
 * Pagekit 2.0+ extension pattern:
 * - Schema changes: Doctrine Migrations in src/Migrations/
 * - install: runs all migrations (fresh install)
 * - enable: runs NEW migrations only (idempotent, safe on every activation)
 * - uninstall: explicit developer choice — extensions decide whether to clean up
 * - updates: DATA migrations only (schema changes belong in src/Migrations/)
 */

return [

    'install' => function ($app) {
        $result = $app->get('migration')->migrateExtension(
            'Pagekit\\Blog\\Migrations',
            __DIR__ . '/src/Migrations'
        );

        if (!$result['success']) {
            throw new \RuntimeException('Blog migration failed: ' . ($result['error'] ?? 'unknown error'));
        }
    },

    'enable' => function ($app) {
        $result = $app->get('migration')->migrateExtension(
            'Pagekit\\Blog\\Migrations',
            __DIR__ . '/src/Migrations'
        );

        if (!$result['success']) {
            throw new \RuntimeException('Blog migration failed: ' . ($result['error'] ?? 'unknown error'));
        }
    },

    'uninstall' => function ($app) {
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
    },

    /*
     * 'updates' — DATA migrations only.
     *
     * Schema changes (CREATE TABLE, ALTER TABLE, ADD INDEX, etc.) belong in
     * src/Migrations/ and are applied automatically by the enable hook.
     *
     * Use 'updates' for one-time data transformations tied to a version bump,
     * e.g. backfilling a new column, normalising legacy values.
     *
     * Example:
     * '2.1.0' => function ($app) {
     *     $app->get('db')->executeStatement(
     *         "UPDATE blog_post SET slug = LOWER(REPLACE(title, ' ', '-')) WHERE slug IS NULL"
     *     );
     * },
     */
    'updates' => [],

];
