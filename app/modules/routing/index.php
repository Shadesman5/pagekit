<?php

use Pagekit\Filter\FilterManager;
use Pagekit\Kernel\Event\ExceptionListenerWrapper;
use Pagekit\Kernel\Exception\HttpException;
use Pagekit\Routing\Event\AliasListener;
use Pagekit\Routing\Event\ConfigureRouteListener;
use Pagekit\Routing\Event\RouterListener;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Middleware;
use Pagekit\Routing\Request\ParamFetcher;
use Pagekit\Routing\Request\ParamFetcherListener;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use Symfony\Component\HttpFoundation\JsonResponse;

return [

    'name' => 'routing',

    'main' => function ($app) {

        $app->set('routes', fn() => new Routes());

        $app->set('router', fn($app) => new Router($app->get('routes'), new RoutesLoader($app->get('events')), $app->get('request.stack'), ['cache' => $app->get('path.cache')]));

        $app->set('middleware', fn($app) => new Middleware($app->get('events')));

        $app->get('module')->addLoader(function ($module) use ($app) {

            if (isset($module['routes'])) {
                foreach ($module['routes'] as $path => $route) {
                    $app->get('routes')->add(array_merge(['path' => $path], $route));
                }
            }

            return $module;
        });

    },

    'events' => [

        'boot' => function ($event, $app) {

            $app->get('events')->subscribe(new ConfigureRouteListener);
            $app->get('events')->subscribe(new ParamFetcherListener(new ParamFetcher(new FilterManager)));
            $app->get('events')->subscribe(new RouterListener($app->get('router')));
            $app->get('events')->subscribe(new AliasListener($app->get('routes')));

            $app->get('middleware');

            $app->get('events')->on('exception', new ExceptionListenerWrapper(function (HttpException $e) use ($app) {

                $request = $app->get('router')->getRequest();
                $types   = $request->getAcceptableContentTypes();

                if ('json' == $request->getFormat(array_shift($types))) {
                    return new JsonResponse($e->getMessage(), $e->getCode());
                }

            }), -10);

        },

        'request' => [function ($event, $request) use ($app) {

            if ($redirect = $request->attributes->get('_redirect')) {
                $event->setResponse($app->get('router')->redirect($redirect, [], 301));
            };

        }, 90],

        'controller' => [function ($event, $request) use ($app) {

            if (!$request->attributes->get('_controller') && $callback = $app->get('routes')->getCallback($request->attributes->get('_route', ''))) {
                $request->attributes->set('_controller', $callback);
            };

        }, 130]

    ],

    'require' => [

        'kernel',
        'filter'

    ],

    'autoload' => [

        'Pagekit\\Routing\\' => 'src'

    ]

];
