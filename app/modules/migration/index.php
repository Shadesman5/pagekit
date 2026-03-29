<?php

/**
 * Pagekit Migration Module
 *
 * Professional database migration system using Doctrine Migrations.
 * Provides versioned schema management, rollback functionality, and generation tools.
 */

use Pagekit\Migration\ConfigurationProvider;
use Pagekit\Migration\MigrationService;

return [

    'name' => 'migration',

    'main' => function ($app) {
        $app->set('migration', function ($app) {
            $configPath = __DIR__ . '/../../config/migrations.php';
            $config = file_exists($configPath) ? require $configPath : [];

            return new MigrationService($app->get('db'), $config);
        });

        $app->set('migration.config', function ($app) {
            $configPath = __DIR__ . '/../../config/migrations.php';
            $config = file_exists($configPath) ? require $configPath : [];

            return new ConfigurationProvider($app->get('db'), $config);
        });
    },

    'autoload' => [
        'Pagekit\\Migration\\' => 'src',
    ],

    'routes' => [
        // Migration routes (if web interface needed in future)
    ],

    'config' => [
        'migrations' => [],
    ],

    'events' => [
        'boot' => function ($event, $app) {
            // Migration service is now available via $app->get('migration')
        },
    ],

];
