<?php

/**
 * Pagekit Migration Module
 *
 * Professional database migration system using Doctrine Migrations.
 * Provides versioned schema management, rollback functionality, and generation tools.
 */

use Pagekit\Migration\MigrationService;
use Pagekit\Migration\ConfigurationProvider;

return [

    'name' => 'migration',

    'main' => function ($app) {
        // Register migration service in DI container
        $app['migration'] = function ($app) {
            // Load migration configuration
            $configPath = __DIR__ . '/../../config/migrations.php';
            $config = file_exists($configPath) ? require $configPath : [];
            
            // Create and return migration service
            return new MigrationService($app['db'], $config);
        };

        // Register configuration provider
        $app['migration.config'] = function ($app) {
            $configPath = __DIR__ . '/../../config/migrations.php';
            $config = file_exists($configPath) ? require $configPath : [];
            
            return new ConfigurationProvider($app['db'], $config);
        };
    },

    'autoload' => [
        'Pagekit\\Migration\\' => 'src'
    ],

    'routes' => [
        // Migration routes (if web interface needed in future)
    ],

    'config' => [
        'migrations' => []
    ],

    'events' => [
        'boot' => function ($event, $app) {
            // Migration service is now available via $app['migration']
        }
    ]

];
