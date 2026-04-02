<?php

use Pagekit\Application\Response;
use Pagekit\Application\UrlProvider;
use Symfony\Component\ErrorHandler\ErrorHandler;

return [

    'name' => 'application',

    'main' => function ($app) {

        $app->set('version', fn () => $this->config['version']);

        $app->set('debug', fn () => (bool) $this->config['debug']);

        $app->set('url', fn ($app) => new UrlProvider($app->get('router'), $app->get('file'), $app->get('locator')));

        $app->set('response', fn ($app) => new Response($app->get('url')));

        $app->set('symfony.event_dispatcher', function ($app) {
            return new \Pagekit\Event\SymfonyEventDispatcherBridge($app->get('events'));
        });

        ErrorHandler::register()->throwAt(E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR);

        ini_set('display_errors', $app->inConsole() || $app->get('debug') ? 1 : 0);

    },

    'require' => [

        'debug',
        'routing',
        'auth',
        'config',
        'cookie',
        'database',
        'filesystem',
        'log',
        'session',
        'view',

    ],

    'config' => [

        'version' => '',
        'debug' => false,

    ],

];
