<?php

return [

    'name' => 'menucards',

    'type' => 'extension',

    'main' => function ($app) {
        // Extension initialization
    },

    'autoload' => [
        'Pagekit\\Menucards\\' => 'src'
    ],

    'routes' => [

        '/menucards' => [
            'name' => '@menucards',
            'controller' => 'Pagekit\\Menucards\\Controller\\MenucardController'
        ],
        '/api/menucards' => [
            'name' => '@menucards/api',
            'controller' => [
                'Pagekit\\Menucards\\Controller\\MenuApiController',
                'Pagekit\\Menucards\\Controller\\ProductApiController'
            ]
        ],
        '/menucard' => [
            'name' => '@menucards/site',
            'controller' => 'Pagekit\\Menucards\\Controller\\SiteController'
        ]

    ],

    'permissions' => [

        'menucards: manage menucards' => [
            'title' => 'Manage menu cards'
        ],
        'menucards: manage products' => [
            'title' => 'Manage products'
        ]

    ],

    'menu' => [

        'menucards' => [
            'label' => 'Menucards',
            'icon' => 'menucards:icon.svg',
            'url' => '@menucards/menu',
            'active' => '@menucards/menu*',
            'access' => 'menucards: manage menucards || menucards: manage products',
            'priority' => 115
        ],
        'menucards: menus' => [
            'label' => 'Menus',
            'parent' => 'menucards',
            'url' => '@menucards/menu',
            'active' => '@menucards/menu*',
            'access' => 'menucards: manage menucards'
        ],
        'menucards: products' => [
            'label' => 'Products',
            'parent' => 'menucards',
            'url' => '@menucards/product',
            'active' => '@menucards/product*',
            'access' => 'menucards: manage products'
        ]

    ],

    'resources' => [
        'menucards:' => ''
    ],

    'events' => [

        'view.scripts' => function ($event, $scripts) {
            $scripts->register('link-menucards', 'menucards:app/bundle/link-menucards.js', '~panel-link');
        }

    ]

];
