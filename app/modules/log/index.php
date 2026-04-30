<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Pagekit\Log\Handler\DebugBarHandler;
use Pagekit\Log\Logger;

return [

    'name' => 'log',

    'main' => function ($app) {

        $app->set('log', function ($app) {

            $logger = new Logger($this->name);

            if ($app->has('path.logs')) {
                $logFile = $app->get('path.logs') . '/debug.log';

                if (!is_dir(dirname($logFile))) {
                    mkdir(dirname($logFile), 0755, true);
                }

                $streamHandler = new StreamHandler($logFile, Level::Debug);
                $logger->pushHandler($streamHandler);
            }

            if ($app->has('debugbar')) {
                $logger->pushHandler($app->get('log.debug'));
            }

            return $logger;
        });

        $app->set('log.debug', fn () => new DebugBarHandler());

    },

    'autoload' => [

        'Pagekit\\Log\\' => 'src',

    ],

    'config' => [

        'name' => 'log',
        'level' => 100,

    ],

];
