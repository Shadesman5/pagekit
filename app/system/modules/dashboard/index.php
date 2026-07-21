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
        // TODO: AUDIT FIX Step 4.4 — move API key to env variable / secrets management
        'weather.key' => '08c012f513db564bd6d4bae94b73cc94',

    ],

];
