<?php

declare(strict_types=1);

use Pagekit\Auth\Auth;
use Pagekit\Auth\Encoder\NativePasswordEncoder;
use Pagekit\Auth\Handler\DatabaseHandler;

return [

    'name' => 'auth',

    'main' => function ($app) {

        $app->set('auth', fn ($app) => new Auth($app->get('events'), $app->get('auth.handler')));

        $app->set('auth.password', fn () => new NativePasswordEncoder());

        // camelCase alias — PHP parameter names cannot contain dots, so controllers
        // inject `$authPassword` which resolves to this alias for `auth.password`.
        $app->set('authPassword', fn ($app) => $app->get('auth.password'));

        $app->set('auth.handler', fn ($app) => new DatabaseHandler($app->get('db'), $app->get('request.stack'), $app->get('cookie'), $this->config));

    },

    'autoload' => [

        'Pagekit\\Auth\\' => 'src',

    ],

    'config' => [

        'timeout' => 900,
        'table' => 'auth',
        'cookie' => [
            'name' => '',
            'lifetime' => 315360000,
        ],

    ],

];
