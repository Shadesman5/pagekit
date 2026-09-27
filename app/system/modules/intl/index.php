<?php

declare(strict_types=1);

return [

    'main' => 'Pagekit\\Intl\\IntlModule',

    'resources' => [

        'system/intl:' => '',

    ],

    'routes' => [
        '/system/intl' => [
            'name' => '@system/intl',
            'controller' => 'Pagekit\\Intl\\Controller\\IntlController',
        ],
        '/api/system/intl' => [
            'name' => '@system/api/intl',
            'controller' => 'Pagekit\\Intl\\Controller\\IntlApiController',
        ],
    ],

    'config' => [

        'locale' => 'en_US',

    ],

    'events' => [

        'view.init' => function ($event, $view) {
            $view->addGlobal('intl', $this);
        },

    ],

];
