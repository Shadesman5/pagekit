<?php

use Pagekit\Auth\Auth;
use Pagekit\Auth\Encoder\NativePasswordEncoder;
use Pagekit\Auth\Handler\DatabaseHandler;
use RandomLib\Factory;

return [

    'name' => 'auth',

    'main' => function ($app) {

        $app->set('auth', fn($app) => new Auth($app->get('events'), $app->get('auth.handler')));

        $app->set('auth.password', fn() => new NativePasswordEncoder);

        $app->set('authPassword', fn($app) => $app->get('auth.password'));

        $app->set('auth.random', fn() => (new Factory)->getLowStrengthGenerator());

        $app->set('auth.handler', fn($app) => new DatabaseHandler($app->get('db'), $app->get('request.stack'), $app->get('cookie'), $app->get('auth.random'), $this->config));

    },

    'autoload' => [

        'Pagekit\\Auth\\' => 'src'

    ],

    'config' => [

        'timeout' => 900,
        'table' => 'auth',
        'cookie'   => [
            'name' => '',
            'lifetime' => 315360000
        ]

    ]

];
