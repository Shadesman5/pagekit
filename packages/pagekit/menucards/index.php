<?php

use Pagekit\Menucards\Event\MenucardsListener;

return [

    'name' => 'menucards',

    'type' => 'extension',

    'main' => function ($app) {
        // Debug: Extension is being loaded
        error_log('[Menucards] Extension main function called');
    },

    'autoload' => [
        'Pagekit\\Menucards\\' => 'src'
    ],

    'routes' => [
        '/menucards' => [
            'name' => '@menucards/admin',
            'controller' => 'Pagekit\\Menucards\\Controller\\MenucardsController'
        ],
        '/menucard' => [
            'name' => '@menucards/site',
            'controller' => 'Pagekit\\Menucards\\Controller\\SiteController'
        ],
        '/api/menucards' => [
            'name' => '@menucards/api',
            'controller' => [
                'Pagekit\\Menucards\\Controller\\ProductApiController',
                'Pagekit\\Menucards\\Controller\\MenuApiController',
                'Pagekit\\Menucards\\Controller\\CategoryApiController'
            ]
        ]
    ],

    'permissions' => [
        'menucards: manage products' => [
            'title' => 'Manage Products',
            'description' => 'Create, edit, and delete products in the global product list'
        ],
        'menucards: manage menus' => [
            'title' => 'Manage Menus',
            'description' => 'Create, edit, and delete menu cards and assign products to categories'
        ]
    ],

    'menu' => [
        'menucards' => [
            'label' => 'Menucards',
            'icon' => 'menucards:icon.svg',
            'url' => '@menucards/admin',
            'access' => 'menucards: manage menus',
            'priority' => 15
        ],
        'menucards: products' => [
            'parent' => 'menucards',
            'label' => 'Products',
            'url' => '@menucards/admin/products',
            'access' => 'menucards: manage products',
            'priority' => 10
        ]
    ],

    'config' => [
        'currency' => '€',
        'decimal_separator' => ',',
        'thousands_separator' => '.'
    ],

    'events' => [
        'boot' => function ($event, $app) {
            // Debug: Boot event fired
            error_log('[Menucards] Boot event fired');
        },
        'view.scripts' => function ($event, $scripts) {
            // Debug: Scripts event fired
            error_log('[Menucards] Scripts event fired');
        }
    ]

];
