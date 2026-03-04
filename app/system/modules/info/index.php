<?php

use Pagekit\Info\InfoHelper;

return [

    'name' => 'system/info',

    'main' => function ($app) {

        $app['info'] = fn() => new InfoHelper(); // TODO: Must be refactored in Step 2.0.1d (Packages + ArrayAccess Removal)

    },

    'autoload' => [

        'Pagekit\\Info\\' => 'src'

    ],

    'routes' => [

        '/system/info' => [
            'name' => '@system/info',
            'controller' => 'Pagekit\\Info\\Controller\\InfoController'
        ]

    ],

    'menu' => [

        'system: info' => [
            'label' => 'Info',
            'parent' => 'system: system',
            'url' => '@system/info',
            'priority' => 30
        ]

    ]

];
