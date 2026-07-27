<?php

declare(strict_types=1);

use DebugBar\DataCollector\MemoryCollector;
use DebugBar\DataCollector\TimeDataCollector;
use Pagekit\Debug\DataCollector\AuthDataCollector;
use Pagekit\Debug\DataCollector\DatabaseDataCollector;
use Pagekit\Debug\DataCollector\EventDataCollector;
use Pagekit\Debug\DataCollector\ProfileDataCollector;
use Pagekit\Debug\DataCollector\RoutesDataCollector;
use Pagekit\Debug\DataCollector\SystemDataCollector;
use Pagekit\Debug\DebugBar;
use Pagekit\Debug\Event\TraceableEventDispatcher;
use Pagekit\Debug\Storage\SqliteStorage;
use Symfony\Component\Stopwatch\Stopwatch;

return [

    'name' => 'debug',

    'main' => function ($app) {

        if (!$this->config['enabled'] || !$this->config['file']) {
            return;
        }

        $app->set('debugbar', function ($app) {
            $debugbar = new DebugBar();

            return $debugbar->setStorage($app->get('debugbar.storage'));
        });

        $app->set('debugbar.storage', fn () => new SqliteStorage($this->config['file']));

        $app->set('debugbar.stopwatch', fn () => new Stopwatch());

        $app->extend('events', fn ($dispatcher, $app) => new TraceableEventDispatcher($dispatcher, $app->get('debugbar.stopwatch')));

    },

    'events' => [

        'boot' => function ($event, $app) {

            if (!$app->has('debugbar')) {
                return;
            }

            $app->get('debugbar')->addCollector(new MemoryCollector());
            $app->get('debugbar')->addCollector(new TimeDataCollector());
            $app->get('debugbar')->addCollector(new RoutesDataCollector($app->get('router'), $app->get('events'), $app->get('path.cache')));
            $app->get('debugbar')->addCollector(new EventDataCollector($app->get('events'), $app->get('path')));
            $app->get('debugbar')->addCollector(new ProfileDataCollector($app->get('debugbar.storage')));

            if ($app->has('auth')) {
                $app->get('debugbar')->addCollector(new AuthDataCollector($app->get('auth'), $app->get('userRepository')));
            }

            if ($app->has('info')) {
                $app->get('debugbar')->addCollector(new SystemDataCollector($app->get('info')));
            }

            if ($app->has('db')) {
                try {
                    if ($app->has('db.debug_logger')) {
                        $logger = $app->get('db.debug_logger');
                        if ($app->has('debugbar.stopwatch') && $logger->stopwatch === null) {
                            $logger->stopwatch = $app->get('debugbar.stopwatch');
                        }
                        $app->get('debugbar')->addCollector(new DatabaseDataCollector($app->get('db'), $logger));
                    } elseif ($app->has('db.debug_middleware')) {
                        $middleware = $app->get('db.debug_middleware');
                        $logger = $middleware->getLogger();
                        if ($app->has('debugbar.stopwatch') && $logger->stopwatch === null) {
                            $logger->stopwatch = $app->get('debugbar.stopwatch');
                        }
                        $app->get('debugbar')->addCollector(new DatabaseDataCollector($app->get('db'), $logger));
                    } else {
                        $app->get('debugbar')->addCollector(new DatabaseDataCollector($app->get('db'), null));
                    }
                } catch (\Exception $e) {
                    $app->get('debugbar')->addCollector(new DatabaseDataCollector($app->get('db'), null));
                }
            }

            if ($app->has('log.debug')) {
                $app->get('debugbar')->addCollector($app->get('log.debug'));
            }

            $app->get('events')->on('view.head', function ($event, $view) use ($app) {

                if ($app->get('request')->get('_disable_debugbar')) {
                    return;
                }

                $view->data('$debugbar', ['current' => $app->get('debugbar')->getCurrentRequestId()]);
                $view->style('debugbar', 'app/modules/debug/assets/css/debugbar.css');
                $view->script('debugbar', 'app/modules/debug/app/bundle/debugbar.js', ['vue']);
            }, 50);

            $app->get('events')->on('terminate', function ($event, $request) use ($app) {

                $route = $request->attributes->get('_route');

                if (!$event->isMasterRequest() || $route == '_debugbar') {
                    return;
                }

                $app->get('debugbar')->collect();

            }, -1000);

            $app->get('routes')->add([
                'name' => '_debugbar',
                'path' => '_debugbar/{id}',
                'defaults' => ['_debugbar' => false],
                'controller' => fn ($id) => $app->get('response')->json($app->get('debugbar')->getStorage()->get($id)),
            ]);

        },

    ],

    'require' => [

        'view',
        'routing',

    ],

    'autoload' => [

        'Pagekit\\Debug\\' => 'src',

    ],

    'config' => [

        'file' => null,
        'enabled' => false,

    ],

];
