<?php

declare(strict_types=1);

use Pagekit\Info\InfoHelper;

return [

    'name' => 'system/info',

    'main' => function ($app) {

        $app->set('info', fn () => new InfoHelper(
            $app->get('db'),
            $app->get('version'),
            $app->get('path.storage'),
            $app->get('path.temp'),
            $app->get('path.packages'),
            $app->get('config.file'),
            $app->get('path'),
        ));

    },

    'autoload' => [

        'Pagekit\\Info\\' => 'src',

    ],

    'routes' => [

        '/system/info' => [
            'name' => '@system/info',
            'controller' => 'Pagekit\\Info\\Controller\\InfoController',
        ],

    ],

    'menu' => [

        'system: info' => [
            'label' => 'Info',
            'parent' => 'system: system',
            'url' => '@system/info',
            'priority' => 30,
        ],

    ],

];
