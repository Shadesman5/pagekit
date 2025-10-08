<?php

use Pagekit\Application;

/**
 * Menucards Extension
 * Digital menu card management for restaurants
 */
return [
    'name' => 'menucards',

    'main' => function (Application $app) {
        // Extension initialization
        $app['log']->debug('Menucards: Extension loaded');
    },

    'autoload' => [
        'Pagekit\\Menucards\\' => 'src'
    ],

    'routes' => [
        '/menucards' => [
            'name' => '@menucards/admin',
            'controller' => 'Pagekit\\Menucards\\Controller\\MenucardController'
        ],
        '/menucards/products' => [
            'name' => '@menucards/products',
            'controller' => 'Pagekit\\Menucards\\Controller\\ProductController'
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

    'menu' => [
        'menucards' => [
            'label' => 'Menucards',
            'url' => '@menucards/admin',
            'access' => 'menucards: manage menucards',
            'icon' => 'menucards:icon.svg',
            'priority' => 10
        ],
        'menucards: products' => [
            'label' => 'Products',
            'parent' => 'menucards',
            'url' => '@menucards/admin/products',
            'access' => 'menucards: manage products',
            'priority' => 5
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

    'resources' => [
        'menucards:' => ''
    ],

    'events' => [
        'view.scripts' => function ($event, $scripts) use ($app) {
            // Register admin scripts
            $scripts->register('menu-index', 'menucards:app/bundle/menu-index.js', '~panel-link');
            $scripts->register('product-index', 'menucards:app/bundle/product-index.js', '~panel-link');
            $scripts->register('link-menucards', 'menucards:app/bundle/link-menucards.js', '~panel-link');
        }
    ]
];
