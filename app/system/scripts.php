<?php

/**
 * System Installation & Update Scripts
 *
 * Modern Pagekit (2.0+) Architecture:
 * - Database schema: Handled by Doctrine Migrations (app/migrations/)
 * - Config initialization: Handled here (configurable user preferences)
 * - System updates: Execute migrations via 'updates' array
 *
 * Clean Separation:
 * ✅ Migrations: Structure (tables, columns, system roles)
 * ✅ scripts.php: Configuration (user preferences, dashboard settings)
 */
return [

    'install' => function ($app) {
        // NOTE: Database tables are created by Doctrine Migrations.
        // This hook is executed AFTER migrations for configuration setup.

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
    },

    'updates' => [
        // System updates execute new migrations automatically
        // Example:
        // '2.1.0' => function ($app) {
        //     $result = $app->get('migration')->migrate();
        //     if (!$result['success']) {
        //         throw new \RuntimeException('Migration failed: ' . $result['error']);
        //     }
        // },
    ],

];
