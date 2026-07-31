<?php

declare(strict_types=1);

return [

    'name' => 'system/dashboard',

    'main' => 'Pagekit\\Dashboard\\DashboardModule',

    'autoload' => [

        'Pagekit\\Dashboard\\' => 'src',

    ],

    'routes' => [

        '/dashboard' => [
            'name' => '@dashboard',
            'controller' => 'Pagekit\\Dashboard\\Controller\\DashboardController',
        ],

    ],

    'resources' => [

        'system/dashboard:' => '',

    ],

    'menu' => [

        'dashboard' => [
            'label' => 'Dashboard',
            'icon' => 'system/dashboard:assets/images/icon-dashboard.svg',
            'url' => '@dashboard',
            'active' => '@dashboard*',
            'priority' => 100,
        ],

    ],

    'config' => [

        'defaults' => [],
        'weather.api' => 'http://api.openweathermap.org/data/2.5',
        // Every installation brings its own OpenWeatherMap key, through
        // PAGEKIT_WEATHER_API_KEY or config.php. Without one the location widget
        // reports that the weather is unavailable.
        'weather.key' => '',

    ],

];
