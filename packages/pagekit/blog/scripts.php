<?php

/**
 * Blog Extension Installation & Update Scripts
 *
 * Modern Pagekit (2.0+) Architecture:
 * - Database schema: Handled by Doctrine Migrations (src/Migrations/)
 * - Config initialization: Handled here (configurable preferences)
 * - Extension updates: Execute migrations via 'updates' array
 */

return [

    'install' => function ($app) {
        // Execute blog migrations to create database tables
        // Note: Extensions use migrateExtension() to run their own migrations
        $result = $app->get('migration')->migrateExtension(
            'Pagekit\\Blog\\Migrations',
            __DIR__ . '/src/Migrations'
        );

        if (!$result['success']) {
            throw new \RuntimeException(
                'Blog installation failed: ' . ($result['error'] ?? 'Unknown error')
            );
        }

        // Initialize blog configuration (if needed)
        // Example:
        // $app->config()->set('blog', [
        //     'posts_per_page' => 10,
        //     'allow_comments' => true,
        // ]);
    },

    'uninstall' => function ($app) {
        // Rollback blog migrations to remove database tables
        // Note: This deletes all blog data!
        $result = $app->get('migration')->rollbackExtension(
            'Pagekit\\Blog\\Migrations',
            __DIR__ . '/src/Migrations',
            '0'  // Rollback all blog migrations
        );

        if (!$result['success']) {
            throw new \RuntimeException(
                'Blog uninstallation failed: ' . ($result['error'] ?? 'Unknown error')
            );
        }

        // Clear cache
        if ($app->has('cache')) {
            $app->get('cache')->clear();
        }
    },

    'updates' => [
        // Extension updates execute new migrations automatically
        // Example:
        // '2.1.0' => function ($app) {
        //     $result = $app->get('migration')->migrateExtension(
        //         'Pagekit\\Blog\\Migrations',
        //         __DIR__ . '/src/Migrations'
        //     );
        //     if (!$result['success']) {
        //         throw new \RuntimeException('Blog update failed: ' . $result['error']);
        //     }
        // },
    ],

];
