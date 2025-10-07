<?php

use Pagekit\Log\Handler\DebugBarHandler;
use Pagekit\Log\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

return [

    'name' => 'log',

    'main' => function ($app) {

        $app['log'] = function ($app) {

            $logger = new Logger($this->name);

            // Add file handler for persistent logging
            if (isset($app['path.logs'])) {
                $logFile = $app['path.logs'] . '/debug.log';
                
                // Ensure log directory exists
                if (!is_dir(dirname($logFile))) {
                    mkdir(dirname($logFile), 0755, true);
                }
                
                // Add stream handler with DEBUG level
                $streamHandler = new StreamHandler($logFile, Level::Debug);
                $logger->pushHandler($streamHandler);
            }

            // Add debug bar handler if available
            if (isset($app['debugbar'])) {
                $logger->pushHandler($app['log.debug']);
            }

            return $logger;
        };

        $app['log.debug'] = fn() => new DebugBarHandler();

    },

    'autoload' => [

        'Pagekit\\Log\\' => 'src'

    ],

    'config' => [

        'name'  => 'log',
        'level' => 100

    ]

];
