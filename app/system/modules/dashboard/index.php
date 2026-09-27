<?php

declare(strict_types=1);

return [

    'main' => 'Pagekit\\Dashboard\\DashboardModule',

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

    ],

];
